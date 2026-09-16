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
 * `opposing_counsel` ở một vụ khác không phải là xung đột lợi ích).
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

        $ourClientRoles = $allParties
            ->filter(fn (MatterParty $party) => $party->is_our_client)
            ->pluck('role');

        $matches = $allParties
            ->flatMap(fn (MatterParty $party) => $this->matchesFor($party, $ourClientRoles, $matter))
            ->unique(fn (ConflictMatch $match) => implode('|', [
                $match->matterCode, $match->partyRole->value, $match->partyName, $match->level->value, $match->tier->value,
            ]))
            ->values();

        $incompleteParties = $parties
            ->filter(fn (MatterParty $party) => $party->id_number_hash === null && $party->phone_normalized === null)
            ->pluck('name')
            ->values();

        $level = $matches->contains(fn (ConflictMatch $match) => $match->level === ConflictLevel::Red)
            ? ConflictLevel::Red
            : ($matches->isEmpty() ? ConflictLevel::Green : ConflictLevel::Yellow);

        $result = new ConflictCheckResult($level, $matches, $incompleteParties);

        // `actor_explicit` đi cùng kết quả chứ không thay thế nó: nó nói dòng này được gán cho ai
        // theo KHẲNG ĐỊNH của caller (true) hay chỉ theo phiên đăng nhập tình cờ đang mở (false).
        Audit::record('conflict_check_run', $matter, [
            ...$result->toArray(),
            'actor_explicit' => $actor !== null,
        ], $actor);

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
            ->get()
            ->map(function (MatterParty $found) use ($idNumberHash, $phoneNormalized, $isOpposing): ConflictMatch {
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
                );
            });
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
