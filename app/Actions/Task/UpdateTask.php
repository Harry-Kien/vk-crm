<?php

namespace App\Actions\Task;

use App\Actions\Task\Concerns\OpensTask;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\Audit;
use App\Support\Tasks\TaskChangeSummary;
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
 * quá hạn thì được.
 *
 * # Nhật ký và lời báo (làn fa1, phán quyết 1 — kiểm tra luồng nội bộ 2026-10-16)
 *
 * Dòng `task_updated` ghi `changed` (tên mọi cột đã đổi) và — cho hạn, ưu tiên, tiêu đề — giá trị cũ (`old`) và
 * mới (`new`), để sau này đọc được "hạn 30/10 → 23/10, bởi ai". Mô tả chỉ có tên trong `changed`: nội dung dài
 * và nằm ở chính việc.
 *
 * Việc đang được GIỮ (chờ nhận hay đang làm) và người sửa không phải chính người giữ: người giữ nhận chuông +
 * thư `staff.task.updated` ({@see TaskAlert::UPDATED}) nêu điều đã đổi ({@see TaskChangeSummary}), xếp sau commit
 * ({@see QueuesTaskNotices}; khoá chống trùng là id dòng nhật ký; job lọc lại người nhận bằng
 * `ResolveStaffRecipients::forTask()` — vụ hạn chế không tới ai không mở được vụ). Việc đã bị trả lại thì người
 * nhận cũ đã buông nó: không báo. Không đổi gì: không ghi, không báo.
 *
 * # Người giữ không còn giữ được việc (làn fa1, vòng sửa 2 — c2)
 *
 * Như `ReopenTask` (vòng sửa 1, m2): người giữ từ đó đã nghỉ, rời đội ngũ, sang "người theo dõi", trả lại hồ sơ hay
 * không còn mở được hồ sơ thì không được báo — hỏi {@see TaskAssignees::qualifies()} dưới khoá dòng của họ. Người theo
 * dõi vẫn xem được vụ nên bộ lọc người nhận của job (`forTask()`) không tự chặn được họ. Việc hiện "Cần giao lại", và
 * câu xong của màn hình nói điều đó (`tasks.actions.edit_success_needs_reassignment`), không hứa "họ được báo".
 *
 * # Người giao được báo (sửa sau kiểm toán 2026-10-21, mục 2)
 *
 * Người sửa không phải người giao (luật sư phụ trách hay quản lý sửa việc một người khác giao): người giao nhận MỘT
 * chuông {@see TaskAlert::UPDATED_BY_OTHER} nêu điều đã đổi — không thư; job lọc qua `forTask()` (người giao đã nghỉ
 * hay không còn mở được hồ sơ thì không ai). Việc riêng (người giao = người giữ) mà người giữ vừa được báo "đã sửa" thì
 * không có lời thứ hai. Người nhận một việc người khác giao không tự sửa được nó nữa (`TaskPolicy::manages()`).
 */
class UpdateTask
{
    use OpensTask;
    use QueuesTaskNotices;
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

            $before = self::tracked($fresh);

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

            $after = self::tracked($fresh);
            $moved = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            $activity = Audit::record('task_updated', $fresh, [
                ...($fresh->matter_id !== null ? ['matter_id' => $fresh->matter_id] : []),
                'changed' => $changed,
                ...($moved !== [] ? [
                    'old' => array_intersect_key($before, array_flip($moved)),
                    'new' => array_intersect_key($after, array_flip($moved)),
                ] : []),
            ], causer: $actor);

            $holderNotified = false;

            if (in_array($fresh->status, [TaskStatus::Pending, TaskStatus::Accepted], true) && $fresh->assignee_id !== $actor->getKey()) {
                // Xem docblock lớp, mục "Người giữ không còn giữ được việc" — hỏi dưới khoá dòng của họ (sau vụ và việc).
                $holder = User::query()->withTrashed()->whereKey($fresh->assignee_id)->lockForUpdate()->first();

                if ($holder !== null && app(TaskAssignees::class)->qualifies($holder, $fresh->matter)) {
                    $this->queueNotice($fresh, TaskAlert::UPDATED, $fresh->assignee_id, $activity);
                    $holderNotified = true;
                }
            }

            // Xem docblock lớp, mục "Người giao được báo" — việc riêng mà người giữ vừa được báo: không lời thứ hai.
            if ($fresh->assigned_by !== $actor->getKey() && ! ($holderNotified && $fresh->assigned_by === $fresh->assignee_id)) {
                $this->queueNotice($fresh, TaskAlert::UPDATED_BY_OTHER, $fresh->assigned_by, $activity);
            }

            return $fresh;
        });
    }

    /**
     * Ba cột có giá trị cũ/mới trong dòng `task_updated` (mô tả thì không — docblock lớp), dạng chữ: tiêu đề, hạn
     * (`Y-m-d` hay `null`), ưu tiên (mã), theo thứ tự đó.
     *
     * @return array<string, string|null>
     */
    private static function tracked(Task $task): array
    {
        return [
            'title' => $task->title,
            'due_date' => $task->due_date?->toDateString(),
            'priority' => $task->priority->value,
        ];
    }
}
