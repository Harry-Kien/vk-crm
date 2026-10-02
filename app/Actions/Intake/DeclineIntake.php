<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Văn phòng từ chối một lần liên hệ, có lý do (M10 R8). Câu trả lời ra ngoài LUÔN là "văn phòng xin
 * phép không nhận vụ việc này", không kèm giải thích (`docs/QUY-TRINH.md`) — màn hình nói điều đó;
 * Action này chỉ ghi quyết định.
 *
 * **Hai loại từ chối:**
 *  - Lý do thường (ngoài lĩnh vực, khách không phù hợp…): ai sửa được bản ghi đều làm được
 *    (`IntakeRequestPolicy::update`).
 *  - **Vì xung đột lợi ích** (`$forConflict`): chỉ quản lý hoặc admin (`IntakeRequestPolicy::
 *    resolveConflict` — CÙNG định nghĩa "ai xử lý Đỏ" với ghi đè, `ConflictOverride::allowedFor()`),
 *    và chỉ người có `intake.viewAny` thấy lý do (`viewConflictReason`); người khác thấy "Văn phòng từ
 *    chối". Nói lý do là tiết lộ có tồn tại một khách hàng khác.
 *
 * **Từ chối vì xung đột KHÔNG xoá `conflict_red_pending_since`** (phán quyết controller, fix vòng 1 của
 * Task 2): từ chối là không nhận việc, không phải cho nghe chuyện. Và `decline_reason_is_conflict` làm
 * bản ghi khoá các lần gọi LẠI của cùng người ({@see IntakeRequest::locksRepeatCalls()}), nên Action
 * chạy dưới khoá `conflict-check` — cùng khoá với mọi lần kiểm tra — để một lần gọi lại ghi đồng thời
 * thấy hoặc không thấy quyết định này một cách tuần tự, không lẫn giữa chừng.
 *
 * **Câu chuyện của CHÍNH bản ghi đóng lại** sau khi từ chối, vì bất kỳ lý do nào (quyết định của Task
 * 3): `IntakeSummaryGate` trả `IntakeSummaryBlocker::Declined`, nhãn trung tính ("văn phòng đã từ chối
 * bản ghi này") để người không được biết lý do cũng không đọc ra được đó là xung đột.
 *
 * Lý do: bắt buộc, đã trim, tối đa 2000 ký tự (cùng trần với lý do ghi đè). Chỉ từ chối được bản ghi
 * còn mở (`new`, `contacted`, `consulting`, `quoted`); bản đã xong việc
 * ({@see IntakeRequest::isClosedToChanges()}) bị từ chối. Câu đầu tiên của transaction là lần đọc có
 * khoá dòng bản ghi. Nhật ký `intake_declined` chỉ mang trạng thái trước đó — KHÔNG lý do, KHÔNG cờ
 * xung đột (R8: `ActivityOwningMatter` cho mọi người có `auditLog.view` đọc dòng này; cột của bản ghi
 * mới là nơi giữ quyết định, sau `viewConflictReason`). `retention_until` (R7b) là việc của Task 7.
 */
class DeclineIntake
{
    use HoldsConflictCheckLock;

    private const REASON_MAX_LENGTH = 2000;

    public function handle(User $actor, IntakeRequest $intake, string $reason, bool $forConflict = false): IntakeRequest
    {
        Gate::forUser($actor)->authorize($forConflict ? 'resolveConflict' : 'update', $intake);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['decline_reason' => [__('intake.errors.decline_reason_required')]]);
        }

        if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw ValidationException::withMessages(['decline_reason' => [__('intake.errors.decline_reason_too_long')]]);
        }

        return $this->underConflictCheckLock(fn (): IntakeRequest => DB::transaction(function () use ($actor, $intake, $reason, $forConflict): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($locked->isClosedToChanges() || ! in_array($from, ChangeIntakeStatus::OPEN, true)) {
                throw ValidationException::withMessages(['decline_reason' => [__('intake.errors.decline_not_allowed', [
                    'status' => $from->label(),
                ])]]);
            }

            $locked->fill([
                'status' => IntakeStatus::Declined,
                'decline_reason' => $reason,
                'decline_reason_is_conflict' => $forConflict,
            ]);
            $locked->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_declined', $locked, ['from' => $from->value], $actor);

            return $locked;
        }));
    }
}
