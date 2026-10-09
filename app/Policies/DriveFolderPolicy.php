<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\DriveFolder;
use App\Models\User;

/** Thư mục tháng của kho Google Drive (M14): không ai làm gì qua Gate — cùng lý do {@see DriveObjectPolicy}. */
class DriveFolderPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function view(User|ClientUser $user, DriveFolder $folder): bool
    {
        return false;
    }

    public function create(User|ClientUser $user): bool
    {
        return false;
    }

    public function update(User|ClientUser $user, DriveFolder $folder): bool
    {
        return false;
    }

    public function delete(User|ClientUser $user, DriveFolder $folder): bool
    {
        return false;
    }

    public function restore(User|ClientUser $user, DriveFolder $folder): bool
    {
        return false;
    }

    public function forceDelete(User|ClientUser $user, DriveFolder $folder): bool
    {
        return false;
    }
}
