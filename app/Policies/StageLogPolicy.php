<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\StageLog;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class StageLogPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    /**
     * `is_published` được đọc THẲNG trên bản ghi, bên cạnh `visibleToPortal()` — cùng thiết bị
     * với `Document::isReleasedToPortal()` và với `MatterPolicy::releasedToPortal()`, thêm ở M5
     * Task 2. Không có nó, điều kiện "chưa công bố thì khách chưa đọc được" chỉ tồn tại ở MỘT
     * chỗ duy nhất (một câu `where` trong `StageLog::applyClientPortalConstraints()`), và một
     * lần quên câu `where` đó đưa mọi ghi chú tiến độ chưa công bố ra trước mắt khách. Đây là
     * dòng nguy hiểm nhất của portal: một dòng chưa công bố là một câu văn phòng CHƯA quyết định
     * nói ra.
     *
     * Điều kiện vụ việc cha thì không nhắc lại ở đây: `canSeeMatter()` đưa nó về
     * `MatterPolicy::view`, nơi nó cũng đã được phát biểu hai lần.
     */
    public function view(User|ClientUser $user, StageLog $stageLog): bool
    {
        return $user instanceof ClientUser
            ? (bool) $stageLog->is_published
                && $this->visibleToPortal($user, $stageLog)
                && $this->canSeeMatter($user, $stageLog->matter)
            : $this->canSeeMatter($user, $stageLog->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    /** Công bố tiến độ cho khách — SPEC §5 stageLog.publish. */
    public function publish(User|ClientUser $user, StageLog $stageLog): bool
    {
        return $user instanceof User
            && $user->can(Permission::StageLogPublish->value)
            && $this->canSeeMatter($user, $stageLog->matter);
    }

    /** Nhật ký chỉ thêm (SPEC §4.8); model cũng chặn. */
    public function update(User|ClientUser $user, StageLog $stageLog): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, StageLog $stageLog): bool
    {
        return false;
    }
}
