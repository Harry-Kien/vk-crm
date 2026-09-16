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
    /**
     * Độ dài tối đa của phần đuôi tệp được chèn lại vào thông điệp. Đuôi là chuỗi do client đặt
     * tên tệp nên nó dài bao nhiêu cũng được: một đuôi 3000 ký tự từng sinh ra một thông điệp
     * 3191 ký tự đi thẳng ra màn hình khách và vào log.
     */
    private const EXTENSION_ECHO_LIMIT = 20;

    public static function extensionNotAllowed(string $extension): self
    {
        return $extension === ''
            ? new self(__('documents.file_guard.extension_missing'))
            : new self(__('documents.file_guard.extension_not_allowed', ['extension' => self::echoable($extension)]));
    }

    /**
     * MIME thật đọc bằng `finfo` không khớp với đuôi tệp đã khai báo — đây là nhánh bắt được tệp
     * thực thi giả dạng tài liệu (SPEC §11 "Tải tệp": `.pdf` nhưng `application/x-dosexec`).
     *
     * Cố ý KHÔNG nhận MIME thật làm tham số: thông điệp không được nói ra thứ hệ thống đọc được
     * (xem docblock `lang/vi/documents.php`). `FileGuard` ghi MIME đó vào log trước khi ném.
     */
    public static function contentMismatch(string $extension): self
    {
        return new self(__('documents.file_guard.content_mismatch', [
            'extension' => self::echoable($extension),
        ]));
    }

    /**
     * Đuôi là `docx`/`xlsx` và byte đầu tệp đúng là một gói ZIP, nhưng bên trong không có cấu
     * trúc bắt buộc của Office Open XML — tức đây là một ZIP tuỳ ý được đặt tên `.docx`, không
     * phải một tài liệu Word/Excel. Xem docblock `FileGuard::verifyOfficePackage()`.
     */
    public static function notAnOfficePackage(string $extension): self
    {
        return new self(__('documents.file_guard.not_office_package', [
            'extension' => self::echoable($extension),
        ]));
    }

    /**
     * Gói Office Open XML có mang một dự án VBA (`vbaProject.bin`) — tức một tệp `.docm`/`.xlsm`
     * đội tên `.docx`/`.xlsx`. Danh sách trắng SPEC §6.6 không có đuôi macro nào.
     */
    public static function macroContent(): self
    {
        return new self(__('documents.file_guard.macro_content'));
    }

    /**
     * Không mở được gói để kiểm tra bên trong: gói hỏng, hoặc `ext-zip` không có trên máy chủ.
     * Từ chối thay vì cho qua — một gói không kiểm tra được không phải một gói đã kiểm tra xong,
     * cùng một lý lẽ với `scannerUnavailable()`.
     */
    public static function packageUnreadable(): self
    {
        return new self(__('documents.file_guard.package_unreadable'));
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

    /**
     * Cắt một chuỗi do client kiểm soát xuống độ dài chèn được vào câu thông điệp. Không thay
     * ký tự nào: `FileGuard::check()` đã chặn ký tự điều khiển trước khi tới được đây, nên phần
     * còn lại chỉ là chữ thường và việc duy nhất cần làm là giới hạn độ dài.
     */
    private static function echoable(string $value): string
    {
        return mb_strimwidth($value, 0, self::EXTENSION_ECHO_LIMIT, '…');
    }
}
