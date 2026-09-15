<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ChecklistTemplateItem;
use App\Models\ClientUser;
use App\Models\User;

/** Mục của danh mục mẫu là cấu hình nội bộ: đọc/ghi đều cần settings.manage (SPEC §4.10). */
class ChecklistTemplateItemPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function view(User|ClientUser $user, ChecklistTemplateItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|ClientUser $user, ChecklistTemplateItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User|ClientUser $user, ChecklistTemplateItem $item): bool
    {
        return $this->viewAny($user);
    }
}
