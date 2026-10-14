<?php

namespace App\Policies;

use App\Enums\InstalmentStatus;
use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Cùng định nghĩa "ai thấy tiền của vụ nào" với {@see ContractPolicy}, đọc qua hợp đồng cha.
 *
 * Không có `create`/`update`/`delete`: lịch thu chỉ đổi qua hợp đồng — soạn nháp và phụ lục đi
 * qua `ContractPolicy::create`/`update`, và hook `Instalment::deleting` chặn xoá đợt của hợp đồng
 * đã rời `draft`. Gate trả `false` cho mọi ability không có ở đây.
 *
 * Khách hàng (M9 Task 10, P1): CHỈ `view`, xem {@see self::view()}. `viewAny` và `waive` vẫn từ
 * chối khách qua `ChecksBillingAccess`.
 */
class InstalmentPolicy
{
    use ChecksBillingAccess;
    use ReadsPortalParents;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    /**
     * Khách — tầng QUYỀN của cổng (M9 Task 10, P1): đợt KHÔNG `cancelled` (thuộc tính của chính
     * dòng, nói lại `Instalment::scopeShownToClient()` mà không chạy nó), **và** khách thấy hợp đồng
     * cha — hỏi `Gate` (`ContractPolicy::view`), không viết lại điều kiện hợp đồng hay vụ việc nào.
     * Hợp đồng cha nạp không qua scope cổng (`parentWithoutPortalScope()`).
     */
    public function view(User|ClientUser $user, Instalment $instalment): bool
    {
        if ($user instanceof ClientUser) {
            /** @var Contract|null $contract */
            $contract = $this->parentWithoutPortalScope($instalment, 'contract');

            // Đọc qua `ownColumnForGate()`: một dòng nạp thiếu cột `status` không được coi là "chưa
            // huỷ" (lượt quét §10 trước bản 1.0).
            [$known, $status] = $this->ownColumnForGate($instalment, 'status');

            return $known
                && $status !== InstalmentStatus::Cancelled
                && $contract !== null
                && $user->can('view', $contract);
        }

        return $this->canSeeBilling($user, $instalment->contract->matter);
    }

    /**
     * Miễn một đợt (`WaiveInstalment`) là bớt số khách phải trả: một quyết định về điều khoản
     * thương mại, cùng loại với đổi số tiền một đợt qua phụ lục — nên đi theo `contract.manage`
     * cộng "thấy tiền của vụ" ({@see self::canSeeBilling()}): mọi luật sư trong đội của vụ (kể cả
     * luật sư phối hợp), quản lý, admin; trên vụ `restricted` chỉ luật sư phụ trách và admin. Không
     * theo `payment.record` (kế toán ghi tiền đã về, không tự xoá nợ). Cách đọc của M9 Task 3, nay
     * là đính chính có ngày của SPEC §5 ("miễn một đợt cũng thuộc `contract.manage`", sửa
     * 2026-10-04 cho khớp hàm này); test qua màn hình: `tests/Feature/Filament/WaiveRightSpecTest.php`.
     * Trạng thái nào thì miễn được, lý do ≥ 20 ký tự: `WaiveInstalment`.
     */
    public function waive(User|ClientUser $user, Instalment $instalment): bool
    {
        return $this->canSeeBilling($user, $instalment->contract->matter)
            && $user->can(Permission::ContractManage->value);
    }

    /**
     * "Dời hạn đợt" (`RescheduleInstalment`, làn fb mục B): khách xin khất là một quyết định về
     * điều khoản thương mại, cùng trục với {@see self::waive()} — `contract.manage` cộng "thấy tiền
     * của vụ". Kế toán (chỉ `payment.record`) không tự dời hạn.
     */
    public function reschedule(User|ClientUser $user, Instalment $instalment): bool
    {
        return $this->waive($user, $instalment);
    }
}
