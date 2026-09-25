<?php

namespace App\Enums;

/**
 * Vòng đời hợp đồng dịch vụ pháp lý (M9 §"Mô hình dữ liệu" — `contracts.status`).
 * `draft` xoá cứng được (chưa có khoản thu nào); từ `active` trở đi chỉ đóng lại bằng
 * `completed` hoặc `cancelled`, không bao giờ xoá — xem `Contract::booted()`.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.contract_status.'.$this->value);
    }
}
