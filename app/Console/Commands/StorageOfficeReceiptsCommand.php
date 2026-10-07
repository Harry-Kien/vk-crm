<?php

namespace App\Console\Commands;

use App\Actions\Storage\ImportOfficeReceipts;
use Illuminate\Console\Command;

/**
 * `vkcrm:storage:office-receipts` (M14 Task 7, kế hoạch R10) — nhập biên nhận bản thứ hai của máy văn
 * phòng. Mục lịch `storage.office-receipts` (07:00) chạy chính lệnh này; người cài đặt chạy tay sau khi
 * điền `DOCUMENT_OFFICE_RECEIPTS_PATH` (Phụ lục D, bước 8 của `docs/SAO-LUU-KHOI-PHUC.md`). Nghiệp vụ và
 * khoá chống chạy chồng `storage-office-receipts` ở {@see ImportOfficeReceipts}; lớp này chỉ in số đếm
 * và chọn mã thoát.
 *
 * Mã thoát: 0 khi nhập xong không biên nhận nào bị từ chối, hoặc khi chưa cấu hình (không có gì để
 * làm, không phải lỗi); 1 khi lượt khác đang chạy, khi `rclone` hỏng, hoặc khi có biên nhận bị từ chối.
 * Biên nhận báo `errors > 0` hay có tệp lệch md5 vẫn là 0: các dòng khớp đã được ghi, và câu lỗi đã nằm
 * ở dòng sức khoẻ `document_office_copy` và thư cảnh báo kho — lệnh in lại chúng.
 */
class StorageOfficeReceiptsCommand extends Command
{
    protected $signature = 'vkcrm:storage:office-receipts';

    protected $description = 'Nhập biên nhận bản thứ hai của máy văn phòng vào chỉ mục kho tài liệu (M14)';

    public function handle(ImportOfficeReceipts $import): int
    {
        $result = $import->handle();

        if (! $result->configured) {
            $this->info(__('office_copy.command.not_configured'));

            return self::SUCCESS;
        }

        if ($result->busy) {
            $this->warn(__('office_copy.command.busy'));

            return self::FAILURE;
        }

        foreach ([
            'imported' => $result->imported,
            'rejected' => $result->rejected,
            'marked' => $result->marked,
            'already' => $result->alreadyMarked,
            'unmatched' => $result->unmatched,
            'mismatched' => $result->mismatched,
            'unknown' => $result->unknownNames,
        ] as $line => $count) {
            $this->line(__('office_copy.command.'.$line, ['count' => $count]));
        }

        foreach ($result->errors as $error) {
            $this->warn($error);
        }

        if ($result->rcloneFailed) {
            $this->error(__('office_copy.command.rclone_failed'));
        }

        return $result->rcloneFailed || $result->rejected > 0 ? self::FAILURE : self::SUCCESS;
    }
}
