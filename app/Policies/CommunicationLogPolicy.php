<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;

class CommunicationLogPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $communicationLog) && $this->canSeeMatter($user, $communicationLog->matter)
            : $this->canSeeMatter($user, $communicationLog->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    public function update(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $communicationLog->matter);
    }

    public function delete(User|ClientUser $user, CommunicationLog $communicationLog): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $communicationLog->matter);
    }
}
