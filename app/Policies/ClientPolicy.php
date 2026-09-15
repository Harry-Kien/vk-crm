<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;

class ClientPolicy
{
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function view(User|ClientUser $user, Client $client): bool
    {
        // Khách xem được hồ sơ của chính mình; scope đã giới hạn, policy xác nhận lại.
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $client)
            : $user->can(Permission::ClientManage->value);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientManage->value);
    }

    public function update(User|ClientUser $user, Client $client): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, Client $client): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }
}
