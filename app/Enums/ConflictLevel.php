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

    /**
     * Thứ tự nghiêm trọng tăng dần — M6.5 Task 8, fix round 1 (C1/`conflict-01`). Dùng để so "mức
     * đã được xác nhận cho một cặp bên" với "mức của lần chạy này": một cặp từng được xác nhận ở
     * Vàng KHÔNG được coi là đã xử lý một khớp Đỏ mới của CHÍNH cặp đó — chỉ một Đỏ đã thật sự
     * được ghi đè mới đủ để một Đỏ sau này không còn chặn lại. Xem `RunConflictCheck::
     * confirmedPairLevels()`.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Yellow => 1,
            self::Red => 2,
        };
    }
}
