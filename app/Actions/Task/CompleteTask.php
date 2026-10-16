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
 * "Hoàn thành" — người nhận báo xong, kèm ghi chú tuỳ chọn: chờ nhận hoặc đang làm → hoàn thành. Một
 * việc nhỏ làm xong ngay không bắt bấm "Đã nhận" trước: hoàn thành thẳng từ "Chờ nhận" ghi luôn mốc
 * nhận (`accepted_at`). Người giao nhận chuông + thư `staff.task.completed`.
 *
 * Bấm lần hai khi việc đã "Hoàn thành" bởi chính người này: trả về việc, không ghi thêm gì.
 */
class CompleteTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    public function handle(Task $task, User $actor, ?string $note): Task
    {
        $note = $this->cleanText($note, 'note', Task::NOTE_MAX_LENGTH);

        return DB::transaction(function () use ($task, $actor, $note): Task {
            $fresh = $this->openTask($task, $actor);

            if ($fresh->status === TaskStatus::Completed && $fresh->assignee_id === $actor->getKey()) {
                return $fresh;
            }

            $this->authorizeTransition($fresh, $actor, 'complete', [TaskStatus::Pending, TaskStatus::Accepted]);

            $from = $fresh->status;

            $fresh->update([
                'status' => TaskStatus::Completed,
                'accepted_at' => $fresh->accepted_at ?? now(),
                'completed_at' => now(),
                'completion_note' => $note,
            ]);

            $activity = Audit::record('task_completed', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => $from->value,
                'to' => TaskStatus::Completed->value,
            ], causer: $actor);

            $this->queueNotice($fresh, TaskAlert::COMPLETED, $fresh->assigned_by, $activity);

            return $fresh;
        });
    }
}
