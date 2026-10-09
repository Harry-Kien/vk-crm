<?php

namespace App\Exceptions;

use App\Actions\Storage\ImportOfficeReceipts;
use App\Support\Storage\OfficeReceipt;
use RuntimeException;

/**
 * Cả một tệp biên nhận của máy văn phòng bị từ chối (M14 Task 7, kế hoạch R10): khuôn sai, thuộc
 * Shared Drive hay thư mục gốc khác, giờ bắt đầu ở tương lai, hoặc quá cỡ. Ném bởi
 * {@see OfficeReceipt::parse()}; {@see ImportOfficeReceipts} bắt, không đánh dấu dòng nào của tệp đó,
 * ghi lý do vào `system_health.last_office_receipt_error`, và cho cursor đi qua tệp.
 *
 * Thông điệp là lý do tiếng Việt (`lang/vi/office_copy.php`, nhóm `receipt`), không bao giờ mang mã
 * Drive hay nội dung biên nhận: nó đi vào thư cảnh báo và dòng kiểm sẵn sàng.
 */
class OfficeReceiptRejected extends RuntimeException
{
    /** @param  array<string, int|string>  $replace */
    public static function because(string $reason, array $replace = []): self
    {
        return new self(__('office_copy.receipt.'.$reason, $replace));
    }
}
