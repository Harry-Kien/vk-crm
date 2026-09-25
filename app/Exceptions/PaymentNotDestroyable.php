<?php

namespace App\Exceptions;

use DomainException;

/**
 * Chặn xoá cứng một khoản thu, vô điều kiện (`Payment::booted()`). Một khoản thu ghi nhầm không
 * được biến mất — nó được HUỶ (`voided_at` + lý do, Action ở Task khác) và vẫn nằm đó, đúng tinh
 * thần `stage_logs` (chỉ thêm). `payments` cố ý không có `deleted_at` (M9 quyết định 4).
 */
class PaymentNotDestroyable extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.payment_not_destroyable'));
    }
}
