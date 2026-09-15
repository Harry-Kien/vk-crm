<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;

class MatterPolicy
{
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        if ($user instanceof ClientUser) {
            return true;
        }

        return $user->can(Permission::MatterViewAny->value) || $user->can(Permission::MatterView->value);
    }

    public function view(User|ClientUser $user, Matter $matter): bool
    {
        if ($user instanceof ClientUser) {
            return $this->visibleToPortal($user, $matter);
        }

        return $user->can(Permission::MatterView->value)
            && Matter::query()->withTrashed()->listableBy($user)->whereKey($matter->getKey())->exists();
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterCreate->value);
    }

    public function update(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $user->can(Permission::MatterUpdate->value)
            && $this->view($user, $matter);
    }

    public function transitionStage(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $user->can(Permission::MatterTransitionStage->value)
            && $this->view($user, $matter);
    }

    /** Xoá mềm vụ việc là việc hệ trọng: chỉ quản trị. */
    public function delete(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function restore(User|ClientUser $user, Matter $matter): bool
    {
        return $this->delete($user, $matter);
    }

    /** Không ai xoá vĩnh viễn được: model cũng chặn (MatterNotDestroyable). */
    public function forceDelete(User|ClientUser $user, Matter $matter): bool
    {
        return false;
    }
}
