<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use App\Support\Normalizer;
use Illuminate\Support\Collection;

/**
 * Kiểm tra xung đột lợi ích (SPEC §6.10). Thuần đọc trên dữ liệu nghiệp vụ: Action này không bao
 * giờ tạo hay sửa `Matter`/`MatterParty`. Ghi duy nhất là dòng activity log ở bước 4, bắt buộc ở
 * MỌI lần chạy kể cả kết quả xanh — "phải chứng minh được là đã kiểm tra".
 *
 * **Hai thời điểm bắt buộc chạy (SPEC §6.10 đầu bài):**
 *  - Vụ việc CHƯA tồn tại (trước khi `OpenMatter` lưu): truyền `$parties` là các `MatterParty`
 *    chưa lưu (`exists === false`, dựng từ dữ liệu form qua `identify()`), `$matter = null`.
 *  - Vụ việc ĐÃ tồn tại, thêm một bên mới: truyền `$parties` là các bên hiện có của vụ việc
 *    (`$matter->parties()->get()`) CỘNG bên mới (chưa lưu), và `$matter` = vụ việc đó — để loại
 *    các bên của chính vụ này ra khỏi kết quả tìm kiếm (không tự xung đột với chính mình).
 *
 * **Ngoại lệ có chủ đích của phân quyền** (SPEC §6.10 đoạn cuối): truy vấn toàn bộ
 * `matter_parties`, KHÔNG qua `Matter::listableBy` — một xung đột trong vụ việc người dùng không
 * có quyền mở vẫn phải hiện ra. Bù lại, `ConflictMatch` chỉ mang đúng năm trường không nhạy cảm
 * (xem docblock của lớp đó); không bao giờ nạp `title`, `summary_for_client`,
 * `description_internal` hay id tài liệu.
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
 */
class RunConflictCheck
{
    /**
     * @param  Collection<int, MatterParty>  $parties  Toàn bộ các bên đang được xem xét cho vụ
     *                                                 việc đang kiểm tra (xem hai thời điểm chạy ở docblock lớp).
     * @param  Matter|null  $matter  Vụ việc đang chạy kiểm tra, nếu đã tồn tại. Dùng để loại các
     *                               bên của chính vụ này khỏi kết quả tìm kiếm, và làm chủ thể (`subject`) của activity log.
     */
    public function handle(Collection $parties, ?Matter $matter = null): ConflictCheckResult
    {
        $ourClientRoles = $parties
            ->filter(fn (MatterParty $party) => $party->is_our_client)
            ->pluck('role');

        $matches = $parties
            ->flatMap(fn (MatterParty $party) => $this->matchesFor($party, $ourClientRoles, $matter))
            ->values();

        $level = $matches->contains(fn (ConflictMatch $match) => $match->level === ConflictLevel::Red)
            ? ConflictLevel::Red
            : ($matches->isEmpty() ? ConflictLevel::Green : ConflictLevel::Yellow);

        $result = new ConflictCheckResult($level, $matches);

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
            ->with('matter.matterType')
            ->when($matter, fn ($query) => $query->where('matter_id', '!=', $matter->id))
            ->where(function ($query) use ($idNumberHash, $phoneNormalized, $nameNormalized): void {
                $query->when($idNumberHash, fn ($q) => $q->orWhere('id_number_hash', $idNumberHash))
                    ->when($phoneNormalized, fn ($q) => $q->orWhere('phone_normalized', $phoneNormalized))
                    ->when($nameNormalized, fn ($q) => $q->orWhere('name_normalized', $nameNormalized));
            })
            ->get()
            // Matter không cascade xoá mềm sang matter_parties: một bên có thể còn ở DB trong khi
            // vụ việc của nó đã bị xoá mềm, khiến quan hệ ->matter (có scope loại trừ đã xoá) trả
            // về null. Vụ đã xoá không còn là "vụ việc khác" theo nghĩa của SPEC §6.10, nên bỏ qua
            // thay vì crash.
            ->reject(fn (MatterParty $found) => $found->matter === null)
            ->map(function (MatterParty $found) use ($idNumberHash, $phoneNormalized, $isOpposing): ConflictMatch {
                // Ưu tiên khớp SPEC §6.10 bước 2: hash "chắc chắn" > điện thoại "rất khả nghi" >
                // tên "cần xem xét". Chỉ hai mức đầu đủ tin cậy để lên đỏ (xem docblock lớp).
                $isCertainOrPhoneMatch =
                    ($idNumberHash !== null && $found->id_number_hash === $idNumberHash)
                    || ($phoneNormalized !== null && $found->phone_normalized === $phoneNormalized);

                $isRed = $isCertainOrPhoneMatch && $found->is_our_client && $isOpposing;

                return new ConflictMatch(
                    matterCode: $found->matter->code,
                    matterTypeName: $found->matter->matterType->name,
                    partyRole: $found->role,
                    partyName: $found->name,
                    level: $isRed ? ConflictLevel::Red : ConflictLevel::Yellow,
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
