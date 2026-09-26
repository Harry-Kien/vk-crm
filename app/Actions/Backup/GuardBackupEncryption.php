<?php

namespace App\Actions\Backup;

use App\Exceptions\BackupEncryptionRequired;
use Spatie\Backup\Config\Config;

/**
 * SPEC §10 mục 8, R3 — ở `production`, chặn một lượt sao lưu sắp tạo archive KHÔNG MÃ HOÁ.
 *
 * # Điều kiện: đúng điều kiện mà `Spatie\Backup\Tasks\Backup\Zip` dùng để mã hoá
 *
 * `Zip::__construct()` chỉ mã hoá khi `$config->backup->password !== null` VÀ
 * `$config->backup->encryption->algorithm()` khác `null`. Guard hỏi đúng hai điều đó, trên đúng
 * đối tượng `Spatie\Backup\Config\Config` mà `Zip` đọc (`app(Config::class)`), chứ không đọc
 * `config('backup.backup.password')` thô:
 *
 * - `BackupConfig::fromArray()` đổi mật khẩu `''` thành `null`, nên "rỗng" ở đây trùng khớp với
 *   "rỗng" ở `Zip`;
 * - `Encryption::algorithm()` trả `null` khi `ZipArchive::EM_AES_256` không tồn tại (libzip cũ)
 *   — khi đó có mật khẩu mà archive vẫn KHÔNG mã hoá, không lỗi nào. `shouldEncrypt()` bắt đúng
 *   trường hợp này (fix I3, review vòng 1);
 * - với `backup:run --config=...`, gói đặt lại `app(Config::class)` bằng cấu hình thay thế
 *   (`BaseCommand::resolveConfig()`), nên guard kiểm đúng cấu hình đang chạy.
 *
 * # Chỗ móc: bên trong `BackupJob::run()`, không phải trước lệnh
 *
 * `AppServiceProvider::boot()` đăng ký `handle()` nghe `Spatie\Backup\Events\
 * BackupManifestWasCreated`. Sự kiện đó phát trong `BackupJob::createBackupManifest()`, tức BÊN
 * TRONG khối `try` của `BackupJob::run()` và TRƯỚC khi zip được dựng. Ngoại lệ ném ở đây vì vậy:
 *
 * - không để lại archive nào (khối `catch` của `run()` xoá thư mục tạm, không bản nào được chép
 *   ra disk đích);
 * - đi đúng đường báo lỗi của gói: `run()` bọc nó thành `BackupFailed`, `BackupCommand::handle()`
 *   của gói bắt, phát `BackupHasFailed`, và `App\Notifications\Backup\BackupHasFailedNotification`
 *   xếp hàng thư báo lỗi nêu thông điệp của ngoại lệ này (fix I2, review vòng 1 — bản trước ném
 *   từ một lớp con của lệnh, TRƯỚC khối `try` đó, nên trên lịch chạy thật lỗi chỉ vào log);
 * - áp cho mọi đường tạo archive: lệnh `backup:run` (CLI, lịch, `Artisan::call()`), tuỳ chọn
 *   `--config=`, và cả code gọi thẳng `BackupJob::run()`.
 *
 * Bản dump CSDL (nếu có) được tạo TRƯỚC sự kiện này, trong thư mục tạm của gói — y như mọi lượt
 * sao lưu bình thường trước khi nén — và bị khối `catch` nói trên xoá đi.
 *
 * Kiểm ở `tests/Feature/Backup/GuardBackupEncryptionTest.php`.
 *
 * Ở mọi môi trường khác (local, testing, staging) không chặn — máy dev không buộc phải cấu hình
 * đủ bí mật.
 */
class GuardBackupEncryption
{
    public function handle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $backup = app(Config::class)->backup;

        if ($backup->password === null) {
            throw BackupEncryptionRequired::passwordMissing();
        }

        if (! $backup->encryption->shouldEncrypt()) {
            throw BackupEncryptionRequired::encryptionUnavailable();
        }
    }
}
