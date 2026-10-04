<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Policies\Concerns\ReadsPortalParents;

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
    use ReadsPortalParents;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    /**
     * Khách — tầng QUYỀN của cổng (M9 Task 10; mở theo chữ kế hoạch, phán quyết controller): phụ lục
     * của một hợp đồng khách thấy — hỏi `Gate` (`ContractPolicy::view`). Phụ lục không có trạng
     * thái riêng, nên không có điều kiện nào trên chính dòng. Trang cổng không vẽ phụ lục; lý do và
     * bản scan nằm trong danh sách ẩn của model (`ContractAmendment::internalAttributes()`).
     */
    public function view(User|ClientUser $user, ContractAmendment $amendment): bool
    {
        if ($user instanceof ClientUser) {
            /** @var Contract|null $contract */
            $contract = $this->parentWithoutPortalScope($amendment, 'contract');

            return $contract !== null && $user->can('view', $contract);
        }

        return $this->canSeeBilling($user, $amendment->contract->matter);
    }
}
