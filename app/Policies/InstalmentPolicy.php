<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;

/**
 * Cùng định nghĩa "ai thấy tiền của vụ nào" với {@see ContractPolicy}, đọc qua hợp đồng cha.
 *
 * Không có `create`/`update`/`delete`: lịch thu chỉ đổi qua hợp đồng — soạn nháp và phụ lục đi
 * qua `ContractPolicy::create`/`update`, và hook `Instalment::deleting` chặn xoá đợt của hợp đồng
 * đã rời `draft`. Gate trả `false` cho mọi ability không có ở đây.
 */
class InstalmentPolicy
{
    use ChecksBillingAccess;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    public function view(User|ClientUser $user, Instalment $instalment): bool
    {
        return $this->canSeeBilling($user, $instalment->contract->matter);
    }

    /**
     * Miễn một đợt (`WaiveInstalment`) là bớt số khách phải trả: một quyết định về điều khoản
     * thương mại, cùng loại với đổi số tiền một đợt qua phụ lục — nên đi theo `contract.manage`
     * (luật sư của vụ, quản lý, admin), không theo `payment.record` (kế toán ghi tiền đã về, không
     * tự xoá nợ). SPEC §5 bổ sung M9 không nêu tên việc miễn; đây là cách đọc của M9 Task 3, ghi
     * trong báo cáo để controller xác nhận hoặc đảo. Trạng thái nào thì miễn được, lý do ≥ 20 ký
     * tự: `WaiveInstalment`.
     */
    public function waive(User|ClientUser $user, Instalment $instalment): bool
    {
        return $this->canSeeBilling($user, $instalment->contract->matter)
            && $user->can(Permission::ContractManage->value);
    }
}
