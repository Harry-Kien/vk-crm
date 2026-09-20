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

    /**
     * Ghi vào bản ghi con của một vụ việc thì phải được ghi vào chính vụ việc đó.
     * `MatterPolicy::update` là một chỗ duy nhất định nghĩa cả ba điều kiện — chưa xoá mềm,
     * có `matter.update`, và thấy được vụ việc — nên không policy con nào chép lại chúng.
     */
    protected function canUpdateMatter(User|ClientUser $user, ?Matter $matter): bool
    {
        return $matter !== null && $user->can('update', $matter);
    }
}
