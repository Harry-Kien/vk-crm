<?php

namespace App\Exceptions;

use App\Models\Instalment;
use App\Support\Billing\Money;
use DomainException;

/**
 * Thu vượt: tổng khoản thu chưa huỷ CỘNG khoản thu mới sẽ vượt `amount` của đợt (M9 Task 5).
 * Thu một phần là bình thường; thu vượt thì KHÔNG tự rải sang đợt sau — thông điệp nói đúng còn
 * lại bao nhiêu, để người ghi tự sửa số hoặc ghi phần dư vào đợt kế tiếp bằng tay.
 */
class PaymentExceedsInstalment extends DomainException
{
    public static function make(Instalment $instalment, int $amount, int $collected): self
    {
        $remaining = max(0, $instalment->amount - $collected);

        return new self(__('billing.errors.payment_exceeds_instalment', [
            'name' => $instalment->name,
            'amount' => Money::format($amount),
            'remaining' => Money::format($remaining),
        ]));
    }
}
