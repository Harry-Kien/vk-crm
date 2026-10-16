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
 * "Huỷ việc" — kèm lý do bắt buộc; việc còn mở → đã huỷ. Không xoá gì: việc đã huỷ còn trong danh sách
 * "Tất cả" và trong nhật ký. Cổng: `TaskPolicy::cancel` (người giao, luật sư phụ trách vụ, quản lý/quản
 * trị). Người đang giữ việc (chờ nhận hay đang làm) nhận một CHUÔNG, không thư: việc đó biến khỏi danh
 * sách của họ và họ cần biết vì sao. Việc đã bị trả lại thì người nhận cũ đã buông nó — không báo.
 *
 * Bấm lần hai khi việc đã huỷ (của bất kỳ ai xem được việc): trả về việc, không ghi thêm gì.
 *
 * Làn fa1 (phán quyết 7): người bấm không phải người giao (luật sư phụ trách hay quản lý huỷ việc của một người
 * khác) thì người giao gốc nhận một CHUÔNG {@see TaskAlert::CANCELLED_BY_OTHER} kèm lý do (job lọc qua
 * `forTask()`). Huỷ nhầm thì chính người huỷ, luật sư phụ trách hay quản lý "Khôi phục" được ({@see RestoreTask}).
 */
class CancelTask
{
    use OpensTask;
    use QueuesTaskNotices;
    use ValidatesTaskInput;

    public function handle(Task $task, User $actor, string $reason): Task
    {
        $reason = $this->cleanText($reason, 'reason', Task::REASON_MAX_LENGTH, required: true);

        return DB::transaction(function () use ($task, $actor, $reason): Task {
            $fresh = $this->openTask($task, $actor);

            if ($fresh->status === TaskStatus::Cancelled) {
                return $fresh;
            }

            $this->authorizeTransition($fresh, $actor, 'cancel', TaskStatus::open());

            $from = $fresh->status;

            $fresh->update([
                'status' => TaskStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $activity = Audit::record('task_cancelled', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'from' => $from->value,
                'to' => TaskStatus::Cancelled->value,
                'reason' => $reason,
            ], causer: $actor);

            if ($from !== TaskStatus::Declined) {
                $this->queueNotice($fresh, TaskAlert::CANCELLED, $fresh->assignee_id, $activity);
            }

            if ($fresh->assigned_by !== $actor->getKey() && $fresh->assigned_by !== $fresh->assignee_id) {
                $this->queueNotice($fresh, TaskAlert::CANCELLED_BY_OTHER, $fresh->assigned_by, $activity);
            }

            return $fresh;
        });
    }
}
