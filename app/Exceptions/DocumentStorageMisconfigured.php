<?php

namespace App\Exceptions;

use App\Support\Storage\GoogleDrive\DriveApiError;
use App\Support\Storage\MisconfiguredDriveAdapter;
use RuntimeException;
use Throwable;

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
 * Nguyên nhân gốc, khi có, là một {@see DriveApiError} ở `getPrevious()` (phương thức, đường mẫu, mã
 * trạng thái, lý do — cũng không bí mật, không mã tệp), chỉ đi vào log.
 */
class DocumentStorageMisconfigured extends RuntimeException
{
    /**
     * Thiếu khoá cấu hình của kho: {@see MisconfiguredDriveAdapter} ném lỗi này ở mọi lời gọi.
     *
     * @param  list<string>  $missing  tên biến môi trường còn thiếu
     */
    public static function notConfigured(array $missing): self
    {
        return new self(__('storage.exceptions.not_configured', ['missing' => implode(', ', $missing)]));
    }

    public static function credentialsMissing(): self
    {
        return new self(__('storage.exceptions.credentials_missing'));
    }

    /** Tệp khoá không có, không đọc được, không phải JSON, thiếu trường, hay khoá không ký được. */
    public static function credentialsUnusable(): self
    {
        return new self(__('storage.exceptions.credentials_unusable'));
    }

    /** Endpoint token từ chối JWT (`invalid_grant`: khoá đã xoá, hay đồng hồ máy chủ lệch). */
    public static function tokenRejected(string $error): self
    {
        return new self(__('storage.exceptions.token_rejected', ['error' => $error]));
    }

    /** Drive trả 401 cả sau khi đã lấy access token mới. */
    public static function credentialsRejected(?Throwable $previous = null): self
    {
        return new self(__('storage.exceptions.credentials_rejected'), 0, $previous);
    }

    /** 404 trên Shared Drive, thư mục gốc hay thư mục tháng — không phải trên một tệp. */
    public static function containerNotFound(?Throwable $previous = null): self
    {
        return new self(__('storage.exceptions.container_not_found'), 0, $previous);
    }

    /**
     * Một lỗi Drive không thử lại: câu riêng cho các lý do đã biết của R9 (hết dung lượng, chạm giới
     * hạn mục, thiếu quyền, mất tư cách thành viên), câu chung kèm mã và lý do cho phần còn lại.
     */
    public static function fromDrive(DriveApiError $error): self
    {
        $key = 'storage.exceptions.drive_reasons.'.$error->reason;

        $message = $error->reason !== null && trans()->has($key)
            ? __($key)
            : __('storage.exceptions.drive_rejected', ['status' => $error->status, 'reason' => $error->reason ?? '—']);

        return new self($message, 0, $error);
    }
}
