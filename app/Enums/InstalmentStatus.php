<?php

namespace App\Enums;

/**
 * Trạng thái LƯU của một đợt thanh toán (`instalments.status`) — chỉ mang sự thật KHÔNG phụ
 * thuộc hôm nay: đã thu đủ, đã miễn, đã huỷ, hay còn đang chờ. Đúng bốn giá trị, cố ý không có
 * `overdue`: quá hạn là hàm của thời gian (hôm nay so với `due_date`), không phải một sự kiện ai
 * đó ghi vào — xem {@see InstalmentState}, thứ SUY RA bảy trạng thái hiển thị từ
 * enum này cộng `due_date` và tổng các khoản thu, ở đúng một chỗ: `Instalment::state()`.
 */
enum InstalmentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.instalment_status.'.$this->value);
    }
}
