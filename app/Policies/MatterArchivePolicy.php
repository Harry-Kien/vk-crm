<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\MatterArchive;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/** Hồ sơ lưu trữ thừa hưởng quyền xem của vụ việc cha, chỉ nhân sự (SPEC §4.19). */
class MatterArchivePolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, MatterArchive $archive): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $archive->matter);
    }
}
