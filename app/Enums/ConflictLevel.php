<?php

namespace App\Enums;

/**
 * Mức xung đột lợi ích của một bản ghi trùng, hoặc mức tổng hợp của cả lần kiểm tra
 * (SPEC §6.10 bước 3). `Green` không bao giờ gắn vào một `ConflictMatch` — nó chỉ là mức tổng
 * hợp khi không tìm thấy bản ghi trùng nào.
 */
enum ConflictLevel: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function label(): string
    {
        return __('conflicts.level.'.$this->value);
    }
}
