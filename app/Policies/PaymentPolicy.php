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

/**
 * Cùng định nghĩa "ai thấy tiền của vụ nào" với {@see ContractPolicy}, đọc qua đợt và hợp đồng cha.
 *
 * Không có `update`/`delete`: một khoản thu không sửa, không xoá — ghi nhầm thì HUỶ (`void`), và
 * hook `Payment::deleting` từ chối mọi lần xoá. Gate trả `false` cho mọi ability không có ở đây.
 */
class PaymentPolicy
{
    use ChecksBillingAccess;

    /** @param  Matter|null  $context  xem {@see ChecksBillingAccess::canListBilling()} */
    public function viewAny(User|ClientUser $user, mixed $context = null): bool
    {
        return $this->canListBilling($user, $context);
    }

    public function view(User|ClientUser $user, Payment $payment): bool
    {
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
     */
    private function canRecordPaymentOn(User|ClientUser $user, ?Matter $matter): bool
    {
        $matter = $this->matterForBillingGate($matter);

        return $this->canSeeBilling($user, $matter)
            && ($matter->confidentiality === Confidentiality::Restricted
                || $user->can(Permission::PaymentRecord->value));
    }
}
