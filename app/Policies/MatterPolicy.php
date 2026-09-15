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

        if (! $user->can(Permission::MatterView->value)) {
            return false;
        }

        // Đường trong bộ nhớ khi `team` đã nạp (ví dụ danh sách Filament eager-load nó): tránh
        // chạy một EXISTS cho mỗi dòng. Ngược lại giữ nguyên truy vấn cũ (cũng cho phép vụ đã
        // xoá mềm, để admin còn thao tác được).
        return $matter->relationLoaded('team')
            ? $matter->isListableBy($user)
            : Matter::query()->withTrashed()->listableBy($user)->whereKey($matter->getKey())->exists();
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
