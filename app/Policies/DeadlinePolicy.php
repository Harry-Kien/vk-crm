<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class DeadlinePolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $deadline) && $this->canSeeMatter($user, $deadline->matter)
            : $this->canSeeMatter($user, $deadline->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    public function update(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $deadline->matter);
    }

    public function delete(User|ClientUser $user, Deadline $deadline): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $deadline->matter);
    }
}
