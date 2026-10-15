<?php

namespace App\Policies;

use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/**
 * Việc giao nội bộ (làn tk, Task 1). Đọc thẳng thuộc tính của bản ghi, KHÔNG chạy lại
 * `Task::scopeVisibleTo()` — hai tầng nói cùng một luật (docblock `App\Models\Task`), `TaskAccessTest`
 * ghim chúng khớp nhau. Điều kiện "xem được vụ" là của `MatterPolicy::view` (qua
 * {@see ChecksMatterAccess}), không viết lại: vụ `restricted` tự chỉ cho luật sư phụ trách và admin qua.
 *
 * Tài khoản cổng khách: `false` ở mọi khả năng.
 *
 * Policy KHÔNG hỏi `is_active`: tài khoản bị vô hiệu không vào được panel, và mọi Action tự hỏi lại
 * (`ChecksAccountActive`) dưới khoá dòng.
 */
class TaskPolicy
{
    use ChecksMatterAccess;

    /** Trang "Công việc" mở cho mọi nhân sự; danh sách tự lọc theo `visibleTo()`. */
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, Task $task): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($task->matter_id === null) {
            return $this->involved($user, $task) || Task::isSupervisor($user);
        }

        // Quan hệ `matter` bỏ vụ đã xoá mềm (huỷ): việc của vụ đã huỷ không ai thấy — cùng câu với
        // `whereHas('matter', …)` của scope.
        $matter = $task->matter;

        if ($matter === null || ! $this->canSeeMatter($user, $matter)) {
            return false;
        }

        return $this->involved($user, $task)
            || Task::isSupervisor($user)
            || $matter->lead_lawyer_id === $user->getKey();
    }

    /** Giao một VIỆC CHUNG (không thuộc vụ nào): chỉ quản lý/quản trị. */
    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && Task::isSupervisor($user);
    }

    /**
     * Giao việc TRÊN MỘT VỤ: vụ chưa huỷ, đang mở; người giao xem được vụ và có `matter.update`; và là
     * quản lý/quản trị, hoặc ngồi ghế luật sư phụ trách/luật sư cộng sự của chính vụ đó mà không mang vai
     * trợ lý (cùng lý lẽ `MatterPolicy::manageTeam()`: một luật sư bị đổi chức danh sang trợ lý không
     * giữ quyền của ghế cũ). Gọi qua `Gate::allows('assignOnMatter', [Task::class, $matter])`.
     */
    public function assignOnMatter(User|ClientUser $user, Matter $matter): bool
    {
        if (! $user instanceof User || $matter->trashed() || ! $matter->isOpen()) {
            return false;
        }

        if (! $this->canSeeMatter($user, $matter) || ! $user->can(Permission::MatterUpdate->value)) {
            return false;
        }

        if (Task::isSupervisor($user)) {
            return true;
        }

        if ($user->hasRole(Role::Assistant->value)) {
            return false;
        }

        return $matter->lead_lawyer_id === $user->getKey()
            || $matter->team()
                ->whereKey($user->getKey())
                ->wherePivotIn('role_in_matter', [MatterRole::Lead->value, MatterRole::Associate->value])
                ->exists();
    }

    /** "Đã nhận": chỉ người nhận, chỉ khi việc đang chờ nhận. */
    public function accept(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) && $task->status === TaskStatus::Pending;
    }

    /** "Trả lại" (kèm lý do): người nhận, khi việc chờ nhận hoặc đang làm. */
    public function decline(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task)
            && in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true);
    }

    /** "Hoàn thành": người nhận, khi việc chờ nhận (nhận và xong một lượt) hoặc đang làm. */
    public function complete(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task)
            && in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true);
    }

    /** "Sửa": người quản lý được việc ({@see self::manages()}), việc còn mở. */
    public function update(User|ClientUser $user, Task $task): bool
    {
        return $this->manages($user, $task) && $task->isOpen();
    }

    /** "Giao lại" cho người khác: như `update`. */
    public function reassign(User|ClientUser $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /** "Huỷ việc" (kèm lý do): như `update`. */
    public function cancel(User|ClientUser $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /** Không ai xoá một việc: huỷ là đường duy nhất, và để lại dấu vết. */
    public function delete(User|ClientUser $user, Task $task): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, Task $task): bool
    {
        return false;
    }

    /**
     * Người QUẢN LÝ được một việc: thấy việc đó, VÀ là người giao, luật sư phụ trách hiện tại của vụ, hoặc
     * quản lý/quản trị. Người nhận không tự sửa/giao lại/huỷ việc của mình — họ "Trả lại".
     */
    private function manages(User|ClientUser $user, Task $task): bool
    {
        if (! $user instanceof User || ! $this->view($user, $task)) {
            return false;
        }

        return $task->assigned_by === $user->getKey()
            || Task::isSupervisor($user)
            || ($task->matter_id !== null && $task->matter?->lead_lawyer_id === $user->getKey());
    }

    private function isAssignee(User|ClientUser $user, Task $task): bool
    {
        return $user instanceof User
            && $task->assignee_id === $user->getKey()
            && $this->view($user, $task);
    }

    private function involved(User $user, Task $task): bool
    {
        return $task->assignee_id === $user->getKey() || $task->assigned_by === $user->getKey();
    }
}
