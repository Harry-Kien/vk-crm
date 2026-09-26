<?php

use App\Actions\Backup\GuardBackupEncryption;
use App\Exceptions\BackupPasswordRequired;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\Config\Config;

/*
|--------------------------------------------------------------------------
| §10.8 / R3 — production không có BACKUP_ARCHIVE_PASSWORD thì backup:run từ chối chạy
|--------------------------------------------------------------------------
|
| Hai test "không chặn" gọi thẳng `GuardBackupEncryption` — không qua `backup:run` thật — để
| không phụ thuộc kết quả một lượt sao lưu thật (đó là việc của `BackupRunIntegrationTest`). Test
| "có chặn" gọi qua `Artisan::call('backup:run')` để khoá luôn cả đường móc vào container
| (`App\Console\Commands\BackupCommand` thế chỗ bản gốc của gói — xem `AppServiceProvider`),
| không chỉ khoá bản thân Action.
*/

function rebindBackupConfig(): void
{
    // `Spatie\Backup\Config\Config` là một `scoped` singleton dựng MỘT LẦN từ `config('backup')`
    // — đổi `config()` sau đó không tự áp dụng. `Config::rebind()` là móc @internal của chính
    // gói dành cho đúng việc này.
    Config::rebind();
}

it('§10.8 chặn backup:run (qua Artisan::call) khi production thiếu BACKUP_ARCHIVE_PASSWORD', function () {
    app()['env'] = 'production';
    config(['backup.backup.password' => null]);
    rebindBackupConfig();

    expect(fn () => Artisan::call('backup:run'))
        ->toThrow(BackupPasswordRequired::class, __('backup.errors.password_required_in_production'));
});

it('§10.8 không chặn ở production khi đã có BACKUP_ARCHIVE_PASSWORD', function () {
    app()['env'] = 'production';
    config(['backup.backup.password' => 'mat-khau-gia-lap']);
    rebindBackupConfig();

    expect(fn () => app(GuardBackupEncryption::class)->handle())->not->toThrow(BackupPasswordRequired::class);
});

it('§10.8 không chặn ở môi trường testing dù thiếu mật khẩu', function () {
    expect(app()->environment())->toBe('testing');

    config(['backup.backup.password' => null]);
    rebindBackupConfig();

    expect(fn () => app(GuardBackupEncryption::class)->handle())->not->toThrow(BackupPasswordRequired::class);
});
