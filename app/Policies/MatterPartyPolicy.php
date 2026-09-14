<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterParty;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

class MatterPartyPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, MatterParty $party): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $party->matter);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterUpdate->value);
    }

    public function update(User|ClientUser $user, MatterParty $party): bool
    {
        return $this->view($user, $party) && $user->can(Permission::MatterUpdate->value);
    }

    public function delete(User|ClientUser $user, MatterParty $party): bool
    {
        return $this->update($user, $party);
    }
}
