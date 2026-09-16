<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterTypeStage;
use App\Models\User;

/**
 * Cùng luật với MatterTypePolicy: ai cũng đọc được (portal cần hiển thị giai đoạn), chỉ
 * settings.manage được ghi. Không có policy riêng thì Filament (không ở chế độ nghiêm ngặt)
 * mặc định CHO PHÉP mọi hành động trên StagesRelationManager — khai báo rõ ở đây để việc thêm/
 * sửa/xoá giai đoạn không chỉ dựa vào việc luật sư không mở được trang EditMatterType.
 */
class MatterTypeStagePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return true;
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function update(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return $this->create($user);
    }

    public function delete(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return $this->create($user);
    }
}
