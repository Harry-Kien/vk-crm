<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterType;
use App\Models\User;

/** Nhãn giai đoạn/loại vụ việc: ai cũng đọc được (portal cần hiển thị), chỉ settings.manage được ghi. */
class MatterTypePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterType $matterType): bool
    {
        return true;
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function update(User|ClientUser $user, MatterType $matterType): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, MatterType $matterType): bool
    {
        return $this->create($user);
    }
}
