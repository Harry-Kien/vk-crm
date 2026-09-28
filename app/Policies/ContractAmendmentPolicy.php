<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\ContractAmendment;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;

/**
 * Cùng định nghĩa "ai thấy tiền của vụ nào" với {@see ContractPolicy}, đọc qua hợp đồng cha.
 *
 * Chỉ đọc. Phụ lục chỉ sinh ra bên trong `AmendContract`, và cổng của việc đó là
 * `ContractPolicy::update` (SPEC §5: `contract.manage` gồm "ký phụ lục"); model chặn sửa và xoá
 * (`ContractAmendmentImmutable`). Gate trả `false` cho mọi ability không có ở đây.
 */
class ContractAmendmentPolicy
{
    use ChecksBillingAccess;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    public function view(User|ClientUser $user, ContractAmendment $amendment): bool
    {
        return $this->canSeeBilling($user, $amendment->contract->matter);
    }
}
