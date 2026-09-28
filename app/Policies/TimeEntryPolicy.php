<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/**
 * Khung quyền cho {@see TimeEntry} (M9 Task 12 — chỉ khung, không màn hình nào gọi policy này ở
 * M9). Theo đúng thành ngữ {@see MatterPartyPolicy}: thấy được vụ việc cha thì thấy
 * được nhật ký giờ làm việc của nó; `matter.update` thì ghi được.
 *
 * **`ClientUser` luôn bị từ chối, mọi ability, không ngoại lệ** — khớp
 * `applyClientPortalConstraints()` của model (chặn `1 = 0` vĩnh viễn, xem docblock
 * {@see TimeEntry}): cổng khách đóng kín mãi, không như bốn model tiền được mở có chủ đích ở
 * Task 10. `PortalCoverageTest` đòi mọi model dùng `RestrictedToClientPortal` có một policy —
 * đây là policy đó.
 */
class TimeEntryPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, TimeEntry $timeEntry): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $timeEntry->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    /**
     * Không tự kiểm `$user instanceof User` ở đây — `canUpdateMatter()` (qua `MatterPolicy::update()`)
     * đã tự có điều kiện đó ở CHÍNH NÓ, nên lặp lại là một điều kiện không mutation probe nào làm
     * đỏ được (đã thử, xoá không đổi kết quả bất kỳ test nào). Khác `view()` phía trên: `canSeeMatter()`
     * đi qua `MatterPolicy::view()`, nơi nhánh `ClientUser` KHÔNG bị chặn bởi instanceof mà rẽ sang
     * `releasedToPortal()`/`visibleToPortal()` — nên ở `view()` việc tự kiểm là bắt buộc, không
     * phải trang trí.
     */
    public function update(User|ClientUser $user, TimeEntry $timeEntry): bool
    {
        return $this->canUpdateMatter($user, $timeEntry->matter);
    }

    public function delete(User|ClientUser $user, TimeEntry $timeEntry): bool
    {
        return $this->update($user, $timeEntry);
    }
}
