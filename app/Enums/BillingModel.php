<?php

namespace App\Enums;

/**
 * Cách tính phí của hợp đồng. `fixed_fee` (giá trị thoả thuận một lần) là loại DUY NHẤT cài đặt
 * ở M9; `hourly`/`mixed` chờ `time_entries` (Task 12, chỉ dựng khung ở M9) — có mặt trong enum
 * để cột không phải đổi kiểu khi phần đó được cài, nhưng không có Action nào ghi ra hai giá trị
 * này ở M9.
 */
enum BillingModel: string
{
    case FixedFee = 'fixed_fee';
    case Hourly = 'hourly';
    case Mixed = 'mixed';

    public function label(): string
    {
        return __('enums.billing_model.'.$this->value);
    }
}
