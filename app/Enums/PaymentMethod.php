<?php

namespace App\Enums;

/** Cách một khoản thu về tới văn phòng (`payments.method`). */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Offset = 'offset';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.payment_method.'.$this->value);
    }
}
