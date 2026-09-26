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
 * **Đây là kiểm tra LỊCH SỬ theo VỤ VIỆC, không phải kiểm tra sự tồn tại hiện thời của vụ việc đó
 * — nhưng KHÔNG áp dụng cho từng BÊN (fix round 2, I1/R14, đính chính đoạn này).** Một hồ sơ vụ
 * việc đã xoá mềm (`Matter::forceDeleting` bị chặn — vụ việc không bao giờ thật sự biến mất) vẫn
 * từng đại diện cho một tranh chấp thật, nên Action này cố ý bỏ qua `SoftDeletingScope` của
 * `Matter` khi tìm bản ghi trùng — một dòng cũ gây ra cảnh báo vàng giả (người xem xét bấm xác
 * nhận rồi tiếp tục) rẻ hơn RẤT nhiều so với bỏ sót một xung đột lợi ích thật, đây là chức năng
 * duy nhất trong hệ thống nơi false negative là vi phạm đạo đức nghề nghiệp, còn false positive
 * chỉ là một cú xác nhận thêm. **Nhưng một BÊN (`MatterParty`) đã xoá mềm — tức đã bị GỠ khỏi
 * chính vụ việc của nó — thì KHÁC:** R14 ra phán quyết một bên đã gỡ "chưa từng là bên" (gỡ kèm lý
 * do bắt buộc, không phải một hành vi tình cờ), nên nó không còn là dữ liệu đối chiếu xung đột ở
 * BẤT KỲ ĐÂU — kể cả khi đang so khớp LỊCH SỬ ở một vụ việc khác vẫn đang mở. Bản round 1 chỉ sửa
 * đúng nửa này ở `existingParties()` (các bên CỦA vụ việc đang xét), để sót `matchesFor()` (các bên
 * ở CÁC VỤ VIỆC KHÁC) vẫn `withTrashed()` — xem docblock ở đó.
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
 * TRƯỚC trên CÙNG vụ việc — vẫn hiện, không chặn lại) bằng `ConflictMatch::pairKey()` (chữ ký
 * "bên phía mình ↔ bản ghi tìm thấy", theo ID THẬT của hai dòng — xem docblock ở đó, và xem "fix
 * round 1, C1" bên dưới cho lý do KHÔNG còn dùng chữ ký định danh) đối chiếu với
 * `confirmedPairLevels()` — mức CAO NHẤT đã từng được chấp nhận cho từng cặp, lấy từ chính các
 * dòng `matter_opened`/`matter_party_added` mà `OpenMatter`/`AddMatterParty` đã ghi (khoá
 * `confirmed_pairs` trong properties, xem docblock hai Action đó). Từ fix round 1 (I1),
 * `sameMatterOppositionMatches()` (R13b) THAM GIA cơ chế này như mọi khớp khác — không còn miễn
 * trừ, xem docblock hàm đó.
 *
 * **Fix round 1, C1 (Critical) — chữ ký phải theo ID DÒNG, không theo định danh.** Bản đầu của
 * R13(c) dùng một chữ ký định danh ("hash:xxx" của bên phía mình, nối id thật của bên tìm thấy)
 * làm `pairKey`, và chỉ lưu chữ ký — không lưu MỨC đã chấp nhận. Hai lỗ hổng, cả hai đều thật:
 * (1) chữ ký định danh không phân biệt được hai DÒNG khác nhau của cùng một người — Y thêm hai
 * lần (lần đầu vai `related`, khớp Vàng, được xác nhận; lần sau — một DÒNG MỚI — vai `defendant`,
 * khớp Đỏ) băm ra CÙNG một chữ ký, nên lần Đỏ bị nhận nhầm là "đã xác nhận" và hiện Xanh; (2)
 * không lưu mức thì một xác nhận Vàng (tên trùng, chưa đối lập) bị đọc nhầm là đã xử lý một Đỏ
 * MỚI của chính cặp đó (đổi vai khách hàng, hay một lần đồng bộ định danh nâng tầng khớp từ tên
 * lên hash). `ConflictMatch::pairKey()` giờ tính từ `getKey()` thật của hai model (xem docblock
 * lớp đó), và `confirmed_pairs` lưu CẢ mức — `handle()` chỉ coi một cặp là đã xử lý khi
 * `$match->level->rank() <= $confirmedLevel->rank()`.
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

        // "Thô" — CHƯA gộp hiển thị (xem "Fix round 2, NB1" bên dưới cho lý do phải tách hai bước
        // này ra làm hai biến khác nhau, không còn gộp NGAY tại đây như round 1).
        $rawMatches = $allParties
            ->flatMap(fn (MatterParty $party) => $this->matchesFor($party, $ourClientRoles, $matter))
            // R13(b): mâu thuẫn NGAY TRONG vụ việc đang xét, không phải một khớp với lịch sử — xem
            // docblock lớp và docblock hàm bên dưới.
            ->concat($this->sameMatterOppositionMatches($ourClientParties))
            ->values();

        // R13(c): tách khớp MỚI (quyết định level) khỏi khớp đã xác nhận/ghi đè ở một lần chạy
        // TRƯỚC trên cùng vụ việc (vẫn hiện, không chặn lại) — xem docblock lớp và
        // `confirmedPairLevels()`. Fix round 1, C1: một cặp chỉ được coi là "đã xử lý" khi mức của
        // NÓ Ở LẦN CHẠY NÀY không nghiêm trọng hơn mức đã từng được chấp nhận cho ĐÚNG cặp đó —
        // một Vàng đã xác nhận không "dùng hộ" cho một Đỏ mới của cùng hai dòng.
        //
        // Phân chia trên danh sách THÔ (fix round 2, NB1): mỗi cặp (bên phía mình ↔ bản ghi tìm
        // thấy) THẬT được xét độc lập — hai cặp thật khác nhau nhưng hiện giống hệt nhau không
        // được phép "dùng chung" một quyết định đã xác nhận/ghi đè chỉ vì gộp hiển thị làm chúng
        // trông như một.
        $confirmedPairLevels = $this->confirmedPairLevels($matter);

        $isConfirmed = function (ConflictMatch $match) use ($confirmedPairLevels): bool {
            $pairKey = $match->pairKey();

            if ($pairKey === null || ! $confirmedPairLevels->has($pairKey)) {
                return false;
            }

            return $match->level->rank() <= $confirmedPairLevels->get($pairKey)->rank();
        };

        [$rawConfirmed, $rawNew] = $rawMatches->partition($isConfirmed);
        $rawNew = $rawNew->values();
        $rawConfirmed = $rawConfirmed->values();

        // Khoá gộp CHỈ dùng cho hai danh sách HIỂN THỊ (`$newMatches`/`$confirmedMatches`) — xem
        // docblock `ConflictCheckResult::$allNewMatches` cho lý do `$rawNew` (dùng để GHI
        // `confirmed_pairs`) không được đi qua bước gộp này. `pairKey()` nằm trong khoá gộp (fix
        // round 1, minor ruling): hai khớp giống hệt nhau ở NĂM trường hiển thị nhưng khác DÒNG
        // thật không được phép gộp làm một khi CẢ HAI đều có id thật — chỉ hai dòng CHƯA lưu,
        // giống hệt nhau ở mọi trường hiển thị (kể cả bên phía mình), mới cố tình gộp thành một
        // dòng cho người xem xét (xem test "deduplicates identical matches...").
        $dedupeKey = fn (ConflictMatch $match) => implode('|', [
            $match->matterCode, $match->partyRole->value, $match->partyName, $match->level->value, $match->tier->value,
            $match->ourPartyRole->value, $match->ourPartyName, $match->pairKey() ?? 'unsaved',
        ]);

        $newMatches = $rawNew->unique($dedupeKey)->values();
        $confirmedMatches = $rawConfirmed->unique($dedupeKey)->values();

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

        $result = new ConflictCheckResult($level, $newMatches, $confirmedMatches, $incompleteParties, $rawNew);

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
     * Bỏ `ClientPortalScope` (cùng lý do với `matchesFor()`).
     *
     * **KHÔNG `withTrashed()` (fix round 1, I1/R14).** Bản trước nạp cả các bên đã xoá mềm của
     * CHÍNH vụ việc đang xét, với lý lẽ "một bên đã xoá mềm vẫn từng đại diện cho khách hàng đó".
     * Bên CỦA CHÍNH vụ việc đang xét, và R14 đã ra phán quyết: "gỡ một bên là xoá mềm kèm lý do bắt
     * buộc… bên đã gỡ KHÔNG còn trong dữ liệu đối chiếu xung đột, vì gỡ nghĩa là 'nhập nhầm, chưa
     * từng là bên'". Một bên đã gỡ khỏi CHÍNH vụ việc này không còn là một phần của `$ourClientRoles`
     * hay của `sameMatterOppositionMatches()` — nạp nó lại bằng `withTrashed()` sẽ làm một xung đột
     * "cùng vụ việc, hai phía đối lập" đã được gỡ đúng cách tái xuất hiện, đúng thứ Task 9 gỡ bên
     * tồn tại để ngăn.
     *
     * **Đính chính fix round 3 — `matchesFor()` KHÔNG còn là ngoại lệ của luật này.** Đoạn TRÊN
     * (bản round 1) từng viết "lý lẽ đó đúng cho `matchesFor()` — không đổi", ngụ ý hàm đó VẪN
     * `withTrashed()` ở `MatterParty` và một bên đã gỡ vẫn khớp được khi tìm ở CÁC VỤ VIỆC KHÁC.
     * SAI kể từ fix round 2 (I1, hoàn tất phán quyết R14): `matchesFor()` cũng đã bỏ `withTrashed()`
     * ở `MatterParty` (chỉ còn giữ ở quan hệ `matter` nạp kèm — một VỤ VIỆC đã xoá mềm vẫn khớp
     * được, khác trục với một BÊN đã gỡ). R14 áp cho MỌI nơi, không chỉ vụ việc đang xét — xem
     * docblock lớp và docblock `matchesFor()`.
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

        return MatterParty::query()
            // Fix round 2, I1/R14 (bản round 1 chỉ sửa nửa `existingParties()`, để sót chỗ này):
            // KHÔNG `withTrashed()` ở CHÍNH `MatterParty` — một bên đã GỠ (xoá mềm) khỏi vụ việc
            // của nó không còn là dữ liệu đối chiếu xung đột Ở BẤT KỲ ĐÂU (phán quyết R14: "gỡ
            // nghĩa là nhập nhầm, chưa từng là bên"), kể cả khi vụ việc CHỨA nó vẫn đang mở và
            // đang được so khớp LỊCH SỬ ở đây. Đây là kiểm tra lịch sử theo VỤ VIỆC (một vụ đã
            // xoá mềm vẫn từng thật), KHÔNG phải theo BÊN đã gỡ (một bên đã gỡ được phán quyết là
            // chưa từng thật) — hai trục khác nhau, không dùng chung một `withTrashed()`. `matter`
            // nạp kèm VẪN `withTrashed()` (dòng dưới) để một bên còn hợp lệ của một VỤ đã xoá mềm
            // tiếp tục khớp được — chỉ trục "bên" đổi, trục "vụ việc" giữ nguyên.
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
            ->map(function (MatterParty $found) use ($idNumberHash, $phoneNormalized, $isOpposing, $party): ConflictMatch {
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
                    ourPartyRecord: $party,
                    foundPartyRecord: $found,
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
     * **`pairKey()` áp DỤNG như mọi khớp khác (fix round 1, I1 — không còn miễn trừ).** Bản trước
     * cố tình gán `pairKey: null` cho loại khớp này, với lý lẽ "một mâu thuẫn ngay trong vụ việc
     * thì VẪN đang mở cho tới khi ai đó gỡ một bên, nên không được phép im lặng cho qua ở lần chạy
     * sau". Lý lẽ đó ĐÚNG cho câu hỏi "có được coi là đã xử lý VĨNH VIỄN không" — nhưng chủ nhiệm
     * đã bác bỏ cách nó được cài đặt (round 1 finding I1): loại trừ HẲN loại khớp này khỏi R13(c)
     * nghĩa là một manager ghi đè ĐÚNG cặp này một lần vẫn phải ghi đè LẠI ở MỌI lần thêm bên khác,
     * không liên quan gì tới cặp đó — đúng cái cổng-luôn-bật mà `conflict-01` mô tả, chỉ chuyển
     * sang một loại khớp khác. Phán quyết mới: dùng CHUNG cơ chế `pairKey()`/`confirmedPairLevels()`
     * như khớp lịch sử — cặp (X, W) một khi đã bị ghi đè ở ĐÚNG mức đó thì không chặn lại một
     * request KHÁC không đụng tới cặp đó, nhưng một cặp MỚI (Y đối lập X, hay chính X/W ở một mức
     * CAO HƠN mức đã ghi đè) vẫn chặn bình thường — cùng bất biến C1 áp cho mọi `pairKey()`.
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
                    ourPartyRecord: $party,
                    foundPartyRecord: $other,
                ));
            }
        }

        return $matches;
    }

    /**
     * R13(c): với mỗi chữ ký `ConflictMatch::pairKey()` đã từng được xác nhận hoặc ghi đè ở MỘT
     * LẦN CHẠY TRƯỚC của `RunConflictCheck` trên CÙNG vụ việc `$matter`, mức CAO NHẤT đã từng được
     * chấp nhận cho đúng cặp đó. Đọc lại từ properties của chính hai dòng nghiệp vụ mà
     * `OpenMatter`/`AddMatterParty` ghi SAU KHI lưu thành công (`matter_opened`/
     * `matter_party_added`, khoá `confirmed_pairs` — mảng `['pair_key' => string, 'level' =>
     * string]`) — không phải từ `conflict_check_run`: dòng đó được ghi ở GIAI ĐOẠN KIỂM TRA, TRƯỚC
     * KHI biết người dùng có xác nhận/ghi đè hay không, nên nó không phải là nơi đúng để hỏi "cặp
     * này đã được CHẤP NHẬN ở mức nào".
     *
     * **Vì sao lưu CẢ mức, không chỉ chữ ký (fix round 1, C1/`conflict-01`).** Bản trước chỉ lưu
     * chữ ký — hệ quả: một cặp được xác nhận ở mức VÀNG (khớp tên, chưa đối lập) sau đó ĐỔI SANG
     * ĐỎ (một lần sửa vai khách hàng khiến hai bên trở nên đối lập, hay một lần đồng bộ định danh
     * nâng tầng khớp từ tên lên hash) sẽ bị đọc nhầm là "cặp này đã xác nhận rồi" và hiện XANH —
     * một xác nhận VÀNG không phải là một quyết định cho một xung đột ĐỎ chưa ai từng thấy. Một
     * cặp chỉ được coi là đã xử lý khi mức hiện tại KHÔNG NGHIÊM TRỌNG HƠN mức cao nhất đã từng
     * được chấp nhận — so bằng `ConflictLevel::rank()`, xem `handle()`.
     *
     * `$matter === null` (lúc mở vụ việc, giai đoạn kiểm tra của `OpenMatter`) luôn trả về rỗng —
     * đúng về mặt logic: vụ việc còn chưa tồn tại nên không thể có gì được xác nhận từ TRƯỚC trên
     * nó. `!$matter->exists` (phòng thủ, không nên xảy ra ở lời gọi thật) cũng vậy.
     *
     * **Bỏ qua một dòng `confirmed_pairs` không phải mảng (fix round 2, minor).** Bản round 0 (TRƯỚC
     * `pairKey()`/mức) từng ghi mảng CHUỖI trần (`['hash:xxx::123', ...]`, không phải
     * `['pair_key' => ..., 'level' => ...]`). Một vụ việc còn giữ dòng `matter_opened`/
     * `matter_party_added` cũ dạng đó (từ trước khi lớp này tồn tại) khiến `flatMap()` đẩy một
     * CHUỖI vào `reduce()` — chữ ký cũ `array $pair` ném `TypeError`, thành một lỗi 500 mỗi lần
     * `RunConflictCheck` chạy trên đúng vụ việc đó. Chữ ký giờ nhận `mixed`, và `is_array()` loại
     * êm những dòng cũ đó — không đọc được (chấp nhận được: dữ liệu định dạng cũ không mang đủ
     * thông tin để khôi phục mức đã xác nhận), nhưng không còn làm SẬP cả lần kiểm tra.
     *
     * @return Collection<string, ConflictLevel>
     */
    private function confirmedPairLevels(?Matter $matter): Collection
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
            ->reduce(function (Collection $levels, mixed $pair): Collection {
                if (! is_array($pair)) {
                    return $levels;
                }

                $pairKey = $pair['pair_key'] ?? null;
                $level = ConflictLevel::tryFrom($pair['level'] ?? '');

                if ($pairKey === null || $level === null) {
                    return $levels;
                }

                $existing = $levels->get($pairKey);

                if ($existing === null || $level->rank() > $existing->rank()) {
                    $levels->put($pairKey, $level);
                }

                return $levels;
            }, collect());
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
