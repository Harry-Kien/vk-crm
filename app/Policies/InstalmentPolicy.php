<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/** Cùng lý do và cùng giới hạn với {@see ContractPolicy} — xem docblock ở đó. */
class InstalmentPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, Instalment $instalment): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $instalment->contract->matter);
    }
}
