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

/**
 * "Đã nhận" — một cú bấm của người nhận (làn tk, Task 1): chờ nhận → đang làm. Người giao nhận chuông
 * + thư `staff.task.accepted`.
 *
 * Bấm lần hai (hay hai tab cùng bấm) khi việc ĐÃ ở "Đang làm" của chính người này: trả về việc, không ghi
 * gì thêm, không báo lần hai — người dùng thấy đúng điều họ muốn. Việc đã sang trạng thái khác (bị huỷ,
 * giao lại…) thì từ chối và nói trạng thái hiện tại ({@see OpensTask::authorizeTransition()}).
 */
class AcceptTask
{
    use OpensTask;
    use QueuesTaskNotices;

    public function handle(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $fresh = $this->openTask($task, $actor);

            if ($fresh->status === TaskStatus::Accepted && $fresh->assignee_id === $actor->getKey()) {
                return $fresh;
            }

            $this->authorizeTransition($fresh, $actor, 'accept', [TaskStatus::Pending]);

            $fresh->update([
                'status' => TaskStatus::Accepted,
                'accepted_at' => now(),
            ]);

            $activity = Audit::record('task_accepted', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => TaskStatus::Pending->value,
                'to' => TaskStatus::Accepted->value,
            ], causer: $actor);

            $this->queueNotice($fresh, TaskAlert::ACCEPTED, $fresh->assigned_by, $activity);

            return $fresh;
        });
    }
}
