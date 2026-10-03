<?php

namespace App\Actions\Intake;

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
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
 *  - Người liên hệ = `MatterParty` `is_our_client`, `client_id` null, vai =
 *    {@see IntakeRequest::conflictContactRole()}: `contact_role`; chưa khai thì suy ra từ bên đối lập
 *    (đối của nguyên đơn là bị đơn và ngược lại), còn không thì `related` — để Đỏ không lặng lẽ tắt chỉ
 *    vì người gọi chưa nói mình là nguyên đơn hay bị đơn. Vai suy ra chỉ dùng cho lần kiểm tra, KHÔNG
 *    ghi lại vào `contact_role`.
 *  - Bên đối lập = {@see IntakeParty::toConflictParty()}.
 *
 * **Ghi lên bản ghi:** `conflict_level` (mức của các khớp MỚI, như `OpenMatter`), `conflict_checked_at`,
 * `conflict_result` (hình dạng `properties` của dòng audit, kèm `fingerprint` — dấu vân tay danh
 * tính đã chạy, để cổng ô câu chuyện biết kết quả có còn khớp danh tính hiện tại không — và
 * `repeat_call_locks` — dấu các khoá người gọi lại mà lần chạy này đã thấy,
 * {@see IntakeRequest::repeatCallLocks()}; fix vòng 2 của Task 4, N1: một ghi đè chỉ che những khoá
 * mà lần kiểm tra gần nhất đã thấy, {@see IntakeRequest::isHeldByRepeatCallLock()}).
 *
 * **Khi nào xác nhận/ghi đè cũ bị xoá:** khi lần chạy này có khớp MỚI (chưa từng được chấp nhận) hoặc
 * danh tính đã đổi so với lần chạy trước. Xác nhận Vàng và ghi đè Đỏ chỉ che những khớp người ta đã
 * thấy; một khớp mới thì phải được nhìn lại. Ngược lại, một lần chạy lại không có gì mới KHÔNG xoá
 * chúng — đúng R13(c): cổng không được luôn bật. Lý do của một ghi đè bị xoá khỏi cột vẫn còn trong
 * dòng `intake_conflict_overridden` của nó (fix vòng 1, I1): cột chỉ là "ghi đè đang có hiệu lực".
 *
 * **Đỏ DÍNH (fix vòng 1 của Task 2, I2): `conflict_red_pending_since`.** Đặt (giữ thời điểm ĐẦU) khi:
 *  - lần chạy này ra Đỏ; hoặc
 *  - (C1, người gọi lại — {@see IntakeRequest::isHeldByRepeatCallLock()}, cùng định nghĩa mà
 *    `ConvertIntakeToMatter::refusal()` đọc) bản ghi chưa có ghi đè còn hiệu lực và một lần gọi khác
 *    của CÙNG người ({@see IntakeRequest::sameCallerIntakes()}, cùng vai mà lần kiểm tra này dùng) đang
 *    khoá cuộc gọi lại ({@see IntakeRequest::locksRepeatCalls()}: Đỏ chưa xử lý, hoặc từ chối vì xung
 *    đột). Điều kiện "chưa có ghi đè" là để một quyết định của quản lý trên CHÍNH bản này không bị lần
 *    chạy lại kế tiếp lật lại khi lần gọi kia vẫn còn khoá; sửa danh tính, hay một khớp mới — kể cả
 *    khớp mang sang từ một lần gọi bắt đầu khoá sau ghi đè — làm ghi đè hết hiệu lực (bước trên), nên
 *    khoá trở lại. Ở đây `repeat_call_locks` vừa lưu đã gồm mọi khoá hiện có, nên vế "khoá mà lần kiểm
 *    tra gần nhất chưa thấy" của hàm đó không bao giờ đúng: một ghi đè còn sót qua bước trên che hết.
 * KHÔNG BAO GIỜ xoá ở đây — một lần chạy ra Xanh, kể cả do quản lý chạy, không xử lý được Đỏ (R1: chỉ
 * từ chối hoặc ghi đè kèm lý do). Chỉ `ResolveIntakeRedConflict` xoá nó.
 */
class CheckIntakeConflict
{
    public function handle(?User $actor, IntakeRequest $intake): ConflictCheckResult
    {
        $parties = $intake->parties()->get();
        $contactRole = $intake->conflictContactRole($parties);

        $result = app(RunConflictCheck::class)->handle(
            $this->buildParties($intake, $contactRole, $parties),
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
            'conflict_result' => [
                ...$result->toArray(),
                'fingerprint' => $fingerprint,
                // Các khoá người gọi lại lần chạy này đã thấy (fix vòng 2 của Task 4, N1) — xem docblock
                // lớp. Lưu TRƯỚC khi hỏi `isHeldByRepeatCallLock()` bên dưới.
                'repeat_call_locks' => $intake->repeatCallLocks($contactRole),
            ],
        ]);

        if ($result->matches->isNotEmpty() || $previousFingerprint !== $fingerprint) {
            $intake->fill([
                'conflict_acknowledged_by' => null,
                'conflict_acknowledged_at' => null,
                'conflict_overridden_by' => null,
                'conflict_override_reason' => null,
            ]);
        }

        // Sau bước xoá ở trên: "chưa có ghi đè còn hiệu lực" phải là trạng thái SAU lần chạy này.
        if ($result->level === ConflictLevel::Red || $intake->isHeldByRepeatCallLock($contactRole)) {
            $intake->conflict_red_pending_since ??= now();
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
    private function buildParties(IntakeRequest $intake, PartyRole $contactRole, Collection $intakeParties): Collection
    {
        $contact = (new MatterParty([
            'role' => $contactRole,
            'name' => $intake->contact_name,
            'is_our_client' => true,
        ]));
        $contact->id_number_hash = $intake->contact_id_number_hash;
        $contact->phone_normalized = $intake->contact_phone_normalized;

        return collect([
            $contact,
            ...$intakeParties->map(fn (IntakeParty $party): MatterParty => $party->toConflictParty())->all(),
        ]);
    }
}
