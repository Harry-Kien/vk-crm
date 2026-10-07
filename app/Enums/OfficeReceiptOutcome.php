<?php

namespace App\Enums;

/**
 * Kết cục của MỘT tệp biên nhận văn phòng đã được CRM xử lý — cột `office_receipt_imports.outcome`
 * (M14 Task 6; kế hoạch R10; rà soát Task 7, r3). Sổ đó chỉ để biết một biên nhận ĐẾN MUỘN (tên nhỏ
 * hơn cursor) đã được đọc hay chưa; xem `App\Actions\Storage\ImportOfficeReceipts`.
 *
 * - `imported`: đọc được, đúng khuôn, ràng đúng Shared Drive; các dòng khớp đã được đánh dấu;
 * - `rejected`: bị từ chối cả tệp (quá cỡ, sai khuôn, Shared Drive hay thư mục gốc khác, `started_at`
 *   ở tương lai). Không đọc lại ở lượt sau: cách gỡ là đổi tên `receipted.txt` trên máy văn phòng.
 */
enum OfficeReceiptOutcome: string
{
    case Imported = 'imported';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('enums.office_receipt_outcome.'.$this->value);
    }
}
