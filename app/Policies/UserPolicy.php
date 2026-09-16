<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\User;

/**
 * Quản trị nhân sự nội bộ (SPEC §7.4, §4.1) — không có quyền riêng ở bảng SPEC §5, dùng
 * settings.manage (chỉ admin) vì đây cùng nhóm với quản trị Role/cấu hình hệ thống.
 */
class UserPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function view(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User|ClientUser $user, User $model): bool
    {
        return $this->viewAny($user) && $user->isNot($model);
    }
}
