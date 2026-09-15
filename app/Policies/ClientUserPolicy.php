<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\User;

/** Quản lý tài khoản khách chỉ dành cho nhân sự — khách không bao giờ chạm tới. */
class ClientUserPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientUserManage->value);
    }

    public function view(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $user instanceof User && $user->can(Permission::ClientUserManage->value);
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::ClientUserManage->value);
    }

    public function update(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, ClientUser $clientUser): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }
}
