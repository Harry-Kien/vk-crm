<?php

namespace App\Actions\Task;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Task\Concerns\QueuesTaskNotices;
use App\Actions\Task\Concerns\ValidatesTaskInput;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Policies\TaskPolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Giao việc" (làn tk, Task 1) — một việc của một vụ, hoặc một việc chung (`$matter` rỗng).
 *
 * # Ai giao, ai nhận
 *
 * Người giao: {@see TaskPolicy::assignOnMatter()} (luật sư phụ trách/cộng sự của vụ, quản lý, quản
 * trị) hoặc {@see TaskPolicy::create()} cho việc chung (quản lý, quản trị), tài khoản còn hoạt động.
 * Người nhận: {@see TaskAssignees::qualifies()} — hỏi lại DƯỚI KHOÁ dòng của người đó, không tin ô chọn
 * của màn hình (một người bị vô hiệu hoá giữa lúc mở form và lúc bấm "Giao" bị từ chối ở đây). Không ai
 * tự giao việc cho chính mình.
 *
 * # Khoá: vụ việc trước, người nhận sau
 *
 * Thứ tự toàn cục của dự án (vụ trước, người thứ ba sau cùng — `ChangeDeadlineResponsible`). Khoá dòng
 * người nhận còn xếp hàng hai lần "Giao" trùng nhau cho cùng một người, để câu chống trùng dưới đây đọc
 * được lần trước.
 *
 * # Bấm trùng không tạo hai việc
 *
 * Một việc ĐANG CHỜ NHẬN cùng vụ, cùng người giao, cùng người nhận, cùng tiêu đề, tạo trong
 * {@see self::DUPLICATE_WINDOW_SECONDS} giây qua là CHÍNH lần bấm trước (nút gửi hai lần, hai tab): trả về
 * việc đó, không ghi thêm dòng nhật ký nào, không báo lần hai.
 *
 * # Báo người nhận
 *
 * Chuông + thư `staff.task.assigned`, xếp `->afterCommit()` ({@see QueuesTaskNotices}); job lọc
 * lại người nhận khi chạy ({@see TaskAlert}).
 */
class AssignTask
{
    use ChecksAccountActive;
    use QueuesTaskNotices;
    use ReadsWithoutPortalScope;
    use ValidatesTaskInput;

    public const DUPLICATE_WINDOW_SECONDS = 60;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        ?Matter $matter,
        User $assignee,
        string $title,
        ?string $description = null,
        ?string $dueDate = null,
        TaskPriority $priority = TaskPriority::Normal,
    ): Task {
        $title = $this->cleanTitle($title);
        $description = $this->cleanText($description, 'description', Task::DESCRIPTION_MAX_LENGTH);
        $dueDate = $this->cleanDueDate($dueDate);

        return DB::transaction(function () use ($actor, $matter, $assignee, $title, $description, $dueDate, $priority): Task {
            $lockedMatter = null;

            if ($matter !== null) {
                // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL (vụ đã huỷ không tìm thấy).
                $lockedMatter = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

                if ($lockedMatter === null) {
                    $this->refuse();
                }
            }

            if (! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            $allowed = $lockedMatter === null
                ? Gate::forUser($actor)->allows('create', Task::class)
                : Gate::forUser($actor)->allows('assignOnMatter', [Task::class, $lockedMatter]);

            if (! $allowed) {
                $this->refuse();
            }

            $lockedAssignee = User::query()->withTrashed()->whereKey($assignee->getKey())->lockForUpdate()->first();

            if ($lockedAssignee === null) {
                $this->refuseAssignee();
            }

            if ($lockedAssignee->is($actor)) {
                throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.self_assign')]]);
            }

            if (! app(TaskAssignees::class)->qualifies($lockedAssignee, $lockedMatter)) {
                $this->refuseAssignee();
            }

            $duplicate = $this->scopelessly(Task::query())
                ->when(
                    $lockedMatter === null,
                    fn ($query) => $query->whereNull('matter_id'),
                    fn ($query) => $query->where('matter_id', $lockedMatter->getKey()),
                )
                ->where('assigned_by', $actor->getKey())
                ->where('assignee_id', $lockedAssignee->getKey())
                ->where('title', $title)
                ->where('status', TaskStatus::Pending->value)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->latest('id')
                ->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            $task = $this->scopelessly(Task::query())->create([
                'matter_id' => $lockedMatter?->getKey(),
                'title' => $title,
                'description' => $description,
                'assignee_id' => $lockedAssignee->getKey(),
                'assigned_by' => $actor->getKey(),
                'due_date' => $dueDate,
                'priority' => $priority,
                'status' => TaskStatus::Pending,
                'assigned_at' => now(),
            ]);

            $activity = Audit::record('task_assigned', $task, [
                ...($lockedMatter !== null ? ['matter_id' => $lockedMatter->getKey()] : []),
                'assignee_id' => $lockedAssignee->getKey(),
                'to' => TaskStatus::Pending->value,
                'priority' => $priority->value,
                'due_date' => $dueDate,
            ], causer: $actor);

            $this->queueNotice($task, TaskAlert::ASSIGNED, $lockedAssignee->getKey(), $activity);

            return $task;
        });
    }

    private function refuse(): never
    {
        throw new AuthorizationException(__('tasks.errors.unavailable'));
    }

    private function refuseAssignee(): never
    {
        throw ValidationException::withMessages(['assignee_id' => [__('tasks.validation.assignee_ineligible')]]);
    }
}
