<?php

namespace App\Exceptions;

use DomainException;

/**
 * Tệp bị `FileGuard` (SPEC §6.6 bước 2-4) hoặc một `VirusScanner` (bước 5) từ chối. Mỗi factory
 * method ứng với đúng MỘT lý do từ chối, và mỗi lý do có một thông điệp tiếng Việt riêng nói rõ
 * việc cần làm tiếp theo — SPEC §8.4 cấm tường minh kiểu thông điệp "Upload failed" không nói gì
 * thêm. Không dùng một message chung cho mọi lỗi: một trợ lý đang thao tác vội hoặc một khách
 * hàng đang chụp ảnh trên điện thoại cần biết CHÍNH XÁC phải sửa gì, không phải chỉ biết "đã
 * hỏng".
 *
 * Đây cũng là exception DUY NHẤT mà `FileGuard::check()` được phép ném (xem docblock lớp đó) —
 * mọi nhánh lỗi, kể cả những nhánh không có trong SPEC §11 (tệp rỗng, không có phần mở rộng, tên
 * chứa byte rỗng, đường dẫn không tồn tại), đều đi qua một trong các factory method dưới đây thay
 * vì để lộ ra một `TypeError`/`ValueError` của PHP hay một exception hạ tầng khác.
 */
class FileRejected extends DomainException
{
    public static function extensionNotAllowed(string $extension): self
    {
        return $extension === ''
            ? new self(__('documents.file_guard.extension_missing'))
            : new self(__('documents.file_guard.extension_not_allowed', ['extension' => $extension]));
    }

    /**
     * MIME thật đọc bằng `finfo` không khớp với đuôi tệp đã khai báo — đây là nhánh bắt được tệp
     * thực thi giả dạng tài liệu (SPEC §11 "Tải tệp": `.pdf` nhưng `application/x-dosexec`).
     */
    public static function contentMismatch(string $extension, ?string $realMimeType): self
    {
        return new self(__('documents.file_guard.content_mismatch', [
            'extension' => $extension,
            'mime' => $realMimeType ?? __('documents.file_guard.mime_unknown'),
        ]));
    }

    public static function tooLarge(int $maxMegabytes): self
    {
        return new self(__('documents.file_guard.too_large', ['max' => $maxMegabytes]));
    }

    public static function empty(): self
    {
        return new self(__('documents.file_guard.empty'));
    }

    /**
     * Đường dẫn không đọc được: không tồn tại, đã bị xoá giữa lúc kiểm tra, hoặc (trong luồng
     * upload thật) tệp tạm của PHP biến mất vì một lỗi tải lên không rơi vào các mã lỗi
     * `UPLOAD_ERR_*` mà `uploadFailed()` xử lý riêng.
     */
    public static function unreadable(): self
    {
        return new self(__('documents.file_guard.unreadable'));
    }

    /**
     * Tên tệp chứa byte rỗng (`\0`). Không phải một lỗ hổng thật trong PHP 8.3 (`is_file()` trên
     * một đường dẫn như vậy chỉ trả `false`, không thực thi gì) nhưng vẫn bị chặn tường minh trước
     * khi chạm tới bất kỳ hàm hệ thống tệp nào — `finfo_file()` thì THẬT SỰ ném `TypeError` với
     * byte rỗng, và một tên tệp như vậy không bao giờ là một cái tên hợp lệ để bắt đầu.
     */
    public static function invalidName(): self
    {
        return new self(__('documents.file_guard.invalid_name'));
    }

    /**
     * Upload PHP có mã lỗi khác `UPLOAD_ERR_OK` (ví dụ đứt kết nối giữa chừng) — tệp tạm không
     * đáng tin hoặc không tồn tại, không nên đọc tiếp.
     */
    public static function uploadFailed(): self
    {
        return new self(__('documents.file_guard.upload_failed'));
    }

    /**
     * `VirusScanner::scan()` phát hiện mã độc (SPEC §6.6 bước 5).
     */
    public static function virusDetected(): self
    {
        return new self(__('documents.file_guard.virus_detected'));
    }

    /**
     * `ClamAvScanner` bật (`CLAMAV_ENABLED=true`) nhưng không kết nối được daemon hoặc daemon trả
     * lời không hiểu được. Từ chối tệp thay vì âm thầm coi là sạch — một tệp không quét được không
     * phải một tệp sạch (xem docblock `ClamAvScanner`).
     */
    public static function scannerUnavailable(): self
    {
        return new self(__('documents.file_guard.scanner_unavailable'));
    }
}
