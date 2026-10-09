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
 * - `run()` bọc nó thành `BackupFailed` và ném lại;
 * - qua lệnh `backup:run` (CLI, lịch, `Artisan::call()`, cả tuỳ chọn `--config=`), đi đúng đường
 *   báo lỗi của gói: `BackupCommand::handle()` của gói bắt `BackupFailed`, phát
 *   `BackupHasFailed`, và `App\Notifications\Backup\BackupHasFailedNotification` xếp hàng thư báo
 *   lỗi nêu thông điệp của ngoại lệ này (fix I2, review vòng 1 — bản trước ném từ một lớp con của
 *   lệnh, TRƯỚC khối `try` đó, nên trên lịch chạy thật lỗi chỉ vào log);
 * - code gọi THẲNG `BackupJob::run()` (không qua lệnh) vẫn bị chặn — không archive, thư mục tạm
 *   bị xoá — nhưng KHÔNG có `BackupHasFailed` nào được phát, nên không có thư báo lỗi: người gọi
 *   chỉ nhận `BackupFailed` (có `getPrevious()` là ngoại lệ của guard) và tự xử lý. Phát sự kiện
 *   là việc của lệnh, không phải của `BackupJob`.
 *
 * Bản dump CSDL (nếu có) được tạo TRƯỚC sự kiện này, trong thư mục tạm của gói — y như mọi lượt
 * sao lưu bình thường trước khi nén — và bị khối `catch` nói trên xoá đi.
 *
 * Kiểm ở `tests/Feature/Backup/GuardBackupEncryptionTest.php`.
 *
 * Ở mọi môi trường khác (local, testing, staging) không chặn — máy dev không buộc phải cấu hình
 * đủ bí mật.
 *
 * # Cùng điều kiện ở hai cổng mở hệ thống (làn fc, kiểm tra nghiệp vụ 2026-10-09)
 *
 * {@see self::problem()} là điều kiện, tách khỏi việc ném lỗi, để `vkcrm:preflight`
 * (`RunPreflight`, dòng `backup_encryption`) và `vkcrm:backup-check` (`CheckBackupDestinations::
 * launchConditions()`) hỏi ĐÚNG câu mà guard này hỏi lúc 02:00, thay vì báo xanh cho một máy chủ mà
 * mọi lượt sao lưu đêm sẽ bị từ chối.
 */
class GuardBackupEncryption
{
    public function handle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $problem = self::problem(app(Config::class));

        if ($problem !== null) {
            throw $problem;
        }
    }

    /**
     * Lý do một lượt sao lưu trên cấu hình `$config` sẽ bị chặn, hoặc `null` khi nó sẽ được mã hoá —
     * không xét môi trường (người gọi quyết). Hai nguyên nhân theo đúng thứ tự `Zip` hỏi: mật khẩu
     * (`''` đã thành `null` ở `BackupConfig::fromArray()`), rồi thuật toán (`null` khi libzip thiếu
     * AES-256).
     */
    public static function problem(Config $config): ?BackupEncryptionRequired
    {
        $backup = $config->backup;

        if ($backup->password === null) {
            return BackupEncryptionRequired::passwordMissing();
        }

        if (! $backup->encryption->shouldEncrypt()) {
            return BackupEncryptionRequired::encryptionUnavailable();
        }

        return null;
    }
}
