<?php

namespace App\Enums;

/**
 * Một điều đang khoá ô CÂU CHUYỆN của một lần tiếp nhận (M10 R1 + R7a). Ô mở khi không còn cái nào;
 * `App\Actions\Intake\IntakeSummaryGate` là nơi duy nhất quyết định, và màn hình chỉ đọc kết quả
 * của nó để nói cho người nhập biết phải làm gì tiếp.
 */
enum IntakeSummaryBlocker: string
{
    /** R7a: chưa ghi nhận người liên hệ đã nghe thông báo và đồng ý. */
    case PrivacyNotice = 'privacy_notice';

    /** Chưa có lần kiểm tra xung đột nào, hoặc danh tính đã đổi kể từ lần kiểm tra gần nhất. */
    case ConflictUnchecked = 'conflict_unchecked';

    /** Vàng, hoặc "thiếu định danh": cần bấm xác nhận đã xem đúng những khớp đang hiện. */
    case ConflictAcknowledgement = 'conflict_acknowledgement';

    /** Đỏ: khoá cho tới khi quản lý/admin ghi đè kèm lý do. */
    case ConflictRed = 'conflict_red';

    /**
     * Văn phòng đã từ chối bản ghi (R8, M10 Task 3), vì bất kỳ lý do nào: câu chuyện không ghi thêm
     * được. Nhãn TRUNG TÍNH — không nói có phải vì xung đột không (người không có `intake.viewAny`
     * cũng đọc nhãn này).
     */
    case Declined = 'declined';

    public function label(): string
    {
        return __('enums.intake_summary_blocker.'.$this->value);
    }
}
