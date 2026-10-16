<?php

namespace App\Actions\Task;

use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Giao lại" — chuyển một việc còn mở sang người khác: việc bị trả lại, người nhận đã nghỉ/bị vô hiệu
 * hay rời đội ngũ vụ, hoặc người giao/luật sư phụ trách/quản lý chủ động chuyển một việc đang chờ nhận
 * hay đang làm (người đang giữ quá tải, vắng mặt). Việc trở về "Chờ nhận" cho người mới (mốc nhận, mốc trả lại và lý do cũ xoá — lịch sử nằm ở
 * nhật ký, dòng `task_reassigned` và `task_declined`); người giao ban đầu (`assigned_by`) giữ nguyên, để
 * việc vẫn nằm ở "Tôi đã giao" của họ. Cổng: `TaskPolicy::reassign` (người giao, luật sư phụ trách vụ,
 * quản lý/quản trị).
 *
 * Người mới qua {@see TaskAssignees::qualifies()} DƯỚI KHOÁ dòng của họ (khoá sau vụ và việc — thứ tự
 * toàn cục). Giao lại cho CHÍNH người đang giữ chỉ được khi họ đã trả lại (sau khi hai bên trao đổi);
 * việc đang chờ nhận/đang làm mà chọn lại đúng người đó thì không có gì để chuyển.
 *
 * Người mới nhận chuông + thư `staff.task.assigned`. Người cũ (vòng sửa 1, I1): việc biến khỏi danh
 * sách của họ — chỉ người nhận HIỆN TẠI xem được việc — nên khi họ còn GIỮ nó (chờ nhận hay đang làm) họ
 * nhận MỘT chuông chung `TaskAlert::REASSIGNED_AWAY` (không tiêu đề, không mã vụ, không thư), để không
 * làm tiếp một việc đã sang tay người khác. Họ đã trả lại thì đã buông việc: không báo. Họ đã nghỉ/bị vô
 * hiệu: job bỏ họ (`ResolveStaffRecipients::forFormerTaskHolder()`).
 */
class ReassignTask
{
    use OpensTask;
    use QueuesTaskNotices;

    public function handle(Task $task, User $actor, User $newAssignee): Task
    {
        return DB::transaction(function () use ($task, $actor, $newAssignee): Task {
            $fresh = $this->openTask($task, $actor);

            $this->authorizeTransition($fresh, $actor, 'reassign', TaskStatus::open());

            $locked = User::query()->withTrashed()->whereKey($newAssignee->getKey())->lockForUpdate()->first();

            if ($locked !== null && $locked->is($actor)) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.self_assign')]]);
            }

            if ($locked === null || ! app(TaskAssignees::class)->qualifies($locked, $fresh->matter)) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.assignee_ineligible')]]);
            }

            if ($locked->getKey() === $fresh->assignee_id && $fresh->status !== TaskStatus::Declined) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.same_assignee')]]);
            }

            $from = $fresh->status;
            $previous = $fresh->assignee_id;

            $fresh->update([
                'assignee_id' => $locked->getKey(),
                'status' => TaskStatus::Pending,
                'assigned_at' => now(),
                'accepted_at' => null,
                'declined_at' => null,
                'decline_reason' => null,
            ]);

            $activity = Audit::record('task_reassigned', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => $from->value,
                'to' => TaskStatus::Pending->value,
                'previous_assignee_id' => $previous,
                'assignee_id' => $locked->getKey(),
            ], causer: $actor);

            $this->queueNotice($fresh, TaskAlert::ASSIGNED, $locked->getKey(), $activity);

            // Làn tk, Task 2 (rà soát Task 1, r1): người giao lại chính là người đang giữ (một quản lý giao
            // cho luật sư phụ trách, rồi luật sư đó chuyển tiếp) thì không tự báo mình.
            if ($from !== TaskStatus::Declined && $previous !== $actor->getKey()) {
                $this->queueNotice($fresh, TaskAlert::REASSIGNED_AWAY, $previous, $activity);
            }

            return $fresh;
        });
    }
}
