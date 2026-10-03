<?php

namespace App\Enums;

/**
 * Trạng thái một lần có người liên hệ văn phòng (M10, bảng `intake_requests`).
 *
 * `New` là trạng thái duy nhất CHƯA có phản hồi: lần đầu một bản ghi rời `new` bằng đổi trạng thái, từ
 * chối hay chuyển thành vụ việc là lúc điền `first_response_at` (R5, đo thời gian phản hồi); rời `new`
 * vì bị GỘP thì không — bản gộp đi là bản trùng, không phải một lần phản hồi (Task 5). Không có đường
 * quay về `new`. `Won`, `Declined`, `Lost`, `Merged` là bốn trạng
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
}
