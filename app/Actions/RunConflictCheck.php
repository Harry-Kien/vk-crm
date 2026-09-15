<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Models\Matter;
use App\Models\MatterParty;
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
 *  - Vụ việc ĐÃ tồn tại, thêm một bên mới: truyền `$parties` là bên mới (chưa lưu) — có thể kèm
 *    hoặc không kèm các bên đã có của vụ việc, KHÔNG quan trọng, vì Action tự nạp
 *    `$matter->parties` để xác định ai là khách hàng mới và vai của họ (xem "Không được để lộ
 *    mức đỏ qua cách gọi" bên dưới) — và `$matter` = vụ việc đó, để loại các bên của chính vụ này
 *    ra khỏi kết quả tìm kiếm (không tự xung đột với chính mình).
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
 * `ClientPortalScope` một cách tường minh trên cả `MatterParty` lẫn `Matter` lồng trong kết quả:
 * nếu Action này lỡ chạy trong lúc guard `client` đang đăng nhập (job, lệnh nền, hay đơn giản là
 * cả hai guard cùng có phiên), scope đó thu hẹp `Matter`/`MatterParty` về đúng một khách hàng và
 * vụ đã công bố — im lặng biến MỌI xung đột thành xanh. Bù lại, `ConflictMatch` chỉ mang đúng
 * những trường không nhạy cảm (xem docblock của lớp đó); không bao giờ nạp `title`,
 * `summary_for_client`, `description_internal` hay id tài liệu.
 *
 * **Không được để lộ mức đỏ qua cách gọi:** vai của "khách hàng mới" (dùng để xác định "đối lập")
 * được tính từ CẢ `$parties` truyền vào LẪN `$matter->parties` khi `$matter` khác null. Nếu chỉ
 * dựa vào `$parties`, một lời gọi chỉ truyền đúng bên mới thêm (không kèm khách hàng đã có của vụ)
 * sẽ luôn thấy "không có khách hàng nào trong tập đang xét", `isOpposing()` luôn false, và mức đỏ
 * không bao giờ kích hoạt được — dù bên mới thêm thực sự đối lập với khách hàng đã có. Vì luật
 * nghiệp vụ (đỏ hay không) không được phép phụ thuộc vào việc caller có nhớ truyền đủ dữ liệu hay
 * không, Action tự nạp `$matter->parties` thay vì tin caller.
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
 */
class RunConflictCheck
{
    /**
     * @param  Collection<int, MatterParty>  $parties  Các bên đang được xem xét cho vụ việc đang
     *                                                 kiểm tra (xem hai thời điểm chạy ở docblock lớp).
     * @param  Matter|null  $matter  Vụ việc đang chạy kiểm tra, nếu đã tồn tại. Dùng để loại các
     *                               bên của chính vụ này khỏi kết quả tìm kiếm, để nạp thêm các bên đã có của vụ vào việc xác
     *                               định "khách hàng mới" (xem docblock lớp), và làm chủ thể (`subject`) của activity log.
     */
    public function handle(Collection $parties, ?Matter $matter = null): ConflictCheckResult
    {
        $rolesSource = $matter !== null ? $parties->merge($matter->parties) : $parties;

        $ourClientRoles = $rolesSource
            ->filter(fn (MatterParty $party) => $party->is_our_client)
            ->pluck('role');

        $matches = $parties
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

        Audit::record('conflict_check_run', $matter, $result->toArray());

        return $result;
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
