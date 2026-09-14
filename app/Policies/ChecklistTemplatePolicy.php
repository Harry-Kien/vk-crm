<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ChecklistTemplate;
use App\Models\ClientUser;
use App\Models\User;

/** Danh mục mẫu là cấu hình nội bộ: đọc/ghi đều cần settings.manage (SPEC §4.10). */
class ChecklistTemplatePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function view(User|ClientUser $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|ClientUser $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|ClientUser $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User|ClientUser $user, ChecklistTemplate $template): bool
    {
        return $this->viewAny($user);
    }
}
