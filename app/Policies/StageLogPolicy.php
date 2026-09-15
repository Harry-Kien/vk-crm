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

    public function view(User|ClientUser $user, StageLog $stageLog): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $stageLog) && $this->canSeeMatter($user, $stageLog->matter)
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
