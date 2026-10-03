<?php

namespace App\Actions;

use App\Actions\Client\CreateClient;
use App\Actions\Concerns\BuildsMatterParties;
use App\Enums\ConflictLevel;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictOverride;
use App\Support\OpenMatterResult;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Mở vụ việc mới (SPEC §6.10 đầu bài "trước khi lưu vụ việc mới"; §11 "Xung đột lợi ích").
 *
 *  1. Kiểm tra quyền qua `MatterPolicy::create` trên `$actor` — Action tự kiểm tra, không tin
 *     caller (cùng quy ước với `TransitionMatterStage`/`AddMatterParty`). Actor là THAM SỐ bắt
 *     buộc, không bao giờ suy ra từ `Auth::` ambient (fix M3, review toàn nhánh, finding 1):
 *     chính actor đó vừa mở cổng `create`, vừa quyết định ai được ghi đè mức đỏ ở bước 4, vừa là
 *     causer của MỌI dòng nhật ký Action này ghi. Một phiên đăng nhập là thứ chỉ tồn tại ở đường
 *     HTTP; khi Action được gọi từ một lệnh console, một job chạy lại, hay một import, phiên đó
 *     hoặc rỗng hoặc thuộc về người khác — và cổng ghi đè mức đỏ là chỗ cuối cùng trong hệ thống
 *     được phép đọc một danh tính đoán mò. Không có dòng `Auth::` nào trong lớp này, có chủ đích.
 *  2. `$attributes['client_role']` BẮT BUỘC (fix round 1, finding 2) — KHÔNG có mặc định. Vai của
 *     khách hàng chính quyết định `$ourClientRoles` mà `RunConflictCheck::isOpposing()` dùng để
 *     tính mức đỏ; một mặc định âm thầm (từng là `plaintiff`) khiến MỌI vụ việc mà khách hàng
 *     chính thật ra là bị đơn bị tính sai vai, hạ mức đỏ xuống vàng một cách im lặng — đúng lỗ
 *     hổng người xem xét tìm thấy. Thiếu khoá này ném `ValidationException` ngay, không suy đoán.
 *  3. **Giai đoạn kiểm tra, trong transaction RIÊNG (luôn commit, không bao giờ bị rollback bởi
 *     nhánh chặn):** khoá MỌI dòng `clients` mà lần mở vụ việc này sẽ đọc — khách hàng chính
 *     (`$attributes['client_id']`) VÀ mọi bên trong `$parties` được đánh dấu `is_our_client` kèm
 *     `client_id` — trong lúc kiểm tra chạy (brief yêu cầu — xem "Về các khoá dòng" bên dưới),
 *     dựng "bên là khách hàng của chính vụ việc" (own-client party) từ hồ sơ `Client` đã khoá —
 *     dùng `identify($client->id_number, $client->phone)`, CHÍNH XÁC như
 *     `MatterPartyFactory::ourClient()` — rồi dựng các bên còn lại từ `$parties` (form) qua CÙNG
 *     một đường dựng (`BuildsMatterParties`, dùng chung với `AddMatterParty`), và chạy
 *     `RunConflictCheck` trên toàn bộ tập hợp (`$matter = null` vì vụ việc CHƯA lưu).
 *
 *     Đây là nghĩa vụ của Action, không phải của caller: một khách hàng không có dòng
 *     `matter_parties` là vô hình với `RunConflictCheck` (`clients` không có cột hash), nên nếu
 *     bỏ bước dựng own-client party, khách hàng CHÍNH của vụ việc mới sẽ không bao giờ bị đối
 *     chiếu — dù đó chính là tình huống SPEC §11 mô tả (bị đơn trùng căn cước với "một khách hàng
 *     hiện hữu").
 *
 *     Tách riêng transaction này khỏi transaction lưu vụ việc là CHỦ Ý: `RunConflictCheck` tự ghi
 *     activity log `conflict_check_run` ở MỌI lần chạy, kể cả khi kết quả dẫn tới chặn — nếu toàn
 *     bộ `handle()` nằm trong một `DB::transaction()` duy nhất và Action `throw` khi bị chặn,
 *     Laravel rollback CẢ dòng activity log đó, xoá mất bằng chứng đã kiểm tra đúng lúc nó quan
 *     trọng nhất (một lần chạy dẫn tới chặn). Two-phase đảm bảo dòng `conflict_check_run` luôn
 *     tồn tại, trong khi giai đoạn lưu (bước 5) chỉ chạy nếu không bị chặn/chưa được xác nhận —
 *     nên `Matter`/`MatterParty`/danh mục hồ sơ không bao giờ được tạo trong hai trường hợp đó.
 *
 *     **CẢNH BÁO CHO CALLER — không gọi `handle()` từ bên trong một transaction đang mở.**
 *     `DB::transaction()` lồng nhau chỉ tạo SAVEPOINT, không phải transaction độc lập: nếu một
 *     Filament create action (hay bất kỳ caller nào) tự bọc lời gọi này trong `DB::transaction()`
 *     của riêng nó, giai đoạn kiểm tra ở đây chỉ còn là một savepoint bên trong transaction đó —
 *     và khi `ConflictBlocked`/`ConflictAcknowledgementRequired` được ném ra rồi caller rollback
 *     transaction NGOÀI của họ, dòng `conflict_check_run` cũng bị cuốn theo, đúng thứ hai giai
 *     đoạn này được tách ra để tránh. Action KHÔNG tự kiểm tra điều kiện này lúc chạy (xem "Về
 *     việc không tự kiểm tra transaction lồng nhau" trong báo cáo Fix round 1) — đây là một ràng
 *     buộc phải tôn trọng ở nơi gọi.
 *  4. Mức đỏ (`isBlocking()`): chặn, TRỪ KHI actor có vai `manager`/`admin` VÀ `$overrideReason`
 *     không rỗng (sau `trim`). Ném `ConflictBlocked` (mang theo `ConflictCheckResult` để caller
 *     hiển thị lại danh sách bản ghi trùng) — giai đoạn kiểm tra ở bước 3 đã commit, giai đoạn lưu
 *     ở bước 5 chưa hề bắt đầu, nên không có gì bị tạo ra ngoài dòng activity log của chính lần
 *     kiểm tra. Bất kỳ kết quả nào khác mà `ConflictCheckResult::requiresAcknowledgement()` trả về
 *     `true` (fix round 1 finding 1, mở rộng ở fix round 2): không chặn vĩnh viễn, nhưng caller
 *     PHẢI xác nhận đã xem xét bằng cách truyền `acknowledged` ĐÚNG BẰNG `$result->level` — thiếu
 *     xác nhận ném `ConflictAcknowledgementRequired` (cùng khuôn với `ConflictBlocked`), và vì lỗi
 *     này được ném TRƯỚC giai đoạn lưu, vụ việc CHƯA tồn tại khi caller mới biết mức — không có
 *     chuyện "lưu rồi mới hỏi". `requiresAcknowledgement()` không chỉ đúng cho mức vàng: nó CŨNG
 *     đúng cho mức XANH khi `hasIncompleteParties()` — một bên chỉ có tên, không có số căn cước
 *     lẫn điện thoại, chỉ so khớp được theo tên ("cần người xem xét" — SPEC §6.10 bước 2), nên một
 *     kết quả xanh ở đó nghĩa là "không tìm thấy gì, nhưng gần như không nhìn được" chứ không phải
 *     "chắc chắn sạch". Fix round 1 gate lúc đầu chỉ so `$result->level === Yellow`, bỏ sót đúng
 *     trường hợp này — SPEC không nói rõ nhưng `ConflictCheckResult::requiresAcknowledgement()`
 *     (Task 7, fix round 2) được thiết kế CHÍNH XÁC để một caller chỉ nhìn `isBlocking()` /
 *     `requiresAcknowledgement()` không thể render một form xanh trơn mà giấu đi cảnh báo thiếu
 *     định danh; gate ở đây phải gọi đúng phương thức đó, không tự suy luận lại theo `level`. Chỉ
 *     mức đỏ không cần khớp `requiresAcknowledgement()` vì nó có luồng riêng
 *     (`$overrideReason`/`isBlocking()`) đứng trước trong `if/elseif`.
 *  5. **Giai đoạn lưu, trong transaction riêng, chỉ chạy nếu không bị chặn VÀ (không cần xác nhận
 *     HOẶC đã được xác nhận đúng mức):** tạo `Matter` (sinh mã qua `CodeSequence::next()` ở
 *     `Matter::creating()`),
 *     ghi các `MatterParty` đã dựng ở bước 3, sao chép danh mục hồ sơ từ template đang hoạt động
 *     mới nhất của loại vụ việc (nếu có), và ghi activity log `matter_opened` — causer truyền
 *     tường minh là `$actor` (không suy luận lại từ `auth()` ambient trong `Audit::record`, cùng
 *     actor đã được kiểm tra vai trò ở bước 4) — gồm kết quả kiểm tra xung đột, có ghi đè hay
 *     không, lý do ghi đè nếu có, danh sách bên thiếu định danh (`incompleteParties()`) — không
 *     thay thế, không trùng lặp dòng `conflict_check_run` đã ghi ở bước 3.
 *
 * **Trả về `OpenMatterResult` (vụ việc + `ConflictCheckResult` + có ghi đè hay không + lý do),
 * không chỉ `Matter` (fix round 3, Critical).** `handle()` trả về BÌNH THƯỜNG ở hai đường rất
 * khác nhau: mức xanh sạch, và mức ĐỎ đã được manager ghi đè ở bước 4. Một caller chỉ cầm
 * `Matter` không phân biệt được hai đường đó, nên màn hình tạo vụ việc suy ra "Action không ném
 * gì ⟹ xanh, không có bản ghi trùng" và báo MÀU XANH cho chính người vừa ghi đè một xung đột mức
 * đỏ — về một xung đột chưa từng được hiện ra cho họ xem. `$result->level` một mình cũng không đủ
 * để phân biệt: nó là ĐỎ ở cả trường hợp bị chặn (ném ngoại lệ) lẫn trường hợp được ghi đè, nên
 * cờ `overridden` và `overrideReason` phải đi kèm. Cùng khuôn `AddMatterPartyResult` của
 * `AddMatterParty`, vì hai Action là hai nhánh của cùng một quy tắc SPEC §6.10.
 *
 * **Về công bố portal ngay lúc mở vụ việc (review fix round 3, Minor 7).** Form tạo vụ việc có
 * công tắc "công bố cho khách", nên `$attributes['is_published_to_portal']` có thể là `true` ngay
 * ở câu `INSERT` đầu tiên. Trước bản sửa này, một vụ việc sinh ra đã công bố KHÔNG để lại dòng
 * `matter_portal_publication_set` nào — lịch sử công bố của nó bắt đầu bằng một khoảng trắng, và
 * M6 (thứ sẽ đọc chính chuỗi sự kiện đó để cảnh báo tái phơi bày backlog) không có gì để đối chiếu
 * ở đúng điểm khởi đầu. Bước 5 vì vậy ghi dòng đó ngay tại đây.
 *
 * **Vì sao ghi dòng nhật ký tại chỗ chứ không gọi `SetMatterPortalPublication` sau khi tạo.** Hai
 * lý do, cả hai đều là chặn đường chứ không phải sở thích. (1) Action kia gác bằng
 * `MatterPolicy::update`, mà `update` đòi `view`, mà `view` đòi người đó LIỆT KÊ được vụ việc:
 * một luật sư mở vụ việc và giao cho đồng nghiệp khác làm lead, hay mở một vụ `restricted`, thì
 * KHÔNG qua được cổng đó — vụ việc đã tạo xong rồi mới ăn 403, để lại một vụ việc có thật cùng
 * một màn hình báo lỗi. Cổng đúng cho thao tác này là `MatterPolicy::create`, đã kiểm tra ở bước
 * 1. (2) Rủi ro mà `SetMatterPortalPublication` sinh ra để canh — bật lại công tắc làm lộ nguyên
 * một backlog `stage_logs.is_published = true` tích luỹ trong lúc tắt — KHÔNG tồn tại ở lúc tạo:
 * vụ việc chưa có dòng tiến độ nào, nên `published_stage_log_count` chắc chắn bằng 0. Ghi thẳng
 * còn giữ được tính nguyên tử: không có khoảnh khắc nào vụ việc tồn tại với trạng thái công bố
 * khác với điều người dùng đã chọn. Cờ `at_creation` để người đọc nhật ký sau này phân biệt được
 * dòng này với một lần bật công tắc thật sự trên trang hồ sơ.
 *
 * **Về các khoá dòng (fix round 1, finding 6/9 — ghi nhận trung thực, không phóng đại):**
 * `lockForUpdate()` trên `clients` ở bước 3 chỉ có tác dụng trong đúng thời gian giai đoạn kiểm
 * tra chạy — khoá được GIẢI PHÓNG khi transaction đó commit, TRƯỚC KHI bất kỳ ghi nào phái sinh từ
 * dữ liệu khách hàng (own-client party) được lưu ở bước 5. Nó ngăn một sửa đổi
 * `name`/`id_number`/`phone` xen ngang đúng lúc kiểm tra đọc, nhưng KHÔNG khoá khách hàng xuyên
 * suốt toàn bộ lần mở vụ việc — đúng nghĩa đen "khoá dòng khách hàng trong lúc kiểm tra chạy" của
 * brief, không hơn.
 *
 * **Vì sao khoá NHIỀU dòng khách hàng trong MỘT câu lệnh, sắp theo khoá chính (fix round 3).** Từ
 * khi bên trong `$parties` cũng được dựng lại từ hồ sơ `Client` thật (finding I-6), một lần mở vụ
 * việc có thể phải khoá nhiều hơn một dòng `clients`. Khoá lần lượt theo thứ tự form gửi lên là
 * công thức kinh điển của DEADLOCK: hai request đồng thời, request A khoá khách hàng 5 rồi xin 3,
 * request B khoá 3 rồi xin 5 — InnoDB phát hiện vòng chờ và huỷ một trong hai (errno 1213), người
 * dùng nhận một lỗi 500 không giải thích được. `lockClients()` gom mọi id cần khoá, sắp TĂNG DẦN
 * theo khoá chính và lấy trong một câu lệnh `whereIn(...)->orderBy('id')->lockForUpdate()`, nên
 * mọi lời gọi `OpenMatter` đồng thời đều xin khoá theo cùng một thứ tự toàn cục và không tồn tại
 * vòng chờ nào để mà deadlock. `AddMatterParty` chỉ khoá đúng MỘT dòng `clients` mỗi lần chạy nên
 * nó không bao giờ vừa giữ một khoá vừa xin khoá thứ hai, tức không tham gia được vào một vòng chờ
 * với `OpenMatter`; thứ tự toàn cục vì vậy vẫn nguyên vẹn khi có cả hai Action chạy cùng lúc.
 * `lockForUpdate()` trên `matters` trước khi sao chép danh mục hồ sơ (carry-forward M1) khoá một
 * dòng vừa được chính `Matter::create()` chèn vài dòng lệnh trước đó, TRONG CÙNG transaction —
 * dòng này vô hình với mọi transaction khác cho tới khi commit, nên không có transaction đồng
 * thời nào có thể tranh chấp nó ở đây; khoá này thoả mãn ĐÚNG NGUYÊN VĂN yêu cầu carry-forward,
 * nhưng không ngăn một race thực sự nào trong luồng gọi hiện tại của `OpenMatter` — nó chỉ có ý
 * nghĩa nếu một lời gọi `ApplyChecklistTemplate` khác (ngoài `OpenMatter`) từng chạy đồng thời
 * trên CÙNG một `Matter` đã tồn tại từ trước, điều không xảy ra ở đây vì `Matter` luôn mới tạo.
 *
 * **Về khoá R13(g)/`conflict-11` (M6.5 Task 8) — vì sao khoá DÒNG không đủ.** `lockClients()` chỉ
 * khoá được những dòng `clients` mà lần mở vụ việc NÀY đọc tới. Khi hai người mở đồng thời hai vụ
 * việc ĐỐI NHAU cho hai khách hàng CHƯA từng có vụ nào (X kiện Y, và Y kiện X, cả hai đều mới), A
 * khoá dòng của X, B khoá dòng của Y — không ai chờ ai, vì không có dòng chung nào để tranh chấp.
 * Giai đoạn kiểm tra của A chạy TRƯỚC khi giai đoạn lưu của B commit (và ngược lại): `matcheFor()`
 * chỉ so với những gì ĐÃ COMMIT trong `matter_parties`, nên cả hai lần kiểm tra đều không thấy
 * dòng của đối phương — cả hai lưu XANH. Cửa sổ hẹp (khoảng cách giữa hai giai đoạn của lượt lưu
 * CUỐI hoàn tất), nhưng có thật. Vì vậy toàn bộ `handle()` (từ bước 3 tới hết bước 5) giờ chạy
 * dưới một khoá ỨNG DỤNG duy nhất, `Cache::store('database')->lock('conflict-check', ...)` — CÙNG
 * tên khoá với `AddMatterParty` (brief R13g: "nằm trong cùng một `Cache::lock('conflict-check')`
 * (cache store `database`)"), nên hai Action không thể cùng chạy giai đoạn kiểm tra+lưu một lúc,
 * dù là hai lần `OpenMatter` với nhau, hai lần `AddMatterParty` với nhau, hay một cặp khác nhau.
 * Khoá này KHÔNG thay thế `lockClients()`: nó là một mutex TOÀN CỤC (đủ cho quy mô văn phòng luật
 * nhỏ mà dự án nhắm tới — SPEC §2), còn `lockForUpdate()` vẫn cần cho tính đúng đắn ở tầng dòng dữ
 * liệu (ngăn một sửa đổi `Client` xen ngang, không ngăn hai lần MỞ VỤ VIỆC xen ngang nhau). Khoá
 * hết hạn sau 30 giây (đề phòng một tiến trình chết giữa chừng không giữ khoá vĩnh viễn) và chờ
 * tối đa 10 giây trước khi ném `LockTimeoutException`.
 *
 * **Fix round 1, minor ruling — `LockTimeoutException` giờ CÓ bắt, thành một lời từ chối tiếng
 * Việt.** Bản trước (đoạn này) từng nói "cố ý KHÔNG bắt lỗi đó ở đây" — sai kể từ bản sửa này: một
 * `LockTimeoutException` không bắt sẽ lọt ra thành lỗi 500 chung chung, không phải một thông báo
 * người dùng đọc được. `handle()` giờ bọc lời gọi `Cache::lock()->block()` trong try/catch, ném lại
 * thành `App\Exceptions\ConflictCheckBusy` (một `DomainException` với thông điệp tiếng Việt) khi
 * khoá không lấy được sau 10 giây. Vẫn không có hàng đợi thử lại tinh vi — quy mô đồng thời của
 * SPEC §2 không cần — chỉ đổi HÌNH THỨC lỗi từ "treo rồi 500" sang "từ chối ngay, có lý do".
 */
class OpenMatter
{
    use BuildsMatterParties;

    /**
     * @param  User  $actor  Người thực hiện thao tác này. Bắt buộc và tường minh — cùng quy ước
     *                       với `TransitionMatterStage::handle()` và `AddMatterParty::handle()`,
     *                       nơi actor đứng ngay sau chủ thể; `OpenMatter` chưa có chủ thể (vụ
     *                       việc chỉ ra đời ở bước 5) nên actor đứng đầu. Dùng cho CẢ BA việc:
     *                       cổng `MatterPolicy::create`, kiểm tra vai `manager`/`admin` ở cổng
     *                       ghi đè mức đỏ, và causer của mọi dòng nhật ký (`conflict_check_run`
     *                       lẫn `matter_opened`). Caller ở Filament truyền `Auth::user()` của
     *                       chính request đó; caller ở console/job truyền actor mà họ biết.
     * @param  array<string, mixed>  $attributes  Thuộc tính `Matter` (SPEC §4.5 `matters`), bắt
     *                                            buộc có `client_id` và `client_role`
     *                                            (`PartyRole|string` — vai của khách hàng chính
     *                                            trong vụ việc này, KHÔNG có mặc định, xem bước 2
     *                                            ở docblock lớp). `client_role` không phải cột
     *                                            của `matters`, bị loại trước khi
     *                                            `Matter::create()`.
     * @param  array<int, array<string, mixed>>  $parties  Các bên KHÁC ngoài khách hàng chính của
     *                                                     vụ việc (bị đơn, liên quan, ...). Mỗi
     *                                                     phần tử: `role` (`PartyRole|string`),
     *                                                     `name`, và tuỳ chọn `id_number`,
     *                                                     `phone`, `address`, `note`,
     *                                                     `is_our_client`, `client_id`.
     * @param  ConflictLevel|null  $acknowledged  Mức mà caller đã hiển thị cho người dùng và được
     *                                            tích xác nhận đã xem xét TRƯỚC lời gọi này (SPEC
     *                                            §11 bullet 3). Có ý nghĩa bất cứ khi nào
     *                                            `$result->requiresAcknowledgement()` là true —
     *                                            KHÔNG chỉ mức vàng (fix round 2): mức XANH có bên
     *                                            thiếu định danh (`hasIncompleteParties()`) cũng
     *                                            đòi xác nhận, vì "không tìm thấy gì" ở đó không
     *                                            đáng tin bằng một mức xanh thật. Mức đỏ có luồng
     *                                            riêng (`$overrideReason`), không dùng tham số
     *                                            này. Dùng enum thay vì `bool $acknowledged` đơn
     *                                            thuần để caller không thể "xác nhận trước" một
     *                                            mức chưa biết: giá trị phải khớp CHÍNH XÁC
     *                                            `$result->level` mà lần kiểm tra NÀY trả về, nên
     *                                            một xác nhận lưu từ một request kiểm tra trước đó
     *                                            (mức có thể đã đổi vì dữ liệu đổi) không tự động
     *                                            hợp lệ nếu mức mới khác.
     * @param  Client|null  $newClient  Final review A-M7: một khách hàng MỚI CHƯA LƯU (từ
     *                                  `CreateClient::resolve()`) thay cho `$attributes['client_id']`.
     *                                  Bước 3 kiểm tra xung đột trên một bên khách hàng dựng từ
     *                                  CHÍNH dữ liệu đó (chưa có `client_id` — một hồ sơ mới không có
     *                                  dòng cũ nào để tự khớp); hồ sơ chỉ được LƯU ở bước 5, sau khi
     *                                  bước 4 cho qua. Bị chặn đỏ/vàng thì không có gì được ghi — không
     *                                  còn khách hàng mồ côi của một lần mở vụ không thành.
     * @param  int|null  $excludeIntakeId  (M10 Task 4) Một lần tiếp nhận cần loại khỏi nguồn dò thứ
     *                                     hai của `RunConflictCheck` (tham số cùng tên ở đó).
     *                                     `ConvertIntakeToMatter` truyền id bản ghi ĐANG được chuyển
     *                                     đổi: lúc kiểm tra chạy, bản ghi đó còn "chưa chuyển đổi",
     *                                     nên không loại nó ra thì người liên hệ và bên đối lập của nó
     *                                     khớp chính các bên của vụ mới — một Vàng "đã liên hệ văn
     *                                     phòng" giả ở mọi lần chuyển đổi.
     * @param  Closure(Matter): void|null  $beforeCommit  (M10 Task 4) Chạy TRONG transaction của
     *                                                    bước 5, sau khi vụ việc, các bên, danh mục hồ sơ và dòng
     *                                                    `matter_opened` đã ghi, trước khi commit — vẫn dưới khoá
     *                                                    `conflict-check`. Nó ném thì CẢ bước lưu rollback: không
     *                                                    vụ việc, không khách hàng mới, không dòng nhật ký nào của
     *                                                    bước 5 (dòng `conflict_check_run` của bước 3 ở lại, như
     *                                                    mọi lần bị chặn). `ConvertIntakeToMatter` dùng nó để khoá
     *                                                    và kiểm tra lại bản ghi tiếp nhận rồi liên kết hai chiều
     *                                                    ở CÙNG transaction với vụ việc — thứ tự khoá: dòng
     *                                                    `matters` (đã khoá ở trên) trước, `intake_requests` sau.
     */
    public function handle(
        User $actor,
        array $attributes,
        array $parties,
        ?string $overrideReason = null,
        ?ConflictLevel $acknowledged = null,
        ?Client $newClient = null,
        ?int $excludeIntakeId = null,
        ?Closure $beforeCommit = null,
    ): OpenMatterResult {
        if ($newClient !== null && $newClient->exists) {
            $attributes['client_id'] = $newClient->getKey();
            $newClient = null;
        }
        // Bước 1.
        Gate::forUser($actor)->authorize('create', Matter::class);

        // Bước 2.
        if (! array_key_exists('client_role', $attributes) || $attributes['client_role'] === null) {
            throw ValidationException::withMessages([
                'client_role' => [__('actions.open_matter.client_role_required')],
            ]);
        }

        $clientRole = $attributes['client_role'];
        $clientRole = $clientRole instanceof PartyRole ? $clientRole : PartyRole::from($clientRole);
        unset($attributes['client_role']);

        // Fix round 1, minor ruling: "OpenMatter Action tự nó phải từ chối client_role =
        // opposing_counsel, không chỉ form." `MatterForm::clientRolePartyOptions()` đã loại giá
        // trị này khỏi ô chọn (R13f), nhưng đó là ranh giới HIỂN THỊ của panel — cổng THẬT của
        // Action không được tin caller đã đi qua đúng form đó (console, job, import, hay một
        // request bị chỉnh sửa gửi thẳng giá trị này). Khách hàng của văn phòng không bao giờ
        // chính là "luật sư đối phương" của vụ việc mình đang là khách hàng — chọn vai đó âm thầm
        // tắt hẳn mức đỏ (xem docblock `MatterForm::clientRolePartyOptions()` cho lý do đầy đủ).
        if ($clientRole === PartyRole::OpposingCounsel) {
            throw ValidationException::withMessages([
                'client_role' => [__('actions.open_matter.client_role_opposing_counsel')],
            ]);
        }

        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;

        // R13(g)/`conflict-11`: bước 3 (kiểm tra) tới hết bước 5 (lưu) chạy dưới MỘT khoá ứng
        // dụng — xem "Về khoá R13(g)" ở docblock lớp cho lý do khoá dòng `clients` một mình
        // không đủ.
        //
        // Fix round 1, minor ruling: `LockTimeoutException` (khoá không lấy được sau 10 giây, xem
        // "Về khoá R13(g)") giờ đổi thành một lời từ chối tiếng Việt qua `ConflictCheckBusy`
        // (`DomainException`, cùng khuôn `OurClientPartyNeedsClient`), KHÔNG còn là một lỗi 500
        // trần. `CreateMatter::handleRecordCreation()` đã có sẵn `catch (DomainException $exception)`
        // cho mọi luật nghiệp vụ ở tầng Action, nên không cần sửa gì ở màn hình — throw đúng loại
        // là đủ. Round 0 cố ý KHÔNG bắt lỗi này; chủ nhiệm đã đảo phán quyết đó ở round 1.
        try {
            return Cache::store('database')->lock('conflict-check', 30)->block(10, function () use (
                $attributes, $parties, $clientRole, $actor, $overrideReason, $acknowledged, $newClient, $excludeIntakeId, $beforeCommit,
            ): OpenMatterResult {
                // Bước 3.
                /** @var array{0: ConflictCheckResult, 1: Collection<int, MatterParty>} $checked */
                $checked = DB::transaction(function () use ($attributes, $parties, $clientRole, $actor, $newClient, $excludeIntakeId): array {
                    $clients = $this->lockClients($newClient === null ? $attributes['client_id'] : null, $parties);

                    $proposedParties = collect([
                        $newClient === null
                            ? $this->buildOwnClientParty($clients->get((int) $attributes['client_id']), $clientRole)
                            : $this->buildUnsavedOwnClientParty($newClient, $clientRole),
                        ...collect($parties)->map(fn (array $party) => $this->buildMatterParty(
                            $party,
                            lockedClient: $clients->get((int) ($party['client_id'] ?? 0)),
                        )),
                    ]);

                    // Actor truyền xuống để dòng `conflict_check_run` và dòng `matter_opened` — hai bằng
                    // chứng của CÙNG một thao tác — không bao giờ ghi hai người khác nhau.
                    // `$excludeIntakeId` (M10 Task 4): xem docblock tham số cùng tên của `handle()`.
                    return [app(RunConflictCheck::class)->handle($proposedParties, null, $actor, excludeIntakeId: $excludeIntakeId), $proposedParties];
                });

                [$result, $proposedParties] = $checked;

                $isOverridden = false;

                // Bước 4.
                if ($result->isBlocking()) {
                    // Xem `AddMatterParty` bước 3: quy tắc "ai ghi đè được" chỉ có một nơi ở, kể cả ở tầng
                    // cổng thật — hai bản viết tay giống hệt nhau là đúng hình dạng đã lệch nhau bốn lần
                    // trên nhánh này.
                    $canOverride = ConflictOverride::allowedFor($actor)
                        && $overrideReason !== null && $overrideReason !== '';

                    if (! $canOverride) {
                        throw ConflictBlocked::make($result);
                    }

                    $isOverridden = true;
                } elseif ($result->requiresAcknowledgement() && $acknowledged !== $result->level) {
                    throw ConflictAcknowledgementRequired::make($result);
                }

                // Bước 5.
                return DB::transaction(function () use (
                    $attributes, $proposedParties, $result, $isOverridden, $overrideReason, $actor, $newClient, $beforeCommit,
                ): OpenMatterResult {
                    // Final review A-M7: khách hàng mới chỉ ra đời ở ĐÂY — bước 4 đã cho qua. Bên
                    // khách hàng dựng ở bước 3 (chưa có `client_id`) nhận id vừa có, rồi
                    // `refreshOwnClientIdentitiesUnderLock()` bên dưới khoá và áp lại định danh từ
                    // chính hồ sơ này như mọi bên `is_our_client` khác.
                    if ($newClient !== null) {
                        $client = app(CreateClient::class)->persist($actor, $newClient);
                        $attributes['client_id'] = $client->getKey();
                        $proposedParties->first()->client_id = $client->getKey();
                    }

                    // `blameOn()` TRƯỚC khi save(), cùng lý do như `TransitionMatterStage` bước 5:
                    // Action đã nhận actor rõ ràng để kiểm tra quyền, nên hai cột "ai tạo" phải ghi đúng
                    // actor đó chứ không suy luận từ `auth('web')` ambient mà `HasBlameable` mặc định
                    // dùng — phiên đang mở có thể là người khác, hoặc không có phiên nào (job, console).
                    $matter = new Matter($attributes);
                    $matter->blameOn($actor)->save();

                    // R13(g)/`conflict-06` (M6.5 Task 8): dòng `conflict_check_run` mà `RunConflictCheck`
                    // vừa ghi ở bước 3 có `subject` rỗng — vụ việc lúc đó chưa tồn tại. Gắn lại NGAY khi
                    // vụ việc vừa có id thật, để "bằng chứng đã kiểm tra" thật sự gắn được với vụ việc nó
                    // mô tả (xem docblock `ConflictCheckResult::$auditLogId`). Cập nhật thẳng bằng query,
                    // không qua Eloquent: đây là một dòng nhật ký của thư viện thứ ba, không phải model
                    // của ứng dụng, và một `update()` ở đây không cần (và không nên) chạy lại observer
                    // nào của `Activity`.
                    if ($result->auditLogId !== null) {
                        Activity::query()->whereKey($result->auditLogId)->update([
                            'subject_type' => $matter->getMorphClass(),
                            'subject_id' => $matter->getKey(),
                        ]);
                    }

                    // M6.5 Task 3 (R6, finding `intake-01`/`roles-03`/`spec-gap-01`/`e2e-F4`, critical).
                    // `Matter::created()` (đã chạy trong `save()` ở trên) chỉ thêm LEAD vào `matter_user`.
                    // Một actor có `matter.create` nhưng KHÔNG có `matter.viewAny` (hôm nay: `Lawyer`) mà
                    // giao vụ việc cho một `lead_lawyer_id` KHÁC mình thì không nằm trong đội ngũ và
                    // không có `matter.viewAny` để bù lại — `scopeListableBy()` không liệt kê được vụ này
                    // cho họ, và mở thẳng URL ra 404, NGAY sau khi họ vừa bấm lưu. Tự thêm actor vào đội
                    // ngũ với vai `associate` ở đây, CÙNG transaction với việc tạo vụ việc (bước 5), nên
                    // không có khoảnh khắc nào vụ việc tồn tại mà chính người mở ra nó không thấy được nó.
                    //
                    // Không cần hỏi `manageTeam` ở đây: đây không phải một lần "thêm thành viên" qua cổng
                    // đội ngũ (`AddTeamMember`), nó là một phần của chính việc MỞ vụ việc — cổng đã kiểm
                    // tra ở bước 1 (`MatterPolicy::create`) là cổng đúng cho hành động này.
                    //
                    // **Fix round 2, finding "Also fix" — bỏ qua trên một vụ `restricted` mà actor sẽ
                    // KHÔNG thấy được, cùng luật I1 đã áp cho `AddTeamMember`.** `Matter::isListableBy()`
                    // nhánh `restricted` không đọc `team()` chút nào (chỉ `hasRole(Admin)` hoặc chính
                    // `lead_lawyer_id`) — thêm actor vào đội ngũ ở đây KHÔNG đổi câu trả lời của `view()`
                    // cho một vụ `restricted`, nên trước bản sửa này một luật sư mở một vụ `restricted`
                    // rồi giao cho đồng nghiệp phụ trách vẫn bị tự thêm vào đội ngũ với vai `associate` mà
                    // KHÔNG BAO GIỜ mở được vụ đó — đúng "thành viên vô hình" mà phán quyết I1 cấm, và
                    // `CheckDeadlines`/các thư sau này lấy người nhận từ `team()` sẽ mời một người không
                    // đọc được thư đó vào chi tiết vụ việc. Đính lại: `addTeamMember()` rồi hỏi lại CHÍNH
                    // `Gate::view()` như `AddTeamMember::handle()` làm (không viết lại luật `restricted`
                    // một lần nữa ở đây) — nếu không qua, `detach()` ngay, không ghi audit. Không throw:
                    // đây là một tiện ích tự động, không phải một thao tác actor vừa yêu cầu qua một ô
                    // trên form, nên không có gì để báo lỗi vào — actor vẫn mở vụ thành công, chỉ đơn
                    // giản là không có mặt trong đội ngũ (giống hệt trước khi tính năng này tồn tại).
                    if (! $actor->can(Permission::MatterViewAny->value) && $matter->lead_lawyer_id !== $actor->getKey()) {
                        $matter->addTeamMember($actor, MatterRole::Associate);

                        if (Gate::forUser($actor)->allows('view', $matter)) {
                            Audit::record('team_member_added', $matter, [
                                'user_id' => $actor->getKey(),
                                'role' => MatterRole::Associate->value,
                                'auto_added_by_open_matter' => true,
                            ], $actor);
                        } else {
                            $matter->team()->detach($actor->getKey());
                        }
                    }

                    // Ruling (fix round 3) — đua tranh hash cũ: khoá lại VÀ ĐỌC LẠI hồ sơ Client
                    // ngay TRƯỚC KHI lưu, không tin ảnh chụp đã dựng ở bước 3; fix round 4 (NB-1):
                    // định danh đã đổi thì xếp một lần rà lại (xem docblock
                    // `refreshOwnClientIdentitiesUnderLock()`).
                    $this->refreshOwnClientIdentitiesUnderLock($proposedParties);

                    $proposedParties->each(function (MatterParty $party) use ($matter, $actor): void {
                        $party->blameOn($actor);
                        $matter->parties()->save($party);
                    });

                    // Khoá dòng vụ việc trước khi sao chép danh mục hồ sơ (carry-forward M1) — xem "Về
                    // hai khoá dòng" ở docblock lớp cho ý nghĩa thật của khoá này trong luồng hiện tại.
                    $lockedMatter = Matter::query()->whereKey($matter->id)->lockForUpdate()->firstOrFail();

                    // Nếu (đáng lẽ không xảy ra) loại vụ việc có nhiều hơn một template đang hoạt động,
                    // chọn có chủ đích template MỚI NHẤT (id lớn nhất) thay vì nhận bất kỳ thứ tự ngầm
                    // định nào của DB — quyết định tường minh, không phải mặc định tình cờ.
                    $template = ChecklistTemplate::query()
                        ->where('matter_type_id', $lockedMatter->matter_type_id)
                        ->where('is_active', true)
                        ->orderByDesc('id')
                        ->first();

                    if ($template !== null) {
                        app(ApplyChecklistTemplate::class)->handle($lockedMatter, $template);
                    }

                    Audit::record('matter_opened', $matter, [
                        'conflict_level' => $result->level->value,
                        'conflict_overridden' => $isOverridden,
                        'override_reason' => $isOverridden ? $overrideReason : null,
                        'incomplete_conflict_parties' => $result->incompleteParties(),
                        // R13(c)/`conflict-01` (M6.5 Task 8, fix round 1 C1): chữ ký + MỨC ĐÃ CHẤP
                        // NHẬN của MỌI khớp MỚI vừa được chấp nhận ở bước 4 (ghi đè hoặc xác nhận) —
                        // nếu $result->allNewMatches rỗng thì mảng này rỗng, không ghi gì thừa.
                        // `pairKey()` trả `null` khi bên phía mình CHƯA có id thật lúc kiểm tra chạy
                        // — nhưng ở ĐÂY, sau `$proposedParties->each(save())` phía trên, mọi bên đề
                        // xuất đã có id thật (cùng đối tượng PHP, xem docblock `ConflictMatch`), nên
                        // `pairKey()` gọi LẠI ở đây trả về chữ ký thật cho MỌI khớp lịch sử VÀ khớp
                        // "cùng vụ việc, hai phía đối lập" (R13b tham gia R13c từ fix round 1, I1 —
                        // không còn ngoại lệ). `filter()` chỉ còn loại trường hợp phòng thủ (found
                        // chưa có id — không nên xảy ra, xem docblock `ConflictMatch`). Đọc lại ở
                        // lần chạy sau qua `RunConflictCheck::confirmedPairLevels()`.
                        //
                        // **`allNewMatches`, KHÔNG phải `matches` (fix round 2, NB1).** `matches` đã
                        // gộp hiển thị (hai dòng form trùng nhau do gõ nhầm hai lần hiện thành MỘT
                        // dòng) — ghi `confirmed_pairs` từ đó bỏ sót `pairKey()` của dòng bị gộp
                        // mất, và lần thêm bên KẾ TIẾP (không liên quan) sẽ thấy cặp đó là "Đỏ MỚI"
                        // và chặn cứng người thêm. Xem docblock `ConflictCheckResult::$allNewMatches`.
                        'confirmed_pairs' => $result->allNewMatches
                            ->map(fn ($match) => ['pair_key' => $match->pairKey(), 'level' => $match->level->value])
                            ->filter(fn (array $pair) => $pair['pair_key'] !== null)
                            ->values()
                            ->all(),
                    ], $actor);

                    // Xem "Về công bố portal ngay lúc mở vụ việc" ở docblock lớp.
                    if ($matter->is_published_to_portal) {
                        Audit::record('matter_portal_publication_set', $matter, [
                            'publish' => true,
                            'published_stage_log_count' => 0,
                            'at_creation' => true,
                        ], $actor);
                    }

                    // M10 Task 4 — xem docblock tham số `$beforeCommit` của `handle()`.
                    if ($beforeCommit !== null) {
                        $beforeCommit($matter);
                    }

                    return new OpenMatterResult($matter, $result, $isOverridden, $isOverridden ? $overrideReason : null);
                });
            });
        } catch (LockTimeoutException) {
            throw ConflictCheckBusy::make();
        }
    }

    /**
     * Ruling (fix round 3) — đóng lỗ hổng đua tranh hash cũ (`stale-hash race`).
     *
     * **Lỗ hổng.** `buildOwnClientParty()`/`buildMatterParty()` (bước 3) dựng ảnh chụp định danh
     * (`name`, `id_number_hash`, `phone_normalized`) của một bên `is_our_client` từ hồ sơ `Client`
     * đã khoá (`lockClients()`) — nhưng khoá dòng đó CHỈ sống trong transaction của bước 3, và
     * được RELEASE ngay khi transaction đó COMMIT. Giữa lúc đó và lúc bước 5 (một transaction
     * RIÊNG) thật sự `save()` các bên, không có gì giữ dòng `clients` nữa. Nếu một
     * `EditClient::save()` KHÁC chen vào ĐÚNG khoảng trống đó và sửa CCCD/điện thoại/tên của CHÍNH
     * khách hàng này, bên vừa lưu ở bước 5 mang HASH CŨ — sai VĨNH VIỄN nếu đây là vụ việc ĐẦU TIÊN
     * của khách hàng đó: `SyncClientPartyIdentities` của lần sửa kia chạy TRƯỚC khi dòng
     * `matter_parties` này tồn tại trong CSDL, nên không có gì để nó đồng bộ lại — không lần kiểm
     * tra xung đột nào trong tương lai từng thấy định danh ĐÚNG của bên này.
     *
     * **Sửa.** Ngay TRƯỚC khi lưu (bước 5, vẫn trong transaction của bước đó), khoá LẠI (thứ tự
     * tăng dần, cùng quy ước `lockClients()`) và ĐỌC LẠI mọi hồ sơ `Client` mà các bên
     * `is_our_client` trỏ tới, rồi gán lại `name`/`identify()` từ giá trị VỪA đọc — không tin ảnh
     * chụp đã dựng ở bước 3, dù chỉ vài mili giây trước. Đây là khoá dòng THỨ HAI trên CÙNG một
     * dòng `clients` trong CÙNG một lần mở vụ (bước 3 và bước 5) — an toàn vì hai transaction đó
     * KHÔNG chồng lấn nhau (bước 3 đã commit và release khoá trước khi bước 5 mở transaction mới),
     * nên không có gì để deadlock với chính nó.
     *
     * **Fix round 4 (NB-1, phán quyết (a)) — định danh đã đổi thì xếp một lần rà lại.** Lưu đúng
     * hash thôi chưa đủ: kết quả kiểm tra ở bước 3 và xác nhận/ghi đè ở bước 4 được tính trên ảnh
     * chụp CŨ, nên định danh MỚI chưa từng được đối chiếu với các vụ việc đang mở khác. Nếu đây là
     * vụ ĐẦU TIÊN của khách hàng, `SyncClientPartyIdentities` của lần sửa kia cũng không có dòng nào
     * để đồng bộ. Vì vậy, khi tên, `id_number_hash` hay `phone_normalized` vừa đọc lại KHÁC ảnh chụp
     * lần kiểm tra đã dùng (`reapplyFreshClientIdentity()`, dùng chung với `AddMatterParty`), xếp
     * `RecheckClientIdentityConflicts` cho khách hàng đó, `afterCommit()` — job đọc lại mọi thứ khi
     * nó chạy, nên nó thấy bên vừa lưu ở đây. Không tự chạy lại `RunConflictCheck` tại chỗ: vụ việc
     * đã được chấp nhận ở bước 4, và đường báo "xung đột lộ ra sau khi sửa định danh" (thông báo +
     * audit `client_identity_conflict_detected`, R13e) chỉ có một nơi ở, là job đó.
     *
     * `SyncClientPartyIdentities` cũng xếp lần rà từ phía lần sửa (phán quyết (b)), nên một lần đua
     * đi qua `Client::updated()` xếp HAI job cho cùng khách hàng. Không bỏ bên nào: đường này che
     * những lối ghi không kích hoạt sự kiện model (docblock `Client::booted()`), đường kia che những
     * lối lưu bên không đi qua lần làm mới này.
     *
     * **Dispatch nằm bên trong khoá `conflict-check`.** `afterCommit()` chèn dòng `jobs` ngay khi
     * transaction bước 5 commit — vẫn trong closure của khoá. Với hàng đợi `database` (cấu hình thật
     * của dự án, `.env.example`) đó chỉ là một câu `INSERT`; worker chỉ lấy được khoá sau khi
     * `handle()` nhả nó. Với driver `sync`, job sẽ chạy tại chỗ và chờ chính khoá này — các test đi
     * vào nhánh này vì vậy chuyển sang hàng đợi `database` (xem `OpenMatterTest.php`).
     *
     * @param  Collection<int, MatterParty>  $proposedParties
     */
    private function refreshOwnClientIdentitiesUnderLock(Collection $proposedParties): void
    {
        $clientIds = $proposedParties
            ->filter(fn (MatterParty $party): bool => $party->is_our_client && $party->client_id !== null)
            ->pluck('client_id')
            ->unique()
            ->sort()
            ->values();

        if ($clientIds->isEmpty()) {
            return;
        }

        $freshClients = Client::query()
            ->whereIn('id', $clientIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var array<int, true> $changedClientIds Khoá theo id — một khách hàng, một job. */
        $changedClientIds = [];

        foreach ($proposedParties as $party) {
            if (! $party->is_our_client || $party->client_id === null) {
                continue;
            }

            $client = $freshClients->get((int) $party->client_id);

            if ($client !== null && $this->reapplyFreshClientIdentity($party, $client)) {
                $changedClientIds[(int) $party->client_id] = true;
            }
        }

        foreach (array_keys($changedClientIds) as $clientId) {
            RecheckClientIdentityConflicts::dispatch($clientId)->afterCommit();
        }
    }

    /**
     * Khoá MỌI dòng `clients` mà lần mở vụ việc này sẽ đọc, trong MỘT câu lệnh và theo thứ tự khoá
     * chính tăng dần — xem "Về các khoá dòng" ở docblock lớp cho lý do thứ tự là bắt buộc chứ
     * không phải gọn gàng.
     *
     * `firstOrFail()` cũ trở thành một phép đối chiếu tập hợp: một `client_id` do form gửi lên mà
     * không có dòng tương ứng phải nổ thành `ModelNotFoundException` y như trước, không được im
     * lặng bỏ qua rồi dựng một bên "là khách hàng của văn phòng" mà không có hồ sơ nào phía sau.
     *
     * @param  array<int, array<string, mixed>>  $parties
     * @return Collection<int, Client> Hồ sơ đã khoá, khoá mảng theo id.
     */
    private function lockClients(int|string|null $primaryClientId, array $parties): Collection
    {
        $ids = collect([$primaryClientId])
            ->merge(collect($parties)
                ->filter(fn (array $party): bool => (bool) ($party['is_our_client'] ?? false))
                ->map(fn (array $party) => $party['client_id'] ?? null))
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        /** @var Collection<int, Client> $clients */
        $clients = Client::query()
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $missing = $ids->reject(fn (int $id): bool => $clients->has($id));

        if ($missing->isNotEmpty()) {
            throw (new ModelNotFoundException)->setModel(Client::class, $missing->all());
        }

        return $clients;
    }

    /**
     * Bên "khách hàng của chính vụ việc" — không có dòng nào trong `$parties` của form, nên Action
     * tự dựng từ hồ sơ `Client` đã khoá ở bước 3. Đi qua CÙNG một đường dựng như mọi bên khác
     * (`BuildsMatterParties`, dùng chung với `AddMatterParty`): chính trait đó ép tên và định danh
     * lấy từ hồ sơ thật, nên không còn một quy tắc "chỉ áp cho own-client party" nào để quên nữa.
     */
    private function buildOwnClientParty(Client $client, PartyRole $role): MatterParty
    {
        return $this->buildMatterParty([
            'role' => $role,
            'is_our_client' => true,
            'client_id' => $client->getKey(),
            'name' => $client->name,
            'address' => $client->address,
        ], lockedClient: $client);
    }

    /**
     * Final review A-M7: bên khách hàng cho một hồ sơ `Client` CHƯA LƯU — cùng tên, địa chỉ và định
     * danh mà `buildOwnClientParty()` sẽ lấy từ hồ sơ đó sau khi lưu, nên kết quả kiểm tra ở bước 3
     * đúng là kết quả của bên sẽ được lưu ở bước 5. Không đi qua `buildMatterParty()`: trait đó
     * từ chối một bên `is_our_client` chưa có `client_id` (`OurClientPartyNeedsClient`) — đúng cho
     * mọi đường khác, và ở đây `client_id` được gán ngay khi hồ sơ ra đời ở bước 5.
     */
    private function buildUnsavedOwnClientParty(Client $client, PartyRole $role): MatterParty
    {
        $party = new MatterParty([
            'role' => $role,
            'is_our_client' => true,
            'client_id' => null,
            'address' => $client->address,
        ]);
        $party->name = $client->name;

        return $party->identify($client->id_number, $client->phone);
    }
}
