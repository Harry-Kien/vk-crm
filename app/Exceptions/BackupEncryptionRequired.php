<?php

namespace App\Exceptions;

use App\Actions\Backup\GuardBackupEncryption;
use DomainException;

/**
 * Ở `production`, một lượt sao lưu sắp tạo ra archive KHÔNG MÃ HOÁ (SPEC §10 mục 8, R3) — ném
 * bởi {@see GuardBackupEncryption}. Hai nguyên nhân, mỗi nguyên nhân một thông điệp riêng để
 * người nhận thư báo lỗi biết phải sửa gì:
 *
 * - {@see self::passwordMissing()}: `BACKUP_ARCHIVE_PASSWORD` rỗng;
 * - {@see self::encryptionUnavailable()}: có mật khẩu, nhưng máy chủ không mã hoá được bằng
 *   thuật toán đã cấu hình (ví dụ libzip cũ thiếu `ZipArchive::EM_AES_256`) — gói khi đó lặng lẽ
 *   ghi archive không mã hoá.
 *
 * R3: "Bản sao chứa tệp hồ sơ thô, nên archive được mã hoá bằng mật khẩu." Một bản sao lưu
 * không mã hoá trót lọt ra Google Drive không phải một sự cố dễ thấy — nó nằm im cho tới lần
 * khôi phục thử. Vì vậy lượt sao lưu THẤT BẠI RÕ RÀNG, và thất bại đó đi qua đường báo lỗi của
 * gói (`BackupHasFailed` → thư báo lỗi), không chỉ vào log.
 *
 * Thông điệp không bao giờ chứa giá trị mật khẩu.
 */
class BackupEncryptionRequired extends DomainException
{
    public static function passwordMissing(): self
    {
        return new self(__('backup.errors.password_required_in_production'));
    }

    public static function encryptionUnavailable(): self
    {
        return new self(__('backup.errors.encryption_unavailable_in_production'));
    }
}
