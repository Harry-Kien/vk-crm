<?php

namespace App\Policies;

use App\Enums\ContractStatus;
use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Tiền của một vụ việc đi theo MỘT định nghĩa — `billing.view` + `Matter::listableBy()`, xem
 * {@see ChecksBillingAccess} — không theo `matter.view` (M9 P3).
 *
 * **Policy trả lời AI, Action trả lời KHI NÀO.** Mọi câu hỏi ghi đòi trước hết "thấy tiền của vụ".
 * Trạng thái nào thì làm được việc gì không nằm ở đây: hợp đồng chỉ xoá được khi còn `draft` và chưa
 * có khoản thu nào — hook `Contract::deleting` giữ điều đó ở mọi đường ghi qua model; sửa nháp, kích
 * hoạt, phụ lục, huỷ, hoàn tất đòi trạng thái nào là việc của các Action trong `app/Actions/Billing/`.
 * Cùng quy ước với docblock của `DocumentPolicy::publish`, nơi điều kiện "trạng thái nào thì công bố
 * được" thuộc về `PublishDocument` chứ không thuộc policy.
 * Các thân hàm viết rời chứ không gọi lẫn nhau, cùng lý do với `DocumentPolicy::publish`/`delete`:
 * mỗi câu hỏi một khác, một ngày siết một câu không được âm thầm siết luôn các câu kia.
 *
 * **Hai nhóm câu hỏi ghi (làn kt, 2026-10-15, SPEC §5 "Bổ sung 2026-10-15").**
 *  - **Nhập hợp đồng đã ký** — `create` (soạn bản nháp cho vụ), {@see self::updateDraft()} (sửa bản
 *    nháp và lịch thu của nó), `delete` (xoá bản nháp), {@see self::activate()} (kích hoạt với ngày
 *    ký): `contract.manage` **hoặc** `contract.enter`. Kế toán có `contract.enter`, nên nhập được hợp
 *    đồng giấy đã ký của một vụ có sẵn mà không mở nội dung hồ sơ.
 *  - **Quyết định thương mại trên hợp đồng đã ký** — `update`: ký phụ lục (kể cả đổi số tiền hay huỷ
 *    một đợt — {@see ContractAmendmentPolicy} vì thế không có `create`), hoàn tất, huỷ. Chỉ
 *    `contract.manage`. Miễn, dời hạn, thư nhắc khách ở `InstalmentPolicy`, cũng chỉ `contract.manage`.
 * Trước làn kt `update` còn là cổng của sửa nháp và kích hoạt; hai việc đó nay có ability riêng, và
 * `UpdateDraftContract`/`ActivateContract` cùng hai nút của tab tiền hỏi đúng ability đó.
 *
 * Khách hàng (M9 Task 10, P1): CHỈ `view` — hợp đồng đã ký của một vụ việc khách thấy trên cổng,
 * xem {@see self::view()}. `viewAny` và mọi câu hỏi ghi vẫn từ chối khách: chúng đi qua
 * `ChecksBillingAccess`, nơi nhánh khách luôn trả `false`.
 */
class ContractPolicy
{
    use ChecksBillingAccess;
    use ChecksMatterAccess;
    use ReadsPortalParents;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    /**
     * Nhân sự: "ai thấy tiền của vụ nào" ({@see ChecksBillingAccess::canSeeBilling()}).
     *
     * Khách — tầng QUYỀN của cổng (M9 Task 10, P1), phát biểu lại tầng truy vấn
     * (`Contract::applyClientPortalConstraints()`) bằng THUỘC TÍNH, không chạy lại scope nào của
     * tiền: hợp đồng `active` hoặc `completed`, **và** khách thấy vụ việc cha (`MatterPolicy::view`
     * qua `canSeeMatter()` — năm điều kiện của ranh giới cổng, bằng thuộc tính ở
     * `releasedToPortal()`). Vụ cha nạp KHÔNG qua scope cổng (`parentWithoutPortalScope()`), để câu
     * trả lời nói về `$user` chứ không về phiên nào đang mở. Không có điều kiện `restricted`: P3 là
     * luật của NHÂN SỰ; khách luôn thấy hợp đồng mình đã ký trên vụ của mình.
     */
    public function view(User|ClientUser $user, Contract $contract): bool
    {
        if ($user instanceof ClientUser) {
            /** @var Matter|null $matter */
            $matter = $this->parentWithoutPortalScope($contract, 'matter');

            return in_array($contract->status, [ContractStatus::Active, ContractStatus::Completed], true)
                && $this->canSeeMatter($user, $matter);
        }

        return $this->canSeeBilling($user, $contract->matter);
    }

    /**
     * Soạn hợp đồng (bản nháp) cho một vụ: `Gate::allows('create', [Contract::class, $matter])` —
     * `contract.manage` hoặc `contract.enter` (xem docblock lớp).
     *
     * **Không có ngữ cảnh vụ việc thì từ chối** — khác `DocumentPolicy::create`, không có nhánh
     * "câu hỏi giao diện": một hợp đồng luôn thuộc về đúng một vụ, và hai màn hình soạn hợp đồng (tab
     * tiền của vụ, trang "Hợp đồng" của kế toán) luôn có vụ trong tay. `mixed` vì cùng lý do với
     * `canListBilling()`.
     *
     * @param  Matter|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canSeeBilling($user, $context instanceof Matter ? $context : null)
            && ($user->can(Permission::ContractManage->value) || $user->can(Permission::ContractEnter->value));
    }

    /** Sửa một bản nháp và thay lịch thu của nó (`UpdateDraftContract`; "còn nháp không" là việc của Action). */
    public function updateDraft(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && ($user->can(Permission::ContractManage->value) || $user->can(Permission::ContractEnter->value));
    }

    /** Ghi nhận khách đã ký: `draft` → `active` (`ActivateContract`, `EnterSignedContract`). */
    public function activate(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && ($user->can(Permission::ContractManage->value) || $user->can(Permission::ContractEnter->value));
    }

    /** Phụ lục, hoàn tất, huỷ — chỉ `contract.manage` (xem docblock lớp). */
    public function update(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && $user->can(Permission::ContractManage->value);
    }

    /**
     * Xoá bản nháp (`DeleteDraftContract`). "Khi nào xoá được" (`draft`, chưa có khoản thu) là hook
     * `Contract::deleting`, xem docblock lớp — nên `contract.enter` ở đây không bao giờ xoá được một
     * hợp đồng đã ký.
     */
    public function delete(User|ClientUser $user, Contract $contract): bool
    {
        return $this->canSeeBilling($user, $contract->matter)
            && ($user->can(Permission::ContractManage->value) || $user->can(Permission::ContractEnter->value));
    }
}
