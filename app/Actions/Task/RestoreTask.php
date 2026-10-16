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
 * hoá ra vẫn cần làm. Cổng: {@see TaskPolicy::restore()} — chính người đã huỷ, luật sư phụ trách vụ, quản lý/quản
 * trị. Lý do bắt buộc.
 *
 * Việc trở về "Chờ nhận" với NGƯỜI NHẬN CŨ: `assigned_at` = bây giờ (một lần giao mới — đồng hồ nhắc chờ nhận
 * chạy lại), mốc nhận/trả lại/huỷ và lý do cũ xoá trên dòng (giữ trong nhật ký: `task_cancelled` cũ và dòng
 * `task_restored` mới — `reason`, `cancel_reason`). Người nhận cũ được hỏi lại DƯỚI KHOÁ dòng của họ
 * ({@see TaskAssignees::qualifies()} — khoá sau vụ và việc, thứ tự toàn cục):
 *
 *  - còn nhận được việc: chuông + thư `staff.task.restored` ({@see TaskAlert::RESTORED}) kèm lý do, sau commit;
 *  - không (đã nghỉ, rời đội ngũ, trả lại hồ sơ, không còn mở được hồ sơ): không báo họ — việc hiện "Cần giao lại"
 *    (`Task::scopeNeedsReassignment()`: cùng các vế của `qualifies()`), và người khôi phục giao lại.
 *
 * Vụ đã kết thúc: từ chối bằng một câu rõ ràng (`tasks.restore.errors.matter_closed`); vụ đã huỷ: `openTask()`
 * từ chối chung. Bấm lần hai (việc đã về "Chờ nhận"): lời từ chối trạng thái.
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

            $this->authorizeTransition($fresh, $actor, 'restore', [TaskStatus::Cancelled]);

            if ($fresh->matter !== null && ! $fresh->matter->isOpen()) {
                throw new DomainException(__('tasks.restore.errors.matter_closed'));
            }

            // Lý do hỏi SAU câu từ chối của vụ đã kết thúc: trên vụ đó màn hình không mở hộp thoại (không ô lý do),
            // và người bấm cần nghe vì sao không làm được, không phải "hãy ghi lý do".
            if ($reason === null) {
                throw ValidationException::withMessages(['reason' => [__('tasks.validation.reason_required')]]);
            }

            $assignee = User::query()->withTrashed()->whereKey($fresh->assignee_id)->lockForUpdate()->first();
            $qualifies = $assignee !== null && app(TaskAssignees::class)->qualifies($assignee, $fresh->matter);
            $cancelReason = $fresh->cancel_reason;

            $fresh->update([
                'status' => TaskStatus::Pending,
                'assigned_at' => now(),
                'accepted_at' => null,
                'declined_at' => null,
                'decline_reason' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ]);

            $activity = Audit::record('task_restored', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => TaskStatus::Cancelled->value,
                'to' => TaskStatus::Pending->value,
                'assignee_id' => $fresh->assignee_id,
                'reason' => $reason,
                'cancel_reason' => $cancelReason,
                'needs_reassignment' => ! $qualifies,
            ], causer: $actor);

            if ($qualifies && $fresh->assignee_id !== $actor->getKey()) {
                $this->queueNotice($fresh, TaskAlert::RESTORED, $fresh->assignee_id, $activity);
            }

            return $fresh;
        });
    }
}
