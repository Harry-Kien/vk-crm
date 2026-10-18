<?php

namespace App\Actions\Task;

use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Policies\TaskPolicy;
use App\Support\Audit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Khôi phục" một việc đã Huỷ (làn fa1, phán quyết 2 — kiểm tra luồng nội bộ 2026-10-16): huỷ nhầm, hay việc
 * hoá ra vẫn cần làm. Cổng: {@see TaskPolicy::restoreCancelled()} — chính người đã huỷ, luật sư phụ trách vụ, quản
 * lý/quản trị. Lý do bắt buộc.
 *
 * # Trở về đâu
 *
 * Theo trạng thái lúc bị huỷ — `from` của dòng nhật ký `task_cancelled` gần nhất ({@see Task::cancelledFrom()}):
 *
 *  - huỷ khi đang "Bị trả lại" (làn fa1, vòng sửa 1 — rà soát Task 1, m1): việc trở về "Bị trả lại" — người nhận
 *    cũ đã trả lại nó (kèm lý do, còn trên dòng: `CancelTask` không xoá `declined_at`/`decline_reason`), nên không
 *    đẩy việc lần nữa cho họ và không báo họ; việc nằm lại ở "Tôi đã giao" của người giao để giao người khác;
 *  - mọi trạng thái khác (chờ nhận, đang làm): việc trở về "Chờ nhận" với NGƯỜI NHẬN CŨ: `assigned_at` = bây giờ
 *    (một lần giao mới — đồng hồ nhắc chờ nhận chạy lại), mốc nhận/trả lại/huỷ và lý do cũ xoá trên dòng (giữ trong
 *    nhật ký: `task_cancelled` cũ và dòng `task_restored` mới — `reason`, `cancel_reason`). Người nhận cũ được hỏi
 *    lại DƯỚI KHOÁ dòng của họ ({@see TaskAssignees::qualifies()} — khoá sau vụ và việc, thứ tự toàn cục):
 *     - còn nhận được việc: chuông + thư `staff.task.restored` ({@see TaskAlert::RESTORED}) kèm lý do, sau commit;
 *     - không (đã nghỉ, rời đội ngũ, trả lại hồ sơ, không còn mở được hồ sơ): không báo họ — việc hiện "Cần giao
 *       lại" (`Task::scopeNeedsReassignment()`: cùng các vế của `qualifies()`), và người khôi phục giao lại.
 *
 * Cả hai nhánh xoá `cancelled_at`/`cancel_reason` trên dòng. Dòng `task_restored` ghi `to` là trạng thái đã về.
 *
 * # Khôi phục về chính người bấm (làn fa1, vòng sửa 2 — c4)
 *
 * Người bấm CHÍNH LÀ người nhận cũ (luật sư phụ trách giữ một việc mà trưởng phòng đã huỷ, và tự khôi phục nó) và còn
 * nhận được việc: việc về thẳng "Đang làm" (`accepted_at` = bây giờ) — khôi phục là chính họ nhận lại việc, không có ai
 * để chờ "Đã nhận", và không lời nhắc "chờ nhận" nào tự nhắc họ. Không báo chính họ; câu xong
 * (`tasks.restore.success_own`) không hứa "người nhận cũ được báo". Người giao gốc vẫn được báo (mục "Người giao gốc").
 *
 * # Người giao gốc
 *
 * Người bấm không phải người giao (luật sư phụ trách hay quản lý khôi phục việc của người khác giao — thường sau khi
 * chính họ hay một người quản lý khác đã huỷ, và người giao đã nhận {@see TaskAlert::CANCELLED_BY_OTHER}): người giao
 * gốc nhận một CHUÔNG {@see TaskAlert::RESTORED_BY_OTHER} kèm lý do (làn fa1, vòng sửa 1, m7; job lọc qua `forTask()`
 * — người giao không còn xem được việc thì không nhận gì).
 *
 * Vụ đã kết thúc: từ chối bằng một câu rõ ràng (`tasks.restore.errors.matter_closed`); vụ đã huỷ: `openTask()`
 * từ chối chung. Bấm lần hai (việc đã về "Chờ nhận" hay "Bị trả lại"): lời từ chối trạng thái.
 */
class RestoreTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    public function handle(Task $task, User $actor, string $reason): Task
    {
        $reason = $this->cleanText($reason, 'reason', Task::REASON_MAX_LENGTH);

        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $fresh = $this->openTask($task, $actor);

            $this->authorizeTransition($fresh, $actor, 'restoreCancelled', [TaskStatus::Cancelled]);

            if ($fresh->matter !== null && ! $fresh->matter->isOpen()) {
                throw new DomainException(__('tasks.restore.errors.matter_closed'));
            }

            // Lý do hỏi SAU câu từ chối của vụ đã kết thúc: trên vụ đó màn hình không mở hộp thoại (không ô lý do),
            // và người bấm cần nghe vì sao không làm được, không phải "hãy ghi lý do".
            if ($reason === null) {
                throw ValidationException::withMessages(['reason' => [__('tasks.validation.reason_required')]]);
            }

            $cancelReason = $fresh->cancel_reason;
            $wasDeclined = $fresh->cancelledFrom() === TaskStatus::Declined;
            $qualifies = false;

            if ($wasDeclined) {
                $fresh->update([
                    'status' => TaskStatus::Declined,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                ]);
            } else {
                $assignee = User::query()->withTrashed()->whereKey($fresh->assignee_id)->lockForUpdate()->first();
                $qualifies = $assignee !== null && app(TaskAssignees::class)->qualifies($assignee, $fresh->matter);
                // Xem docblock lớp, mục "Khôi phục về chính người bấm".
                $toSelf = $qualifies && $fresh->assignee_id === $actor->getKey();

                $fresh->update([
                    'status' => $toSelf ? TaskStatus::Accepted : TaskStatus::Pending,
                    'assigned_at' => now(),
                    'accepted_at' => $toSelf ? now() : null,
                    'declined_at' => null,
                    'decline_reason' => null,
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                ]);
            }

            $activity = Audit::record('task_restored', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => TaskStatus::Cancelled->value,
                'to' => $fresh->status->value,
                'assignee_id' => $fresh->assignee_id,
                'reason' => $reason,
                'cancel_reason' => $cancelReason,
                ...($wasDeclined ? [] : ['needs_reassignment' => ! $qualifies]),
            ], causer: $actor);

            if ($qualifies && $fresh->assignee_id !== $actor->getKey()) {
                $this->queueNotice($fresh, TaskAlert::RESTORED, $fresh->assignee_id, $activity);
            }

            // Xem docblock lớp, mục "Người giao gốc".
            if ($fresh->assigned_by !== $actor->getKey() && $fresh->assigned_by !== $fresh->assignee_id) {
                $this->queueNotice($fresh, TaskAlert::RESTORED_BY_OTHER, $fresh->assigned_by, $activity);
            }

            return $fresh;
        });
    }
}
