<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;

/**
 * Tiền của một vụ việc đi theo MỘT định nghĩa — `billing.view` + `Matter::listableBy()`, xem
 * {@see ChecksBillingAccess} — không theo `matter.view` (M9 P3).
 *
 * **Policy trả lời AI, Action trả lời KHI NÀO.** Ba câu hỏi ghi (`create`, `update`, `delete`)
 * cùng một điều kiện hôm nay: thấy tiền của vụ và có `contract.manage`. Trạng thái nào thì làm
 * được việc gì không nằm ở đây: hợp đồng chỉ xoá được khi còn `draft` và chưa có khoản thu nào —
 * hook `Contract::deleting` giữ điều đó ở mọi đường ghi qua model; kích hoạt, phụ lục, huỷ, hoàn
 * tất đòi trạng thái nào là việc của các Action trong `app/Actions/Billing/`. Cùng quy ước với
 * docblock của `DocumentPolicy::publish`, nơi điều kiện "trạng thái nào thì công bố được" thuộc
 * về `PublishDocument` chứ không thuộc policy.
 * Ba thân hàm viết rời chứ không gọi lẫn nhau, cùng lý do với `DocumentPolicy::publish`/`delete`:
 * ba câu hỏi khác nhau, một ngày siết một câu không được âm thầm siết luôn hai câu kia.
 *
 * `update` là cổng của MỌI thay đổi trên một hợp đồng đã có: sửa bản nháp và lịch thu của nó,
 * kích hoạt, ký phụ lục (kể cả đổi số tiền hay huỷ một đợt — {@see ContractAmendmentPolicy} vì
 * thế không có `create`), hoàn tất, huỷ. SPEC §5 gom tất cả vào `contract.manage`.
 *
 * Khách hàng: từ chối mọi thứ (P1, cổng mở có chủ đích ở M9 Task 10).
 */
class ContractPolicy
{
    use ChecksBillingAccess;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    public function view(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter);
    }

    /**
     * Soạn hợp đồng cho một vụ: `Gate::allows('create', [Contract::class, $matter])`.
     *
     * **Không có ngữ cảnh vụ việc thì từ chối** — khác `DocumentPolicy::create`, không có nhánh
     * "câu hỏi giao diện": một hợp đồng luôn thuộc về đúng một vụ, và màn hình duy nhất soạn hợp
     * đồng (tab tiền của vụ) luôn có vụ trong tay. `mixed` vì cùng lý do với `canListBilling()`.
     *
     * @param  Matter|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canSeeBilling($user, $context instanceof Matter ? $context : null)
            && $user->can(Permission::ContractManage->value);
    }

    public function update(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && $user->can(Permission::ContractManage->value);
    }

    /** "Khi nào xoá được" (`draft`, chưa có khoản thu) là hook `Contract::deleting`, xem docblock lớp. */
    public function delete(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && $user->can(Permission::ContractManage->value);
    }
}
