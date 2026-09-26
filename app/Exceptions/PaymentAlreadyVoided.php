<?php

namespace App\Exceptions;

use DomainException;

/**
 * Huỷ một khoản thu đã huỷ từ trước. `PaymentPolicy::void()` cố ý không hỏi "đã huỷ chưa" (đó là
 * việc của `VoidPayment`, không phải của policy — xem docblock `ContractPolicy`), nên đây là chốt
 * chặn duy nhất: không có nó, gọi `VoidPayment` hai lần trên cùng một khoản thu ghi đè
 * `voided_by`/`void_reason` lần hai lên lần đầu một cách im lặng — mất dấu ai huỷ trước, vì lý do
 * gì. Không nằm trong danh sách "Test bắt buộc" của kế hoạch M9 Task 5 — thêm vì đây là một lỗ
 * hổng rõ trong bản thân `VoidPayment`, không phải một tính năng ngoài phạm vi.
 */
class PaymentAlreadyVoided extends DomainException
{
    public static function make(): self
    {
        return new self(__('billing.errors.payment_already_voided'));
    }
}
