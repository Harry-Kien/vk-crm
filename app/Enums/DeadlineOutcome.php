<?php

namespace App\Enums;

use App\Models\Deadline;

/**
 * Kết quả của MỘT mốc thời hạn trong một kỳ (M13, cột P1 "Mốc đúng hạn") — đầu ra của
 * {@see Deadline::outcomeAt()}, không lưu vào cột nào. Bảng ca biên (khi nào là "đúng hạn", "trễ",
 * "lỡ", và khi nào mốc không vào tập) nằm ở docblock của hàm đó.
 */
enum DeadlineOutcome: string
{
    /** Đánh dấu xong trước khi hết ngày đến hạn. */
    case OnTime = 'on_time';

    /** Xong sau ngày đến hạn nhưng không muộn hơn mốc cắt của kỳ (R19). */
    case Late = 'late';

    /** Chưa xong tới mốc cắt (hoặc xong sau mốc cắt — vẫn là "lỡ" của kỳ đó). */
    case Missed = 'missed';

    public function label(): string
    {
        return __('performance.outcomes.'.$this->value);
    }
}
