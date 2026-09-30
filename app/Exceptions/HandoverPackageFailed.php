<?php

namespace App\Exceptions;

use App\Actions\Matter\BuildHandoverPackage;
use RuntimeException;

/**
 * Lỗi CÓ TÊN trong lúc dựng gói bàn giao ({@see BuildHandoverPackage}). Khác `DomainException`
 * của {@see HandoverPackageUnavailable}: đây là job chạy nền hỏng, không có ai đang nhìn — thông
 * điệp (tiếng Việt, không lộ đường dẫn máy chủ) là thứ được lưu vào
 * `matter_archives.handover_error` và hiện cho luật sư, còn `getPrevious()` (nếu có) đi vào log.
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

    public static function indexFailed(?\Throwable $previous = null): self
    {
        return new self(__('handover.exceptions.index_failed'), 0, $previous);
    }

    public static function noUploader(): self
    {
        return new self(__('handover.exceptions.no_uploader'));
    }
}
