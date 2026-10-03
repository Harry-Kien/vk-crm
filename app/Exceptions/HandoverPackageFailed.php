<?php

namespace App\Exceptions;

use App\Actions\Matter\BuildHandoverPackage;
use App\Jobs\GenerateHandoverPackage;
use RuntimeException;
use Throwable;

/**
 * Lỗi CÓ TÊN trong lúc dựng gói bàn giao ({@see BuildHandoverPackage}). Khác `DomainException`
 * của {@see HandoverPackageUnavailable}: đây là job chạy nền hỏng, không có ai đang nhìn — thông
 * điệp (tiếng Việt, không lộ đường dẫn máy chủ) là thứ được lưu vào
 * `matter_archives.handover_error` và hiện cho luật sư, còn `getPrevious()` (nếu có) đi vào log.
 *
 * Lỗi có tên là lỗi TẤT ĐỊNH (hoặc cần người sửa trước khi chạy lại): {@see GenerateHandoverPackage}
 * ghi nó ngay, không thử lại — một lần thử lại chỉ nén lại toàn bộ gói rồi hỏng y hệt.
 */
class HandoverPackageFailed extends RuntimeException
{
    public static function matterGone(): self
    {
        return new self(__('handover.exceptions.matter_gone'));
    }

    public static function notClosed(): self
    {
        return new self(__('handover.exceptions.not_closed'));
    }

    public static function missingFile(string $title): self
    {
        return new self(__('handover.exceptions.missing_file', ['title' => $title]));
    }

    public static function zipFailed(): self
    {
        return new self(__('handover.exceptions.zip_failed'));
    }

    public static function indexFailed(?Throwable $previous = null): self
    {
        return new self(__('handover.exceptions.index_failed'), 0, $previous);
    }

    public static function noUploader(): self
    {
        return new self(__('handover.exceptions.no_uploader'));
    }

    /**
     * Rà soát cuối M7, I2: version gói hiện tại đã được công bố cho khách trong lúc job chờ hàng.
     * Job không tự gỡ nó khỏi cổng ("một đường rút duy nhất", Task 7) — kiểm dưới khoá trong
     * `BuildHandoverPackage::store()`, cùng luật với lời từ chối lúc bấm nút
     * ({@see HandoverPackageUnavailable::released()}).
     */
    public static function previousReleased(): self
    {
        return new self(__('handover.exceptions.previous_released'));
    }

    /**
     * Vòng sửa 1: thư mục tạm dựng gói (`HANDOVER_WORK_DIR`) không tạo/ghi được — đĩa đầy, mất
     * quyền ghi. PHP báo những lỗi này bằng cảnh báo, Laravel đổi thành `ErrorException`.
     */
    public static function workDirectoryFailed(Throwable $previous): self
    {
        return new self(__('handover.exceptions.work_dir_failed'), 0, $previous);
    }

    /**
     * Vòng sửa 1: zip đã dựng xong nhưng lớn hơn trần MỘT tệp của kho hồ sơ
     * (`media-library.max_file_size`, biến `MEDIA_MAX_FILE_SIZE_MB`).
     */
    public static function tooLarge(int $bytes, int $limitBytes, Throwable $previous): self
    {
        return new self(__('handover.exceptions.too_large', [
            'size' => self::megabytes($bytes),
            'limit' => self::megabytes($limitBytes),
        ]), 0, $previous);
    }

    /**
     * Vòng sửa 1: medialibrary không lưu được zip vào đĩa `private` (đĩa từ chối ghi, đĩa chưa
     * cấu hình, tệp nguồn biến mất giữa chừng — mọi `FileCannotBeAdded` khác `FileIsTooBig`).
     */
    public static function storeFailed(Throwable $previous): self
    {
        return new self(__('handover.exceptions.store_failed'), 0, $previous);
    }

    /** MB theo cách viết số tiếng Việt, một chữ số thập phân, bỏ ",0": `1,5`, `1`, `2.048`. */
    private static function megabytes(int $bytes): string
    {
        $formatted = number_format($bytes / (1024 * 1024), 1, ',', '.');

        return str_ends_with($formatted, ',0') ? substr($formatted, 0, -2) : $formatted;
    }
}
