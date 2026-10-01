<?php

namespace App\Actions\Intake;

use App\Actions\RunConflictCheck;
use App\Enums\PartyRole;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\ConflictCheckResult;
use Illuminate\Support\Collection;

/**
 * Chạy `RunConflictCheck` cho một lần tiếp nhận và GHI kết quả lên bản ghi (M10 R1) — bước dùng
 * chung của `RecordIntake`, `RerunIntakeConflictCheck`, `AcknowledgeIntakeConflict` và
 * `ResolveIntakeRedConflict`, để "một lần kiểm tra ở lần chạm đầu" có đúng MỘT cách dựng đầu vào.
 *
 * **NGƯỜI GỌI PHẢI ĐANG GIỮ khoá `conflict-check`** (xem `HoldsConflictCheckLock`, không tái nhập)
 * và đang ở trong một transaction nếu muốn kết quả ghi cùng các thay đổi khác của họ. Lớp này cố ý
 * không tự lấy khoá.
 *
 * **Dựng đầu vào từ DỮ LIỆU ĐÃ LƯU, không từ số gốc.** Số CCCD/SĐT gốc chỉ có ở lúc nhập
 * (`RecordIntake`); sau đó chỉ còn dấu băm và số chuẩn hoá — và lần chạy lại (sau khi sửa danh tính,
 * lúc xác nhận, lúc ghi đè) cũng chỉ có chừng đó. Nên MỌI lần chạy, kể cả lần đầu, dựng các
 * `MatterParty` chưa lưu từ chính các cột đã lưu; hai đường dựng khác nhau là hai định nghĩa "cùng
 * một người" sẽ lệch nhau.
 *  - Người liên hệ = `MatterParty` `is_our_client`, `client_id` null, vai = `contact_role`. Vai chưa
 *    khai thì suy ra từ bên đối lập (đối của nguyên đơn là bị đơn và ngược lại), còn không thì
 *    `related` — để Đỏ không lặng lẽ tắt chỉ vì người gọi chưa nói mình là nguyên đơn hay bị đơn.
 *    Vai suy ra chỉ dùng cho lần kiểm tra, KHÔNG ghi lại vào `contact_role`.
 *  - Bên đối lập = `MatterParty` `is_our_client = false`, với dấu băm và SĐT chuẩn hoá đã lưu.
 *
 * **Ghi lên bản ghi:** `conflict_level` (mức của các khớp MỚI, như `OpenMatter`), `conflict_checked_at`,
 * `conflict_result` (hình dạng `properties` của dòng audit, kèm `fingerprint` — dấu vân tay danh
 * tính đã chạy, để cổng ô câu chuyện biết kết quả có còn khớp danh tính hiện tại không).
 *
 * **Khi nào xác nhận/ghi đè cũ bị xoá:** khi lần chạy này có khớp MỚI (chưa từng được chấp nhận) hoặc
 * danh tính đã đổi so với lần chạy trước. Xác nhận Vàng và ghi đè Đỏ chỉ che những khớp người ta đã
 * thấy; một khớp mới thì phải được nhìn lại. Ngược lại, một lần chạy lại không có gì mới KHÔNG xoá
 * chúng — đúng R13(c): cổng không được luôn bật.
 */
class CheckIntakeConflict
{
    public function handle(?User $actor, IntakeRequest $intake): ConflictCheckResult
    {
        $parties = $intake->parties()->get();

        $result = app(RunConflictCheck::class)->handle(
            $this->buildParties($intake, $parties),
            null,
            $actor,
            null,
            null,
            $intake,
        );

        $fingerprint = $intake->identityFingerprint();
        $previousFingerprint = is_array($intake->conflict_result) ? ($intake->conflict_result['fingerprint'] ?? null) : null;

        $intake->fill([
            'conflict_level' => $result->level,
            'conflict_checked_at' => now(),
            'conflict_result' => [...$result->toArray(), 'fingerprint' => $fingerprint],
        ]);

        if ($result->matches->isNotEmpty() || $previousFingerprint !== $fingerprint) {
            $intake->fill([
                'conflict_acknowledged_by' => null,
                'conflict_acknowledged_at' => null,
                'conflict_overridden_by' => null,
                'conflict_override_reason' => null,
            ]);
        }

        if ($actor !== null) {
            $intake->blameOn($actor);
        }

        $intake->save();

        return $result;
    }

    /**
     * @param  Collection<int, IntakeParty>  $intakeParties
     * @return Collection<int, MatterParty>
     */
    private function buildParties(IntakeRequest $intake, Collection $intakeParties): Collection
    {
        $contact = (new MatterParty([
            'role' => $intake->contact_role ?? $this->impliedContactRole($intakeParties),
            'name' => $intake->contact_name,
            'is_our_client' => true,
        ]));
        $contact->id_number_hash = $intake->contact_id_number_hash;
        $contact->phone_normalized = $intake->contact_phone_normalized;

        return collect([
            $contact,
            ...$intakeParties->map(function (IntakeParty $party): MatterParty {
                $built = new MatterParty(['role' => $party->role, 'name' => $party->name, 'is_our_client' => false]);
                $built->id_number_hash = $party->id_number_hash;
                $built->phone_normalized = $party->phone_normalized;

                return $built;
            })->all(),
        ]);
    }

    /** Vai của người liên hệ khi chưa khai: đối của vai (nguyên đơn/bị đơn) đầu tiên trong các bên đối lập. */
    private function impliedContactRole(Collection $intakeParties): PartyRole
    {
        foreach ($intakeParties as $party) {
            if ($party->role === PartyRole::Plaintiff) {
                return PartyRole::Defendant;
            }

            if ($party->role === PartyRole::Defendant) {
                return PartyRole::Plaintiff;
            }
        }

        return PartyRole::Related;
    }
}
