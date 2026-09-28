<?php

namespace App\Enums;

/**
 * Trạng thái HIỂN THỊ của một đợt thanh toán — SUY RA, không lưu cột nào. Đây là "bảy trạng thái
 * hiển thị" đứng cạnh "bốn trạng thái lưu" của {@see InstalmentStatus} (M9
 * §"Mô hình dữ liệu", mục `instalments`). Tính ở đúng MỘT chỗ: `Instalment::state()`; không nơi
 * nào khác được suy luận lại `overdue`/`due`/`partially_paid` bằng mắt hay bằng một điều kiện
 * viết tay thứ hai.
 */
enum InstalmentState: string
{
    /** `status = pending`, chưa có `due_date` (chưa ký nếu `on_signing`, chưa chạm giai đoạn nếu `stage`). */
    case Scheduled = 'scheduled';

    /** `status = pending`, có `due_date`, `due_date` chưa qua, chưa thu đồng nào. */
    case Due = 'due';

    /** `status = pending`, có `due_date`, `due_date` đã qua, chưa thu đủ — KỂ CẢ đã thu một phần (I1). */
    case Overdue = 'overdue';

    /** `status = pending`, `due_date` chưa qua, đã thu một phần (SUM khoản thu chưa huỷ > 0 và < `amount`). */
    case PartiallyPaid = 'partially_paid';

    /** `status = paid`, HOẶC `status = pending` mà tổng khoản thu chưa huỷ đã ≥ `amount`. */
    case Paid = 'paid';

    /** `status = waived`. */
    case Waived = 'waived';

    /** `status = cancelled`. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('enums.instalment_state.'.$this->value);
    }
}
