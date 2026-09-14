<?php

namespace App\Policies\Concerns;

use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

/**
 * Mọi bản ghi con của Matter đều thừa hưởng quyền xem của vụ việc cha. Không policy nào
 * được viết lại điều kiện đội ngũ / confidentiality: một chỗ duy nhất là MatterPolicy::view.
 */
trait ChecksMatterAccess
{
    protected function canSeeMatter(User|ClientUser $user, ?Matter $matter): bool
    {
        return $matter !== null && $user->can('view', $matter);
    }
}
