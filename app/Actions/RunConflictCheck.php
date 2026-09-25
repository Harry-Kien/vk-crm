<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Kiểm tra xung đột lợi ích (SPEC §6.10). Thuần đọc trên dữ liệu nghiệp vụ: Action này không bao
 * giờ tạo hay sửa `Matter`/`MatterParty`. Ghi duy nhất là dòng activity log ở bước 4, bắt buộc ở
 * MỌI lần chạy kể cả kết quả xanh — "phải chứng minh được là đã kiểm tra".
 *
 * **Hai thời điểm bắt buộc chạy (SPEC §6.10 đầu bài):**
 *  - Vụ việc CHƯA tồn tại (trước khi `OpenMatter` lưu): truyền `$parties` là các `MatterParty`
 *    chưa lưu (`exists === false`, dựng từ dữ liệu form qua `identify()`), `$matter = null`.
 *  - Vụ việc ĐÃ tồn tại, thêm một bên mới: truyền `$parties` là bên mới (chưa lưu) — có kèm hay
 *    không kèm các bên đã có của vụ việc THẬT SỰ không quan trọng, vì Action tự nạp các bên đã có
 *    của `$matter` (qua `existingParties()` bên dưới) và dùng CHUNG một tập hợp — `$parties` gộp
 *    với các bên đã có — cho CẢ HAI việc: xác định ai là "khách hàng mới" (xem "Không được để lộ
 *    mức đỏ qua cách gọi") VÀ tìm bản ghi trùng cho TỪNG bên trong tập hợp đó, kể cả các bên đã có
 *    sẵn của vụ (xem "Tìm kiếm phải chạy trên cùng tập hợp với việc xác định vai" bên dưới) — chứ
 *    không chỉ một trong hai như các bản sửa trước. `$matter` = vụ việc đó, dùng để loại các bên
 *    của chính vụ này ra khỏi kết quả tìm kiếm (không tự xung đột với chính mình — xem
 *    `matchesFor()`, `where('matter_id', '!=', ...)`).
 *
 * **Đây là kiểm tra LỊCH SỬ, không phải kiểm tra sự tồn tại hiện thời.** Một hồ sơ đã xoá mềm
 * (`Matter::forceDeleting` bị chặn — vụ việc không bao giờ thật sự biến mất) hoặc một bên đã xoá
 * mềm vẫn từng đại diện cho một người, ở một thời điểm nào đó. Action này cố ý bỏ qua
 * `SoftDeletingScope` của cả `Matter` lẫn `MatterParty` khi tìm bản ghi trùng: một dòng cũ gây ra
 * cảnh báo vàng giả (người xem xét bấm xác nhận rồi tiếp tục) rẻ hơn RẤT nhiều so với bỏ sót một
 * xung đột lợi ích thật — đây là chức năng duy nhất trong hệ thống nơi false negative là vi phạm
 * đạo đức nghề nghiệp, còn false positive chỉ là một cú xác nhận thêm.
 *
 * **Ngoại lệ có chủ đích của phân quyền** (SPEC §6.10 đoạn cuối): truy vấn toàn bộ
 * `matter_parties`, KHÔNG qua `Matter::listableBy`. Cùng lý do đó, Action cũng bỏ qua
 * `ClientPortalScope` một cách tường minh trên cả `MatterParty` lẫn `Matter` lồng trong kết quả.
 * **Cả HAI truy vấn trong lớp này đều phải bỏ scope này** — truy vấn tìm bản ghi trùng
 * (`matchesFor()`) VÀ truy vấn nạp các bên đã có của vụ để xác định "khách hàng mới"
 * (`existingParties()` bên dưới). Bỏ sót truy vấn thứ hai từng là một lỗi thật (fix round 2): nếu
 * Action lỡ chạy trong lúc guard `client` đang đăng nhập (job, lệnh nền, hay đơn giản là chỉ có
 * guard đó có phiên), `$matter->parties()` không bỏ scope sẽ trả về rỗng, "khách hàng mới" biến
 * mất, và mức đỏ không bao giờ kích hoạt được — dù truy vấn tìm bản ghi trùng đã đúng. Người sửa
 * sau này: nếu thêm một truy vấn `MatterParty`/`Matter` thứ ba vào lớp này, nó CŨNG phải bỏ scope
 * này, cùng lý do. Bù lại, `ConflictMatch` chỉ mang đúng những trường không nhạy cảm (xem docblock
 * của lớp đó); không bao giờ nạp `title`, `summary_for_client`, `description_internal` hay id tài
 * liệu.
 *
 * **Không được để lộ mức đỏ qua cách gọi:** vai của "khách hàng mới" (dùng để xác định "đối lập")
 * được tính từ CẢ `$parties` truyền vào LẪN các bên đã có của `$matter` khi `$matter` khác null.
 * Nếu chỉ dựa vào `$parties`, một lời gọi chỉ truyền đúng bên mới thêm (không kèm khách hàng đã có
 * của vụ) sẽ luôn thấy "không có khách hàng nào trong tập đang xét", `isOpposing()` luôn false, và
 * mức đỏ không bao giờ kích hoạt được — dù bên mới thêm thực sự đối lập với khách hàng đã có. Vì
 * luật nghiệp vụ (đỏ hay không) không được phép phụ thuộc vào việc caller có nhớ truyền đủ dữ liệu
 * hay không, Action tự nạp các bên đã có của `$matter` thay vì tin caller.
 *
 * **Tìm kiếm phải chạy trên CÙNG tập hợp với việc xác định vai (fix round 3).** Round 2 chỉ gộp
 * các bên đã có của `$matter` vào việc xác định `$ourClientRoles`, nhưng KHÔNG gộp vào vòng lặp
 * tìm bản ghi trùng (`flatMap` gọi `matchesFor()`) — vòng đó vẫn chỉ chạy trên `$parties`. Hệ quả:
 * một vụ đã có sẵn bị đơn Y (thêm vào lúc vụ chưa có bên khách hàng nào, nên khi đó không thể lên
 * đỏ), sau đó thêm khách hàng mới X (nguyên đơn) bằng lời gọi CHỈ truyền đúng X — đúng như câu
 * "không quan trọng" ở trên cho phép — sẽ chỉ tìm bản ghi trùng cho X, không bao giờ xét lại Y, dù
 * bây giờ vụ việc đã đủ điều kiện đỏ (Y đối lập X VÀ Y là khách hàng của văn phòng ở vụ khác). Vì
 * vậy `handle()` gọi `matchesFor()` cho TỪNG bên trong tập hợp gộp (`$allParties`), không chỉ
 * `$parties` — các bên đã có của vụ được xét lại mỗi lần chạy cũng như bên mới. Khớp thêm ở một
 * lần chạy lại không phải là nhiễu: đó là xung đột thật mà lần chạy trước không thể phát hiện, và
 * `unique()` ở cuối `handle()` đã gộp các bản ghi trùng lặp nếu có.
 *
 * **Gộp không được dùng `Illuminate\Database\Eloquent\Collection::merge()`.** Fix round 2: khi
 * `$parties` là một Eloquent Collection (đúng hình dạng được khuyến nghị ở docblock trên —
 * `$matter->parties()->get()->push($newParty)`) và chứa một `MatterParty` CHƯA LƯU
 * (`getKey() === null`), `Collection::getDictionary()` của Eloquent BỎ QUA hẳn các phần tử có khoá
 * null khi dựng dictionary — bên chưa lưu biến mất khỏi kết quả gộp một cách im lặng, kể cả khi
 * chính bên đó mang vai "khách hàng mới". `Illuminate\Support\Collection::merge()` (khi `$parties`
 * là `collect([...])` thường) không có vấn đề này vì nó không dùng ngữ nghĩa dictionary theo khoá
 * chính. Vì kiểu thực tế của `$parties` tại lời gọi có thể là một trong hai, Action gộp bằng mảng
 * PHP thuần (`[...$parties->all(), ...$existingParties]`) để không bao giờ đi qua đường
 * dictionary-theo-khoá của Eloquent.
 *
 * **Đọc "đối lập" (SPEC §6.10 bảng mức Đỏ) theo đúng nghĩa đen:** một bên `plaintiff`, bên kia
 * `defendant`, không hơn không kém. `related`, `third_party`, `opposing_counsel` KHÔNG bao giờ
 * được coi là đối lập với khách hàng mới — dù bên đó đang là khách hàng của văn phòng ở vụ khác,
 * việc mang một trong ba vai này vào vụ mới không tự lên mức đỏ, chỉ có thể lên vàng như mọi bên
 * khác nếu từng xuất hiện ở hồ sơ khác. SPEC không liệt kê ba vai này trong định nghĩa đối lập, và
 * suy rộng ra rủi ro chặn nhầm việc lưu một hồ sơ hợp lệ (vd. mời đúng luật sư đối phương cũ làm
 * `opposing_counsel` ở một vụ khác không phải là xung đột lợi ích). Quy tắc này áp cho khớp LỊCH
 * SỬ (`matchesFor()`, `isOpposing()`) — `sameMatterOppositionMatches()` bên dưới (R13b) là một quy
 * tắc KHÁC, hẹp hơn, chỉ so `plaintiff`/`defendant` với nhau NGAY TRONG vụ việc đang xét.
 *
 * **Chỉ khớp "chắc chắn" (`id_number_hash`) hoặc "rất khả nghi" (`phone_normalized`) mới đủ tin
 * cậy để lên mức đỏ.** Khớp theo tên đã chuẩn hoá ("cần người xem xét" — SPEC §6.10 bước 2) không
 * bao giờ tự lên đỏ dù vai có đối lập; nó chỉ lên vàng, bắt người tạo tích xác nhận đã xem xét.
 * Đây là lựa chọn có chủ đích ở điểm SPEC không nói rõ: một cái tên trùng (kể cả sau chuẩn hoá)
 * không đủ chắc chắn để CHẶN việc lưu hồ sơ — tên người Việt trùng nhau rất phổ biến — trong khi
 * cảnh báo vàng vẫn buộc người tạo phải xem xét trước khi lưu. Ưu tiên không chặn nhầm việc hợp lệ
 * hơn là bỏ sót; bỏ sót vẫn còn lưới an toàn (vàng bắt xác nhận), chặn nhầm thì không.
 *
 * **Một bên không có định danh nào chỉ so khớp được theo tên.** Nếu caller dựng `MatterParty` mà
 * quên gọi `identify()` (hoặc gọi với `null`, `null`), `id_number_hash` và `phone_normalized` của
 * bên đó không tồn tại — Action không có cách nào biết họ có trùng số căn cước với ai hay không,
 * vì dữ liệu gốc chưa từng được đưa vào. Đây không phải lỗi để bỏ qua trong im lặng: bên đó được
 * liệt kê ở `ConflictCheckResult::incompleteParties()`, và kèm vào activity log, để người xem xét
 * biết kết quả xanh của riêng bên này KHÔNG đáng tin cậy bằng bên đã có đủ định danh. Caller LUÔN
 * PHẢI gọi `identify()` với số căn cước/điện thoại thật khi có; đây chỉ là lưới an toàn khi họ quên.
 *
 * **Ai đã chạy lần kiểm tra này (fix M3, review toàn nhánh, finding 2).** Dòng `conflict_check_run`
 * không chỉ chứng minh ĐÃ kiểm tra mà còn chứng minh AI kiểm tra, nên `$actor` là tham số — caller
 * nào biết người thực hiện thì phải nói ra, thay vì để Action đoán từ phiên đăng nhập. `OpenMatter`
 * và `AddMatterParty` LUÔN truyền actor tường minh của chúng, để dòng này và dòng nghiệp vụ đi kèm
 * (`matter_opened` / `matter_party_added`) không bao giờ ghi hai người khác nhau cho cùng một thao
 * tác. Tham số để CUỐI và TUỲ CHỌN một cách có chủ đích: lớp này còn là một chẩn đoán thuần đọc mà
 * một màn hình có thể chạy thăm dò (SPEC §7.2 "chạy lại và hiện kết quả tại chỗ"), và một lệnh
 * console/job chẩn đoán thật sự KHÔNG có actor nào để nói — bắt buộc actor sẽ buộc những chỗ đó bịa
 * ra một người. Đổi lại, dòng nhật ký phải trung thực về chỗ danh tính đến từ đâu: thuộc tính
 * `actor_explicit` là `true` khi caller KHẲNG ĐỊNH actor, `false` khi không — ở trường hợp `false`,
 * causer (nếu có) chỉ là suy luận từ phiên đang mở của `Audit::record`, và người đọc kiểm toán sau
 * này phải đọc được đúng như vậy chứ không phải như một lời khẳng định. Người sửa sau này: đừng
 * thêm `Auth::` vào lớp này để "điền cho đủ" — chỗ trống đó chính là thông tin.
 *
 * ---
 *
 * # M6.5 Task 8 — bốn phán quyết mới (R13a/b/c/d)
 *
 * **R13(a)/`conflict-02` — khách hàng quay lại không tự xung đột với CHÍNH hồ sơ cũ của mình.**
 * `OpenMatter::buildOwnClientParty()` dựng một bên `is_our_client` từ hồ sơ `Client` ở MỌI vụ việc
 * mở qua nó, nên một khách hàng đã có một vụ cũ luôn có sẵn một dòng `matter_parties` mang cùng
 * `id_number_hash`. Trước bản sửa này, `matchesFor()` không loại trừ trường hợp bản ghi TÌM THẤY
 * chính là một dòng khác của CÙNG khách hàng đó (`client_id` giống nhau) — nên mọi lần một khách
 * hàng quay lại mở vụ mới đều tự khớp với chính mình và lên mức vàng, kể cả khi không có ai khác
 * liên quan. Với luật sư (chỉ thấy khách hàng của những vụ mình đã liệt kê được — SPEC §5), mức
 * xanh vì vậy KHÔNG THỂ đạt được: mọi lần mở vụ đều phải tích "đã xem xét" trước một "xung đột" là
 * chính khách hàng của mình — đúng cái cổng luôn bật mà một người dùng thật học cách bấm cho qua.
 * `matchesFor()` giờ loại trừ TƯỜNG MINH bản ghi tìm thấy khi CẢ hai đúng: bên đang xét
 * (`$party`) là `is_our_client` với một `client_id`, VÀ bản ghi tìm thấy cũng `is_our_client` với
 * CÙNG `client_id` đó. Không loại một khách hàng KHÁC (`client_id` khác) — đó có thể là một xung
 * đột thật; không loại một bên KHÔNG PHẢI `is_our_client` — một bị đơn gõ tay trùng định danh với
 * chính khách hàng đang mở vụ là đúng tình huống SPEC §11 bullet 1 mô tả và phải tiếp tục lên đỏ.
 *
 * **R13(b)/`conflict-03` — hai khách hàng của văn phòng ở hai phía đối lập NGAY TRONG cùng một vụ
 * việc là Đỏ.** `matchesFor()` chỉ tìm bản ghi TRÙNG ở CÁC VỤ KHÁC (`where('matter_id', '!=',
 * ...)` khi thêm bên, hoặc hoàn toàn không tìm được gì lúc mở vụ vì chưa có gì để so — cả hai bên
 * đề xuất đều chưa nằm trong DB). Nếu hai khách hàng của văn phòng CHƯA từng có vụ nào (nên không
 * để lại dấu vết ở nơi khác) được nhập ở hai vai đối lập của CÙNG một vụ việc — dù qua form mở vụ
 * hay tab "Các bên" — trước bản sửa này kết quả là XANH SẠCH: hệ thống có đủ dữ kiện (cả hai đều
 * `is_our_client`, vai đối lập) nhưng chưa từng tự hỏi câu đó. `sameMatterOppositionMatches()`
 * bên dưới lấp đúng lỗ hổng này: so từng cặp bên `is_our_client` trong `$allParties` (gộp bên đề
 * xuất VÀ bên đã có của vụ, đúng tập hợp `handle()` đã dùng cho mọi việc khác) với NHAU — không
 * phải với lịch sử — và luôn Đỏ khi vai đối lập (`plaintiff`/`defendant`, đúng nghĩa hẹp của
 * `isOpposing()`) và không phải cùng một khách hàng thật (R13a áp lại ở đây: `client_id` khác
 * nhau). Tier riêng `ConflictMatchTier::SameMatter` vì đây không phải một phép so khớp định danh.
 *
 * **R13(c)/`conflict-01` — một khớp đã được xác nhận/ghi đè thì không chặn lại ở lần chạy sau.**
 * "Fix round 3" ở trên (xét lại MỌI bên đã có ở mỗi lần chạy) là cố ý và vẫn đúng — một vụ việc
 * chỉ đủ điều kiện đỏ SAU khi khách hàng được thêm vào phải được phát hiện. Nhưng nó có một hệ quả
 * chưa từng được xử lý: một khi mức đỏ của một cặp bên đã bị `manager` ghi đè (hay mức vàng đã
 * được xác nhận), CHÍNH cặp đó lại khớp lại ở MỌI lần thêm bên tiếp theo trên cùng vụ việc — với
 * `AddMatterParty` (không có gì để "ghi đè" cho vai luật sư phụ trách) đây là CHẶN CỨNG vĩnh viễn,
 * và thông báo còn đổ lỗi nhầm cho bên vừa nhập (xem R13d). `handle()` giờ tách kết quả thành
 * `matches` (MỚI — quyết định `level`) và `confirmedMatches` (đã xác nhận/ghi đè ở một lần chạy
 * TRƯỚC trên CÙNG vụ việc — vẫn hiện, không chặn lại) bằng `ConflictMatch::$pairKey` (chữ ký
 * "bên phía mình ↔ bản ghi tìm thấy", xem docblock ở đó) đối chiếu với `confirmedPairKeys()` —
 * tập hợp lấy từ chính các dòng `matter_opened`/`matter_party_added` mà `OpenMatter`/
 * `AddMatterParty` đã ghi (khoá `confirmed_pairs` trong properties, xem docblock hai Action đó).
 * Chỉ áp cho khớp LỊCH SỬ (`matchesFor()`); `sameMatterOppositionMatches()` (R13b) cố ý không có
 * `pairKey` — xem docblock hàm đó cho lý do không mở rộng R13c sang loại khớp này.
 *
 * **R13(d)/`conflict-07` — mỗi khớp mang nhãn "bên phía mình" gây ra khớp.** `ConflictMatch` giờ
 * mang thêm `ourPartyRole`/`ourPartyName`: vai và tên của bên ĐANG ĐƯỢC XÉT (`$party` trong
 * `matchesFor()`, hoặc một trong hai bên đối lập trong `sameMatterOppositionMatches()`) — không
 * phải bên tìm thấy. Trước bản sửa này bảng kết quả chỉ mô tả bản ghi TÌM THẤY, không nói bên nào
 * của form (hay bên nào đã có của vụ) gây ra khớp; khi vụ việc có nhiều bên, người xem xét phải
 * đoán, và `conflict-01` cho thấy đoán sai thì thông báo đổ lỗi nhầm cho bên vô can.
 */
class RunConflictCheck
{
    /**
     * @param  Collection<int, MatterParty>  $parties  Các bên đang được xem xét cho vụ việc đang
     *                                                 kiểm tra (xem hai thời điểm chạy ở docblock lớp).
     * @param  Matter|null  $matter  Vụ việc đang chạy kiểm tra, nếu đã tồn tại. Dùng để loại các
     *                               bên của chính vụ này khỏi kết quả tìm kiếm, để nạp thêm các bên đã có của vụ vào CẢ việc xác
     *                               định "khách hàng mới" LẪN việc tìm bản ghi trùng (xem docblock lớp), và làm chủ thể
     *                               (`subject`) của activity log.
     * @param  User|null  $actor  Người thực hiện lần kiểm tra này, nếu caller biết. Bắt buộc trên
     *                            mọi đường nghiệp vụ (`OpenMatter`, `AddMatterParty` đều truyền);
     *                            `null` CHỈ dành cho một lần chạy chẩn đoán thật sự không có người
     *                            thực hiện xác định — khi đó dòng nhật ký tự đánh dấu
     *                            `actor_explicit = false` thay vì im lặng nhận causer của phiên
     *                            đang mở như một khẳng định. Xem docblock lớp.
     */
    public function handle(Collection $parties, ?Matter $matter = null, ?User $actor = null): ConflictCheckResult
    {
        // Mảng thuần, không phải Collection::merge() — xem docblock lớp "Gộp không được dùng
        // Eloquent Collection::merge()". Tập hợp CHUNG này nuôi CẢ việc xác định vai LẪN việc tìm
        // bản ghi trùng bên dưới — xem docblock lớp "Tìm kiếm phải chạy trên CÙNG tập hợp với
        // việc xác định vai (fix round 3)".
        $allParties = collect([...$parties->all(), ...$this->existingParties($matter)]);

        $ourClientParties = $allParties->filter(fn (MatterParty $party) => $party->is_our_client);
        $ourClientRoles = $ourClientParties->pluck('role');

        $matches = $allParties
            ->flatMap(fn (MatterParty $party) => $this->matchesFor($party, $ourClientRoles, $matter))
            // R13(b): mâu thuẫn NGAY TRONG vụ việc đang xét, không phải một khớp với lịch sử — xem
            // docblock lớp và docblock hàm bên dưới.
            ->concat($this->sameMatterOppositionMatches($ourClientParties))
            ->unique(fn (ConflictMatch $match) => implode('|', [
                $match->matterCode, $match->partyRole->value, $match->partyName, $match->level->value, $match->tier->value,
                $match->ourPartyRole->value, $match->ourPartyName,
            ]))
            ->values();

        // R13(c): tách khớp MỚI (quyết định level) khỏi khớp đã xác nhận/ghi đè ở một lần chạy
        // TRƯỚC trên cùng vụ việc (vẫn hiện, không chặn lại) — xem docblock lớp và
        // `confirmedPairKeys()`.
        $confirmedPairKeys = $this->confirmedPairKeys($matter);

        [$confirmedMatches, $newMatches] = $matches->partition(
            fn (ConflictMatch $match): bool => $match->pairKey !== null && $confirmedPairKeys->contains($match->pairKey)
        );
        $newMatches = $newMatches->values();
        $confirmedMatches = $confirmedMatches->values();

        // CỐ Ý tính trên `$parties` chứ không phải `$allParties` — đây là chỗ DUY NHẤT hai tập
        // hợp tách nhau, nên nói rõ vì sao. Danh sách này chỉ phục vụ việc bắt người dùng tích
        // xác nhận (`requiresAcknowledgement()`), và lời xác nhận đó có nghĩa là "tôi biết những
        // bên TÔI đang nhập vào lúc này thiếu định danh nên kết quả xanh không đáng tin". Một bên
        // đã có sẵn của vụ việc mà thiếu định danh thì đã được xác nhận đúng như vậy ở lần nó
        // được thêm vào; kéo nó vào đây sẽ bắt xác nhận lại ở mọi lần thêm bên về sau, biến lời
        // xác nhận thành một cái nút bấm cho qua — đúng thứ làm hỏng giá trị của nó. Việc TÌM
        // KIẾM thì ngược lại, vẫn chạy trên `$allParties`: bỏ sót một bản ghi trùng là hậu quả
        // hoàn toàn khác hạng với việc hỏi thừa một câu.
        $incompleteParties = $parties
            ->filter(fn (MatterParty $party) => $party->id_number_hash === null && $party->phone_normalized === null)
            ->pluck('name')
            ->values();

        // Chỉ khớp MỚI quyết định mức — một khớp đã xác nhận/ghi đè trước đó không được phép bắt
        // người dùng xác nhận lại (R13c).
        $level = $newMatches->contains(fn (ConflictMatch $match) => $match->level === ConflictLevel::Red)
            ? ConflictLevel::Red
            : ($newMatches->isEmpty() ? ConflictLevel::Green : ConflictLevel::Yellow);

        $result = new ConflictCheckResult($level, $newMatches, $confirmedMatches, $incompleteParties);

        // `actor_explicit` đi cùng kết quả chứ không thay thế nó: nó nói dòng này được gán cho ai
        // theo KHẲNG ĐỊNH của caller (true) hay chỉ theo phiên đăng nhập tình cờ đang mở (false).
        $activity = Audit::record('conflict_check_run', $matter, [
            ...$result->toArray(),
            'actor_explicit' => $actor !== null,
        ], $actor);

        // R13(g)/`conflict-06`: id của dòng vừa ghi, để `OpenMatter` gắn lại `subject` của nó vào
        // vụ việc SAU KHI vụ việc được lưu (lúc kiểm tra chạy ở đây, vụ việc mới có thể còn chưa
        // tồn tại). Xem docblock `ConflictCheckResult::$auditLogId`.
        $result->auditLogId = $activity?->getKey();

        return $result;
    }

    /**
     * Các bên đã có của $matter, dùng để bổ sung vào CẢ việc xác định "khách hàng mới" LẪN việc
     * tìm bản ghi trùng (xem docblock lớp — cả hai đều chạy trên cùng tập hợp kể từ fix round 3).
     * Bỏ CẢ `ClientPortalScope` (cùng lý do với `matchesFor()`) LẪN `SoftDeletingScope` (một bên
     * khách hàng đã xoá mềm trong chính vụ đang xét vẫn từng đại diện cho khách hàng đó — nhất
     * quán với cách `matchesFor()` đọc lịch sử).
     *
     * @return array<int, MatterParty>
     */
    private function existingParties(?Matter $matter): array
    {
        if ($matter === null) {
            return [];
        }

        return $matter->parties()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->get()
            ->all();
    }

    /**
     * @param  Collection<int, PartyRole>  $ourClientRoles  Vai của (các) bên là khách hàng mới
     *                                                      trong vụ đang kiểm tra — dùng để xác định "đối lập" cho $party.
     * @return Collection<int, ConflictMatch>
     */
    private function matchesFor(MatterParty $party, Collection $ourClientRoles, ?Matter $matter): Collection
    {
        $idNumberHash = $party->id_number_hash;
        $phoneNormalized = $party->phone_normalized;
        // name_normalized chỉ được model tính ở sự kiện `saving` (xem MatterParty::booted()); một
        // bên đề xuất chưa lưu chưa chắc có giá trị này, nên tự chuẩn hoá lại từ `name` ở đây thay
        // vì tin vào thuộc tính model — luôn đúng bất kể $party đã lưu hay chưa.
        $nameNormalized = Normalizer::name($party->name);

        if ($idNumberHash === null && $phoneNormalized === null && $nameNormalized === null) {
            return collect();
        }

        $isOpposing = $this->isOpposing($party->role, $ourClientRoles);

        // R13(c): chữ ký định danh của CHÍNH bên đang xét — tầng mạnh nhất có sẵn, theo đúng thứ
        // tự ưu tiên của SPEC §6.10 bước 2. Dùng để dựng `ConflictMatch::$pairKey` bên dưới, tức
        // "bên phía mình (theo định danh) ↔ bản ghi tìm thấy (theo id thật)" — chữ ký này KHÔNG
        // cần $party đã được lưu: nó tính lại được y hệt ở lần chạy sau, dù lần đó $party là chính
        // dòng đã lưu của lần này hay một dòng khác mang cùng định danh, nên không cần theo dõi
        // đối tượng PHP hay id còn chưa tồn tại lúc lần chạy ĐẦU TIÊN diễn ra.
        $ourSignature = match (true) {
            $idNumberHash !== null => 'hash:'.$idNumberHash,
            $phoneNormalized !== null => 'phone:'.$phoneNormalized,
            $nameNormalized !== null => 'name:'.$nameNormalized,
            default => null,
        };

        return MatterParty::query()
            // Đây là kiểm tra lịch sử: một bên đã xoá mềm, hoặc thuộc một vụ đã xoá mềm, vẫn từng
            // đại diện cho một người thật. Bỏ sót ở đây là bỏ sót một xung đột lợi ích thật.
            ->withTrashed()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->with([
                'matter' => fn ($query) => $query->withTrashed()->withoutGlobalScope(ClientPortalScope::class)
                    ->with(['matterType' => fn ($q) => $q->withTrashed()]),
            ])
            ->when($matter, fn ($query) => $query->where('matter_id', '!=', $matter->id))
            ->where(function ($query) use ($idNumberHash, $phoneNormalized, $nameNormalized): void {
                $query->when($idNumberHash, fn ($q) => $q->orWhere('id_number_hash', $idNumberHash))
                    ->when($phoneNormalized, fn ($q) => $q->orWhere('phone_normalized', $phoneNormalized))
                    ->when($nameNormalized, fn ($q) => $q->orWhere('name_normalized', $nameNormalized));
            })
            // R13(a)/`conflict-02`: một khách hàng của văn phòng quay lại không phải xung đột với
            // CHÍNH hồ sơ cũ của mình — bên khớp là `is_our_client` với CÙNG `client_id`. Chỉ áp
            // khi CHÍNH bên đang xét cũng `is_our_client` kèm `client_id`: không loại một
            // `client_id` KHÁC (một khách hàng khác thật sự có thể đối lập), và không loại một
            // bên KHÔNG PHẢI `is_our_client` (một bị đơn gõ tay trùng định danh với đúng khách
            // hàng đang mở vụ vẫn phải lên đỏ — SPEC §11 bullet 1). Xem docblock lớp.
            ->when($party->is_our_client && $party->client_id !== null, fn ($query) => $query->where(function ($q) use ($party): void {
                $q->where('is_our_client', false)
                    ->orWhereNull('client_id')
                    ->orWhere('client_id', '!=', $party->client_id);
            }))
            ->get()
            ->map(function (MatterParty $found) use ($idNumberHash, $phoneNormalized, $isOpposing, $party, $ourSignature): ConflictMatch {
                // Ưu tiên khớp SPEC §6.10 bước 2: hash "chắc chắn" > điện thoại "rất khả nghi" >
                // tên "cần xem xét". Chỉ hai mức đầu đủ tin cậy để lên đỏ (xem docblock lớp).
                $tier = match (true) {
                    $idNumberHash !== null && $found->id_number_hash === $idNumberHash => ConflictMatchTier::Hash,
                    $phoneNormalized !== null && $found->phone_normalized === $phoneNormalized => ConflictMatchTier::Phone,
                    default => ConflictMatchTier::Name,
                };

                $isRed = in_array($tier, [ConflictMatchTier::Hash, ConflictMatchTier::Phone], true)
                    && $found->is_our_client
                    && $isOpposing;

                return new ConflictMatch(
                    matterCode: $found->matter->code,
                    matterTypeName: $found->matter->matterType->name,
                    partyRole: $found->role,
                    partyName: $found->name,
                    level: $isRed ? ConflictLevel::Red : ConflictLevel::Yellow,
                    tier: $tier,
                    ourPartyRole: $party->role,
                    ourPartyName: $party->name,
                    // Bản ghi tìm thấy luôn đã tồn tại trong DB (truy vấn ở trên chỉ đọc các dòng
                    // đã lưu), nên getKey() luôn có giá trị thật ở đây.
                    pairKey: $ourSignature !== null ? $ourSignature.'::'.$found->getKey() : null,
                );
            });
    }

    /**
     * R13(b)/`conflict-03`: hai khách hàng của văn phòng ở hai vai đối lập NGAY TRONG vụ việc
     * đang xét là Đỏ. Đây KHÔNG phải một khớp với lịch sử — `matchesFor()` không bao giờ tự phát
     * hiện việc này: lúc mở vụ, cả hai bên đề xuất đều CHƯA nằm trong DB nên không có gì để truy
     * vấn; lúc thêm bên vào một vụ đã tồn tại, `where('matter_id', '!=', $matter->id)` cố tình
     * loại CHÍNH vụ việc này khỏi kết quả tìm kiếm — đúng cho một khớp LỊCH SỬ, sai cho một mâu
     * thuẫn ngay trong tập hợp đang xét. Hàm này so từng CẶP bên `is_our_client` trong
     * `$ourClientParties` (đã gộp bên đề xuất VÀ bên đã có của vụ — đúng `$allParties` mà mọi
     * việc khác của `handle()` dùng) với NHAU.
     *
     * Vòng lặp O(n²) trên số bên `is_our_client` của một vụ việc — luôn nhỏ (một vụ kiện hiếm khi
     * có quá vài khách hàng của chính văn phòng), nên không cần tối ưu.
     *
     * **Vì sao KHÔNG gắn `pairKey` (R13c không áp cho loại khớp này).** R13c chỉ suy giảm một
     * khớp LỊCH SỬ thành "đã xác nhận, không chặn lại" vì lịch sử đó không đổi — cặp bên đó đã
     * từng được một manager xem xét và quyết định. Một mâu thuẫn NGAY TRONG vụ việc thì khác: hai
     * khách hàng đó VẪN đang ở hai phía đối lập của CÙNG một vụ việc cho tới khi ai đó thật sự gỡ
     * một trong hai bên (Task 9) — im lặng cho qua ở lần chạy sau chỉ vì lần trước đã ghi đè một
     * lần là để một xung đột đang mở tiếp tục mở, đúng thứ chức năng này tồn tại để CHẶN. Người
     * ghi đè đã đồng ý ĐÚNG MỘT LẦN chịu trách nhiệm cho quyết định đại diện cả hai phía; không
     * suy ra rằng họ đã đồng ý một lần cho MỌI lần thêm bên sau đó.
     *
     * @param  Collection<int, MatterParty>  $ourClientParties
     * @return Collection<int, ConflictMatch>
     */
    private function sameMatterOppositionMatches(Collection $ourClientParties): Collection
    {
        $matches = collect();

        foreach ($ourClientParties as $party) {
            foreach ($ourClientParties as $other) {
                if ($party === $other) {
                    continue;
                }

                // R13(a) áp lại ở đây: cùng một khách hàng thật (client_id giống nhau) xuất hiện
                // hai lần không phải xung đột với chính mình.
                if ($party->client_id !== null && $party->client_id === $other->client_id) {
                    continue;
                }

                if (! $this->isOpposing($party->role, collect([$other->role]))) {
                    continue;
                }

                $matches->push(new ConflictMatch(
                    matterCode: __('matters.conflict.same_matter_marker'),
                    matterTypeName: '',
                    partyRole: $other->role,
                    partyName: $other->name,
                    level: ConflictLevel::Red,
                    tier: ConflictMatchTier::SameMatter,
                    ourPartyRole: $party->role,
                    ourPartyName: $party->name,
                    pairKey: null,
                ));
            }
        }

        return $matches;
    }

    /**
     * R13(c): tập hợp các chữ ký `ConflictMatch::$pairKey` đã từng được xác nhận hoặc ghi đè ở
     * MỘT LẦN CHẠY TRƯỚC của `RunConflictCheck` trên CÙNG vụ việc `$matter`. Đọc lại từ properties
     * của chính hai dòng nghiệp vụ mà `OpenMatter`/`AddMatterParty` ghi SAU KHI lưu thành công
     * (`matter_opened`/`matter_party_added`, khoá `confirmed_pairs`) — không phải từ
     * `conflict_check_run`: dòng đó được ghi ở GIAI ĐOẠN KIỂM TRA, TRƯỚC KHI biết người dùng có
     * xác nhận/ghi đè hay không, nên nó không phải là nơi đúng để hỏi "cặp này đã được CHẤP NHẬN
     * chưa".
     *
     * `$matter === null` (lúc mở vụ việc, giai đoạn kiểm tra của `OpenMatter`) luôn trả về rỗng —
     * đúng về mặt logic: vụ việc còn chưa tồn tại nên không thể có gì được xác nhận từ TRƯỚC trên
     * nó. `!$matter->exists` (phòng thủ, không nên xảy ra ở lời gọi thật) cũng vậy.
     *
     * @return Collection<int, string>
     */
    private function confirmedPairKeys(?Matter $matter): Collection
    {
        if ($matter === null || ! $matter->exists) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', $matter->getMorphClass())
            ->where('subject_id', $matter->getKey())
            ->whereIn('event', ['matter_opened', 'matter_party_added'])
            ->get()
            ->flatMap(fn (Activity $activity): array => (array) $activity->properties->get('confirmed_pairs', []))
            ->values();
    }

    /** "Đối lập" = plaintiff đối defendant, hai chiều. Xem docblock lớp cho related/third_party/opposing_counsel. */
    private function isOpposing(PartyRole $role, Collection $ourClientRoles): bool
    {
        $opposite = match ($role) {
            PartyRole::Plaintiff => PartyRole::Defendant,
            PartyRole::Defendant => PartyRole::Plaintiff,
            default => null,
        };

        return $opposite !== null && $ourClientRoles->contains($opposite);
    }
}
