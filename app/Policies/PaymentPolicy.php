<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;

/** Cùng lý do và cùng giới hạn với {@see ContractPolicy} — xem docblock ở đó. */
class PaymentPolicy
{
    use ChecksMatterAccess;

    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User;
    }

    public function view(User|ClientUser $user, Payment $payment): bool
    {
        return $user instanceof User && $this->canSeeMatter($user, $payment->instalment->contract->matter);
    }
}
