<?php

namespace App\Actions\Task;

use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Sửa" một việc còn mở — tiêu đề, mô tả, hạn, mức ưu tiên. Người quản lý được việc (người giao, luật
 * sư phụ trách vụ, quản lý/quản trị — `TaskPolicy::update`); người nhận không tự sửa việc của mình. Đổi
 * người nhận là {@see ReassignTask}, không phải ở đây.
 *
 * `$expectedUpdatedAt` là `updated_at` lúc màn hình mở form: dòng đã đổi từ lúc đó (người nhận vừa bấm
 * "Đã nhận", người khác vừa sửa) thì từ chối, để một tab mở từ trước không ghi đè lần đổi của người khác
 * — cùng thành ngữ `UpdateDeadline`. Rỗng: không so (gọi từ nơi không có form).
 *
 * Hạn đã qua chỉ bị từ chối khi người sửa ĐỔI hạn sang một ngày đã qua; giữ nguyên hạn cũ của một việc đã
 * quá hạn thì được. Không báo ai: người nhận thấy bản mới ở danh sách của mình.
 */
class UpdateTask
{
    use OpensTask;
    use ValidatesTaskInput;

    public function handle(
        Task $task,
        User $actor,
        string $title,
        ?string $description,
        ?string $dueDate,
        TaskPriority $priority,
        ?string $expectedUpdatedAt,
    ): Task {
        $title = $this->cleanTitle($title);
        $description = $this->cleanText($description, 'description', Task::DESCRIPTION_MAX_LENGTH);

        return DB::transaction(function () use ($task, $actor, $title, $description, $dueDate, $priority, $expectedUpdatedAt): Task {
            $fresh = $this->openTask($task, $actor);

            $this->authorizeTransition($fresh, $actor, 'update', TaskStatus::open());

            if ($expectedUpdatedAt !== null && $fresh->updated_at?->toDateTimeString() !== $expectedUpdatedAt) {
                throw ValidationException::withMessages(['title' => [__('tasks.validation.changed_meanwhile')]]);
            }

            $fresh->fill([
                'title' => $title,
                'description' => $description,
                'due_date' => $this->cleanDueDate($dueDate, $fresh->due_date?->toDateString()),
                'priority' => $priority,
            ]);

            $changed = array_keys($fresh->getDirty());

            if ($changed === []) {
                return $fresh;
            }

            $fresh->save();

            Audit::record('task_updated', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'changed' => $changed,
            ], causer: $actor);

            return $fresh;
        });
    }
}
