<?php

namespace App\Policies;

use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksBillingAccess;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Cùng định nghĩa "ai thấy tiền của vụ nào" với {@see ContractPolicy}, đọc qua đợt và hợp đồng cha.
 *
 * Không có `update`/`delete`: một khoản thu không sửa, không xoá — ghi nhầm thì HUỶ (`void`), và
 * hook `Payment::deleting` từ chối mọi lần xoá. Gate trả `false` cho mọi ability không có ở đây.
 *
 * Khách hàng (M9 Task 10, P1): CHỈ `view`, xem {@see self::view()}. `viewAny`, `create`, `void`
 * vẫn từ chối khách (`ChecksBillingAccess`, và `canRecordPaymentOn()` hỏi `User` đầu tiên).
 */
class PaymentPolicy
{
    use ChecksBillingAccess;
    use ReadsPortalParents;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    /**
     * Khách — tầng QUYỀN của cổng (M9 Task 10, P1): khoản thu CHƯA huỷ (`voided_at` trên chính dòng,
     * nói lại `Payment::scopeShownToClient()` mà không chạy nó), **và** khách thấy đợt cha — hỏi
     * `Gate` (`InstalmentPolicy::view`, rồi tới hợp đồng và vụ việc). Đợt cha nạp không qua scope
     * cổng (`parentWithoutPortalScope()`).
     */
    public function view(User|ClientUser $user, Payment $payment): bool
    {
        if ($user instanceof ClientUser) {
            /** @var Instalment|null $instalment */
            $instalment = $this->parentWithoutPortalScope($payment, 'instalment');

            return $payment->voided_at === null
                && $instalment !== null
                && $user->can('view', $instalment);
        }

        return $this->canSeeBilling($user, $payment->instalment->contract->matter);
    }

    /**
     * Ghi một khoản thu. Hai dạng ngữ cảnh: ĐỢT (`[Payment::class, $instalment]`, trang "Công nợ"
     * ghi theo dòng) và VỤ VIỆC (`[Payment::class, $matter]`, tab tiền của vụ theo thành ngữ
     * `$this->getOwnerRecord()`). Không có ngữ cảnh, hoặc ngữ cảnh khác hai loại đó → từ chối:
     * tiền luôn ghi vào một vụ cụ thể, và luật chọn nhánh theo `confidentiality` của CHÍNH vụ đó.
     *
     * @param  Instalment|Matter|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        $matter = match (true) {
            $context instanceof Instalment => $context->contract->matter,
            $context instanceof Matter => $context,
            default => null,
        };

        return $this->canRecordPaymentOn($user, $matter);
    }

    /**
     * Huỷ một khoản thu. SPEC §5 định nghĩa `payment.record` là "ghi nhận và huỷ", nên cùng luật
     * với `create` qua cùng một hàm. Khoản thu đã huỷ thì không huỷ lần hai — đó là việc của
     * `VoidPayment`, không phải của policy (xem docblock {@see ContractPolicy}).
     */
    public function void(User|ClientUser $user, Payment $payment): bool
    {
        return $this->canRecordPaymentOn($user, $payment->instalment->contract->matter);
    }

    /**
     * Thấy tiền của vụ, **và**: vụ thường → `payment.record` (kế toán, admin; quản lý chỉ xem,
     * luật sư không ghi); vụ `restricted` → không cần `payment.record`.
     *
     * Vế `restricted` KHÔNG viết thêm "admin hoặc luật sư phụ trách": trên vụ `restricted`,
     * `canSeeBilling()` đã chỉ để lọt đúng hai người đó (nhánh `restricted` của
     * `Matter::isListableBy()`), nên một điều kiện thứ hai sẽ là định nghĩa thứ hai của cùng một
     * luật — và là một điều kiện không mutation probe nào làm đỏ được. SPEC §5 bổ sung M9 nói lý
     * do bằng chính câu đó: "vì ngoài admin không ai khác thấy vụ đó". Nếu một ngày quản lý được
     * thấy vụ `restricted`, câu hỏi "quản lý có ghi được ở đó không" phải được hỏi lại cùng lúc,
     * ở đây.
     *
     * `confidentiality` đọc trên vụ ĐÃ QUA `matterForBillingGate()` (fix round 1, I1), không trên
     * vụ nơi gọi đưa vào: một vụ nạp thiếu cột có `confidentiality` là `null`, và đọc nó ở đó thì
     * luật sư phụ trách bị từ chối oan trên chính vụ `restricted` của mình.
     *
     * **`$user instanceof User` đứng ĐẦU (fix vòng M9 Task 5, carry-forward từ Task 3).** Bản
     * trước gọi `matterForBillingGate($matter)` TRƯỚC khi biết `$user` có phải nhân sự hay không:
     * với một `Matter` nạp thiếu cột, hàm đó nạp lại bằng một truy vấn KHÔNG có khoá `client`
     * (`withoutGlobalScope(ClientPortalScope::class)`) — tức một `ClientUser` gọi `create`/`void`
     * kích hoạt một lần đọc KHÔNG bị cắt theo phiên cổng khách của chính nó, trước khi bị từ chối.
     * Không đổi kết quả cuối (vẫn từ chối, `canSeeBilling()` đóng ngay ở nhánh khách), nhưng đổi
     * THỨ TỰ: một khách hàng không nên khiến cổng tiền chạy một truy vấn không khoá cổng khách chỉ
     * để rồi bị từ chối — cùng lý lẽ với `canSeeBilling()` tự nó đặt điều kiện này lên đầu. Test
     * `PaymentPolicyRecordOrderTest` ("refuses a client user before ever reloading a partially
     * loaded matter") dùng một `Matter` nạp thiếu cột (`select('id')`) để chứng minh không có
     * truy vấn nạp lại nào chạy trước khi bị từ chối.
     */
    private function canRecordPaymentOn(User|ClientUser $user, ?Matter $matter): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $matter = $this->matterForBillingGate($matter);

        return $this->canSeeBilling($user, $matter)
            && ($matter->confidentiality === Confidentiality::Restricted
                || $user->can(Permission::PaymentRecord->value));
    }
}
