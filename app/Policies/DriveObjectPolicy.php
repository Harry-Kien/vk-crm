<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\DriveObject;
use App\Models\User;

/**
 * Chỉ mục kho Google Drive (M14): không ai làm gì qua Gate, kể cả admin. Bảng chỉ do mã của kho ghi
 * và đọc; không màn hình nào liệt kê nó, vì `file_id` không bao giờ rời máy chủ (kế hoạch M14, R3).
 * Policy có mặt để `PortalCoverageTest` ("mọi model bị giới hạn cổng đều có policy") và để một
 * resource Filament lỡ tạo ra cho model này không hiện được gì.
 */
class DriveObjectPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function view(User|ClientUser $user, DriveObject $object): bool
    {
        return false;
    }

    public function create(User|ClientUser $user): bool
    {
        return false;
    }

    public function update(User|ClientUser $user, DriveObject $object): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, DriveObject $object): bool
    {
        return false;
    }

    public function restore(User|ClientUser $user, DriveObject $object): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, DriveObject $object): bool
    {
        return false;
    }
}
