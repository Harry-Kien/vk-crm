<?php

namespace App\Enums;

/**
 * Trạng thái một lần có người liên hệ văn phòng (M10, bảng `intake_requests`).
 *
 * `New` là trạng thái duy nhất CHƯA có phản hồi: lần đầu một bản ghi rời `new` là lúc điền
 * `first_response_at` (R5, đo thời gian phản hồi). `Won`, `Declined`, `Lost`, `Merged` là bốn trạng
 * thái cuối: `Won` do việc chuyển thành vụ việc (R3), `Merged` do gộp bản ghi trùng (R4) — hai
 * trạng thái đó chỉ Action tương ứng được đặt. `Declined`, `Lost` và `Merged` (những bản ghi KHÔNG
 * thành khách) mang `retention_until` rồi bị ẩn danh (R7b).
 */
enum IntakeStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Consulting = 'consulting';
    case Quoted = 'quoted';
    case Won = 'won';
    case Declined = 'declined';
    case Lost = 'lost';
    case Merged = 'merged';

    public function label(): string
    {
        return __('enums.intake_status.'.$this->value);
    }

    /**
     * Ba trạng thái cuối của một người KHÔNG thành khách (M10 R7b, Task 7): vào một trong ba thì bản
     * ghi nhận hạn lưu `retention_until` (`IntakeRequest::stampRetention()`) và hết hạn thì bị ẩn
     * danh. `Won` không có: người đó đã là khách, dữ liệu theo hồ sơ khách.
     */
    public function startsRetention(): bool
    {
        return in_array($this, [self::Declined, self::Lost, self::Merged], true);
    }
}
