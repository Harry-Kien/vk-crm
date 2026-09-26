<?php

namespace App\Exceptions;

use App\Actions\Backup\GuardBackupEncryption;
use DomainException;

/**
 * `production` mà `BACKUP_ARCHIVE_PASSWORD` rỗng (SPEC §10 mục 8, R3) — ném bởi
 * {@see GuardBackupEncryption}, chặn `backup:run` trước khi nó tạo ra một
 * bản sao lưu KHÔNG MÃ HOÁ chứa tệp hồ sơ thô và một bản dump CSDL mang `clients.id_number`.
 *
 * R3: "Bản sao chứa tệp hồ sơ thô, nên archive được mã hoá bằng mật khẩu." Một bản sao lưu
 * không mã hoá trót lọt ra Google Drive không phải một sự cố dễ thấy — nó nằm im cho tới lần
 * khôi phục thử, đúng lúc không còn sửa được nữa. Vì vậy lệnh THẤT BẠI RÕ RÀNG ngay lúc chạy,
 * thay vì âm thầm tạo archive không mã hoá.
 */
class BackupPasswordRequired extends DomainException
{
    public static function make(): self
    {
        return new self(__('backup.errors.password_required_in_production'));
    }
}
