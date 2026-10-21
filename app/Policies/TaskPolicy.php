<?php

namespace App\Policies;

use App\Actions\Task\TaskAssignees;
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
 * (`ChecksAccountActive`) dưới khoá dòng. Lần hỏi "còn giữ được việc" của `accept`/`complete` vì vậy dùng
 * `TaskAssignees::holdsSeat()` — vế "của vụ" của luật người nhận, không vế `is_active`.
 */
class TaskPolicy
{
    use ChecksMatterAccess;

    /** Người đã hoàn thành một việc tự "Mở lại" được trong ngần này giờ (bấm nhầm) — {@see self::reopen()}. */
    public const REOPEN_OWN_HOURS = 24;

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

    /**
     * Giao một VIỆC CHUNG (không thuộc vụ nào) — làn fa1, phán quyết 3 (kiểm tra luồng nội bộ 2026-10-16; trước đó
     * chỉ quản lý/quản trị): mọi nhân sự có `matter.view` — luật sư, trợ lý, trưởng phòng, quản trị viên — để
     * luật sư giao chuyên viên việc ngoài vụ (tra cứu trước buổi tư vấn, cập nhật mẫu hợp đồng) mà người giao vẫn
     * là chính họ. Kế toán và nhân viên kinh doanh (không `matter.view`) nhận việc chung, không giao. Người nhận:
     * mọi nhân sự đang hoạt động (`TaskAssignees`). Cũng là cổng của nút "Giao việc" trên trang "Công việc".
     */
    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterView->value);
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

    /**
     * "Đã nhận": chỉ người nhận, chỉ khi việc đang chờ nhận, và chỉ khi họ còn GIỮ ĐƯỢC việc
     * ({@see self::stillHolds()}).
     */
    public function accept(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) && $task->status === TaskStatus::Pending && $this->stillHolds($user, $task);
    }

    /**
     * "Trả lại" (kèm lý do): người nhận, khi việc chờ nhận hoặc đang làm — kể cả người không còn giữ được
     * việc (đã sang "người theo dõi"…): trả việc về cho người giao là đúng việc họ nên làm.
     *
     * Trừ khi người nhận CHÍNH là người giao (gộp integ-fa — việc riêng sau một lần nhận thay, `ReassignTask`): không có
     * ai để trả về; họ "Giao lại" hay "Huỷ" bằng quyền người giao ({@see self::manages()}).
     */
    public function decline(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task)
            && $task->assigned_by !== $user->getKey()
            && in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true);
    }

    /**
     * "Hoàn thành": người nhận, khi việc chờ nhận (nhận và xong một lượt) hoặc đang làm, và còn GIỮ ĐƯỢC
     * việc ({@see self::stillHolds()}).
     */
    public function complete(User|ClientUser $user, Task $task): bool
    {
        return $this->isAssignee($user, $task)
            && in_array($task->status, [TaskStatus::Pending, TaskStatus::Accepted], true)
            && $this->stillHolds($user, $task);
    }

    /**
     * "Sửa": người quản lý được việc ({@see self::manages()}), việc còn mở, và — việc của một vụ — vụ chưa
     * kết thúc (làn tk, Task 2; rà soát Task 1, m6): cùng luật "giao việc trên một vụ" của
     * {@see self::assignOnMatter()}. Vụ đã kết thúc thì việc còn mở chỉ còn một đường: huỷ.
     */
    public function update(User|ClientUser $user, Task $task): bool
    {
        return $this->manages($user, $task) && $task->isOpen()
            && ($task->matter_id === null || $task->matter?->isOpen() === true);
    }

    /** "Giao lại" cho người khác: như `update` — không giao lại việc của một vụ đã kết thúc. */
    public function reassign(User|ClientUser $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /** "Huỷ việc" (kèm lý do): người quản lý được việc, việc còn mở — kể cả trên vụ đã kết thúc. */
    public function cancel(User|ClientUser $user, Task $task): bool
    {
        return $this->manages($user, $task) && $task->isOpen();
    }

    /**
     * "Mở lại" một việc đã Hoàn thành (làn fa1, phán quyết 2): người quản lý được việc ({@see self::manages()} —
     * người giao, luật sư phụ trách vụ, quản lý/quản trị; cùng trục với "Giao lại") — đó là "yêu cầu làm lại", bắt
     * buộc lý do; hoặc chính người đã hoàn thành ({@see self::completedRecentlyBy()}: trong
     * {@see self::REOPEN_OWN_HOURS} giờ đầu, sửa một lần bấm nhầm).
     *
     * KHÔNG hỏi vụ còn mở: trên vụ đã kết thúc nút vẫn hiện nhưng bị khoá kèm câu giải thích, và `ReopenTask` từ
     * chối bằng chính câu đó (một màn hình mở từ trước khi vụ kết thúc).
     */
    public function reopen(User|ClientUser $user, Task $task): bool
    {
        if (! $user instanceof User || $task->status !== TaskStatus::Completed) {
            return false;
        }

        return $this->manages($user, $task) || $this->completedRecentlyBy($user, $task);
    }

    /**
     * `$user` là người đã hoàn thành `$task` — người nhận: việc đã hoàn thành không giao lại được, nên `assignee_id`
     * là người đã bấm "Hoàn thành" — và còn trong {@see self::REOPEN_OWN_HOURS} giờ đầu, khi họ còn giữ được việc
     * ({@see self::stillHolds()}). Vế "bấm nhầm" của {@see self::reopen()}: lý do không bắt buộc (`ReopenTask`).
     */
    public function completedRecentlyBy(User|ClientUser $user, Task $task): bool
    {
        return $user instanceof User
            && $task->status === TaskStatus::Completed
            && $this->isAssignee($user, $task)
            && $task->completed_at !== null
            && $task->completed_at->greaterThan(now()->subHours(self::REOPEN_OWN_HOURS))
            && $this->stillHolds($user, $task);
    }

    /**
     * "Khôi phục" một việc đã Huỷ (làn fa1, phán quyết 2): chính người đã huỷ ({@see Task::cancelledBy()} — dòng
     * nhật ký `task_cancelled` gần nhất của việc), luật sư phụ trách hiện tại của vụ, hoặc quản lý/quản trị; người
     * đó phải còn xem được việc. Người giao mà KHÔNG phải người đã huỷ (luật sư phụ trách hay quản lý huỷ việc của
     * họ) thì không: họ đã được báo việc bị huỷ, kèm lý do. Như {@see self::reopen()}, không hỏi vụ còn mở.
     *
     * Tên `restoreCancelled`, KHÔNG `restore` (làn fa1, vòng sửa 1 — rà soát Task 1, m9): `restore` là khả năng của
     * Laravel/Filament cho khôi phục XOÁ MỀM (`Task` dùng `SoftDeletes`) — một `RestoreAction`/`TrashedFilter` thêm vào
     * `TaskResource` sau này sẽ hỏi `restore` và không được mở bằng cổng của "Khôi phục việc đã huỷ". Policy không có
     * `restore()`, nên cổng đó luôn từ chối (như `delete`/`forceDelete`: không ai xoá một việc).
     */
    public function restoreCancelled(User|ClientUser $user, Task $task): bool
    {
        if (! $user instanceof User || $task->status !== TaskStatus::Cancelled || ! $this->view($user, $task)) {
            return false;
        }

        return Task::isSupervisor($user)
            || ($task->matter_id !== null && $task->matter?->lead_lawyer_id === $user->getKey())
            || $task->cancelledBy() === $user->getKey();
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
     *
     * Sửa sau kiểm toán 2026-10-21 (mục 2): vế "luật sư phụ trách / quản lý / quản trị" KHÔNG áp cho việc NGƯỜI KHÁC giao
     * cho chính `$user` (`assignee_id` là họ, `assigned_by` là người khác) — trước bản sửa, luật sư phụ trách nhận một
     * việc trưởng phòng giao vẫn tự sửa hạn/ưu tiên, tự huỷ, tự giao lại nó. Họ "Trả lại" kèm lý do như mọi người nhận;
     * người giao (hay luật sư phụ trách / quản lý khác) quyết. Đường có sẵn giữ nguyên: việc RIÊNG (người giao = người
     * giữ — sau một lần nhận thay hay bàn giao, `ReassignTask` mục "Nhận thay") vẫn quản lý được bằng vế người giao.
     * Áp cho mọi trạng thái: việc họ đã "Trả lại", hay đã hoàn thành (ngoài 24 giờ "bấm nhầm" của
     * {@see self::completedRecentlyBy()}), vẫn là việc người khác giao cho họ.
     */
    private function manages(User|ClientUser $user, Task $task): bool
    {
        if (! $user instanceof User || ! $this->view($user, $task)) {
            return false;
        }

        if ($task->assigned_by === $user->getKey()) {
            return true;
        }

        if ($task->assignee_id === $user->getKey()) {
            return false;
        }

        return Task::isSupervisor($user)
            || ($task->matter_id !== null && $task->matter?->lead_lawyer_id === $user->getKey());
    }

    private function isAssignee(User|ClientUser $user, Task $task): bool
    {
        return $user instanceof User
            && $task->assignee_id === $user->getKey()
            && $this->view($user, $task);
    }

    /**
     * Người nhận còn GIỮ ĐƯỢC việc này (làn tk, vòng sửa polish 1 — rà soát Task 1, m5): cùng luật "ai nhận
     * được việc" của `TaskAssignees` ({@see TaskAssignees::holdsSeat()} — vế của vụ, không `is_active`, xem
     * docblock lớp): việc của một vụ chỉ cho người ngồi ghế giữ việc (không "người theo dõi"), chưa trả lại
     * ghế, còn mở được vụ; việc chung không có vế này. Người không còn đạt (việc đó hiện "Cần giao lại" —
     * `Task::scopeNeedsReassignment()`, vế `off_team` / `seat_declined` / `off_access`) không bấm "Đã nhận" hay
     * "Hoàn thành" được nữa; họ vẫn "Trả lại" được. Quan hệ `matter` là bản `OpensTask` vừa khoá khi hỏi dưới
     * khoá.
     */
    private function stillHolds(User $user, Task $task): bool
    {
        return $task->matter_id === null
            || ($task->matter !== null && app(TaskAssignees::class)->holdsSeat($user, $task->matter));
    }

    private function involved(User $user, Task $task): bool
    {
        return $task->assignee_id === $user->getKey() || $task->assigned_by === $user->getKey();
    }
}
