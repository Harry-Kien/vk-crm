<?php

namespace App\Policies;

use App\Enums\Permission;
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

    /**
     * M7 Task 4: bấm "Sinh (lại) gói bàn giao". `document.publish` (quyền đưa văn bản ra khỏi văn
     * phòng — cùng quyền công bố chính gói đó qua `PublishDocument`) CỘNG xem được vụ việc. Đi qua
     * `view()` nên một vụ `restricted` không bao giờ cho manager thường bấm: họ không xem được
     * archive của nó (MatterPolicy::view). Trợ lý không có `document.publish` nên không sinh được.
     */
    public function generateHandover(User|ClientUser $user, MatterArchive $archive): bool
    {
        return $this->view($user, $archive)
            && $user->can(Permission::DocumentPublish->value);
    }
}
