<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\StageLogView;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/** Nhật ký khách đã xem tiến độ: thừa hưởng quyền xem của vụ việc cha, chỉ nhân sự. */
class StageLogViewPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, StageLogView $view): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $view->stageLog->matter);
    }
}
