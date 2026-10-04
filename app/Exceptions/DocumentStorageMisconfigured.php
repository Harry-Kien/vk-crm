<?php

namespace App\Exceptions;

use App\Support\Storage\MisconfiguredDriveAdapter;
use RuntimeException;

/**
 * Kho tài liệu (Google Drive) hỏng vì CẤU HÌNH, không phải vì mạng (kế hoạch M14, R7, R9): thiếu
 * khoá tài khoản dịch vụ, hết hạn mức, mất quyền, Shared Drive hay thư mục gốc không tồn tại. Thử
 * lại không giúp gì; luật cho nơi bắt (R9) là không thử lại, báo người vận hành sửa.
 *
 * Adapter của đĩa `documents_remote` dựng LƯỜI: thiếu cấu hình thì lỗi này ném ra lúc DÙNG đĩa,
 * không lúc khởi động ứng dụng — một khoá Drive bị thiếu không được làm hỏng mọi request và mọi lệnh
 * Artisan, kể cả khi công tắc `DOCUMENT_STORAGE` là `local`.
 *
 * Thông điệp là câu tiếng Việt đọc được: không bí mật, không đường dẫn khoá, không mã tệp Drive.
 */
class DocumentStorageMisconfigured extends RuntimeException
{
    /**
     * M14 Task 1: đĩa `documents_remote` đã có trong cấu hình nhưng trình kết nối Drive thật chưa
     * được cài — {@see MisconfiguredDriveAdapter} ném lỗi này ở mọi lời gọi.
     */
    public static function adapterNotInstalled(): self
    {
        return new self(__('storage.exceptions.adapter_not_installed'));
    }
}
