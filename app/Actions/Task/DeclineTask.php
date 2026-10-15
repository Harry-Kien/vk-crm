<?php

namespace App\Actions\Task;

use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * "Trả lại" — người nhận trả việc về cho người giao, BẮT BUỘC kèm lý do (quá tải, xung đột lợi ích,
 * thiếu thông tin…): chờ nhận hoặc đang làm → bị trả lại. Việc không tự chuyển cho ai: nó nằm ở "Tôi đã
 * giao" của người giao (và ở danh sách của quản lý) cho tới khi được giao lại hoặc huỷ. Người giao nhận
 * chuông + thư `staff.task.declined` kèm lý do.
 *
 * Bấm lần hai khi việc đã "Bị trả lại" bởi chính người này: trả về việc, không ghi thêm gì.
 */
class DeclineTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    public function handle(Task $task, User $actor, string $reason): Task
    {
        $reason = $this->cleanText($reason, 'reason', Task::REASON_MAX_LENGTH, required: true);

        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $fresh = $this->openTask($task, $actor);

            if ($fresh->status === TaskStatus::Declined && $fresh->assignee_id === $actor->getKey()) {
                return $fresh;
            }

            $this->authorizeTransition($fresh, $actor, 'decline', [TaskStatus::Pending, TaskStatus::Accepted]);

            $from = $fresh->status;

            $fresh->update([
                'status' => TaskStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => $reason,
            ]);

            $activity = Audit::record('task_declined', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => $from->value,
                'to' => TaskStatus::Declined->value,
                'reason' => $reason,
            ], causer: $actor);

            $this->queueNotice($fresh, TaskAlert::DECLINED, $fresh->assigned_by, $activity);

            return $fresh;
        });
    }
}
