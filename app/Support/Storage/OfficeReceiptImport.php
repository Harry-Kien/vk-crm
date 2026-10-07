<?php

namespace App\Support\Storage;

use App\Actions\Storage\ImportOfficeReceipts;

/**
 * Kết quả của một lượt {@see ImportOfficeReceipts} (M14 Task 7): chỉ số đếm và câu lỗi tiếng Việt,
 * không khoá đối tượng, không mã Drive — lệnh `vkcrm:storage:office-receipts` in thẳng nó.
 *
 * - `configured = false`: chưa cấu hình máy văn phòng (hoặc Shared Drive/thư mục gốc), không tiến
 *   trình nào chạy.
 * - `busy`: lượt khác đang giữ khoá `storage-office-receipts`, không tiến trình nào chạy.
 * - `rcloneFailed`: một lệnh `rclone` hỏng; đã phát `BackupHasFailed('rclone:office-receipts')`.
 * - `imported`/`rejected`: số tệp biên nhận nhận và từ chối cả tệp.
 * - `marked`: dòng chỉ mục vừa được đặt `office_copied_at`; `alreadyMarked`: dòng khớp nhưng đã có
 *   biên nhận từ trước; `unmatched`: tên đọc ngược được mà không có dòng SỐNG nào cùng khoá + thế hệ
 *   trên Shared Drive đang cấu hình; `mismatched`: có dòng sống cùng khoá + thế hệ nhưng md5 hoặc
 *   cỡ khác (tín hiệu tệp bị đổi trên kho); `unknownNames`: tên không đọc ngược được.
 * - `errors`: các câu đã ghi vào `system_health.last_office_receipt_error`.
 */
final class OfficeReceiptImport
{
    /** @param  list<string>  $errors */
    public function __construct(
        public readonly bool $configured = true,
        public readonly bool $busy = false,
        public readonly bool $rcloneFailed = false,
        public readonly int $imported = 0,
        public readonly int $rejected = 0,
        public readonly int $marked = 0,
        public readonly int $alreadyMarked = 0,
        public readonly int $unmatched = 0,
        public readonly int $mismatched = 0,
        public readonly int $unknownNames = 0,
        public readonly array $errors = [],
    ) {}

    public static function notConfigured(): self
    {
        return new self(configured: false);
    }

    public static function busy(): self
    {
        return new self(busy: true);
    }
}
