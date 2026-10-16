<?php

namespace App\Enums;

use App\Models\MatterUser;

/**
 * Tình trạng NHẬN HỒ SƠ của một ghế trong đội ngũ vụ việc (làn tk, Task 2). Không phải một cột: đọc từ
 * hai mốc của ghế ({@see MatterUser::assignmentStatus()}) — `accepted_at` có thì đã nhận, `declined_at`
 * có thì đã trả lại, không mốc nào thì đang chờ người đó bấm "Đã nhận hồ sơ".
 *
 * "Trả lại" không gỡ người khỏi vụ: ghế vẫn còn, mang lý do, cho tới khi quản lý hay luật sư phụ trách
 * phân công lại (gỡ người đó, hay bàn giao vụ cho người khác).
 */
enum MatterAssignmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return __('tasks.seat.status.'.$this->value);
    }

    /** Màu badge — chỉ hiển thị. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Declined => 'danger',
        };
    }
}
