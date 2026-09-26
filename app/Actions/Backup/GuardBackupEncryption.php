<?php

namespace App\Actions\Backup;

use App\Console\Commands\BackupCommand;
use App\Exceptions\BackupPasswordRequired;

/**
 * SPEC §10 mục 8, R3 — chặn `backup:run` ở môi trường `production` khi
 * `BACKUP_ARCHIVE_PASSWORD` (`config('backup.backup.password')`) rỗng.
 *
 * Đọc `config('backup.backup.password')` chứ không `env('BACKUP_ARCHIVE_PASSWORD')` trực tiếp:
 * `env()` đọc thẳng biến môi trường trên đĩa, bỏ qua `config:cache` — trên shared hosting nơi
 * `config:cache` gần như luôn bật, một `env()` gọi ngoài tệp cấu hình sẽ luôn thấy `null` sau
 * khi cache được dựng, dù `.env` có gì.
 *
 * Ở mọi môi trường khác (local, testing, staging) mật khẩu rỗng KHÔNG bị chặn — máy dev không
 * buộc phải cấu hình đủ bí mật, và §11 cần chạy được `backup:run` không mật khẩu để kiểm tra
 * đường "chưa mã hoá".
 *
 * Được gọi bởi {@see BackupCommand::handle()}, lớp con của
 * `Spatie\Backup\Commands\BackupCommand` được cắm vào container thay bản gốc của gói (xem
 * `AppServiceProvider::register()`) — nên MỌI đường gọi `backup:run` (CLI, `Artisan::call()`,
 * lịch ở `routes/console.php`) đều đi qua đây, không có đường tắt nào bỏ qua được guard này.
 */
class GuardBackupEncryption
{
    public function __invoke(): void
    {
        $this->handle();
    }

    public function handle(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (filled(config('backup.backup.password'))) {
            return;
        }

        throw BackupPasswordRequired::make();
    }
}
