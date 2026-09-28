<?php

use App\Actions\Backup\GuardBackupEncryption;
use App\Exceptions\BackupEncryptionRequired;
use App\Notifications\Backup\BackupHasFailedNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Notifications\EventHandler;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;

/*
|--------------------------------------------------------------------------
| §10.8 / R3 — production không mã hoá được thì KHÔNG có archive, và CÓ thư báo lỗi
|--------------------------------------------------------------------------
|
| Guard chạy BÊN TRONG `BackupJob::run()` (nghe `BackupManifestWasCreated`, xem
| `AppServiceProvider::boot()`), nên các test "có chặn" đi đường thật: `backup:run` chạy tới
| bước dựng manifest, guard ném, khối `catch` của gói phát `BackupHasFailed`, và thư báo lỗi của
| dự án được xếp hàng. Không test nào ở đây gọi guard rồi tự giả định phần còn lại (fix I2).
|
| Mỗi test một tên disk riêng — lý do ở đầu `BackupRunIntegrationTest.php`.
*/

beforeEach(function () {
    // Cờ static của gói; tệp khác dùng `--disable-notifications` tắt nó và không bật lại. Lý do
    // đầy đủ ở `BackupRunIntegrationTest.php`.
    EventHandler::enable();
});

afterEach(function () {
    EventHandler::enable();
});

/**
 * Chạy `backup:run --only-files` ở production với cấu hình cho trước, trên một disk giả lập.
 *
 * @param  array<string, mixed>  $config
 * @return array{exit: int, files: list<string>}
 */
function runProductionBackup(string $disk, array $config): array
{
    Storage::fake($disk);
    app()['env'] = 'production';

    config(['backup.backup.destination.disks' => [$disk], ...$config]);
    Config::rebind();

    $exit = Artisan::call('backup:run', ['--only-files' => true]);

    return ['exit' => $exit, 'files' => Storage::disk($disk)->allFiles()];
}

function assertBackupFailedAlertQueued(string $expectedMessage): void
{
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($expectedMessage) {
        return $job->notification instanceof BackupHasFailedNotification
            && $job->notification->exceptionMessage === $expectedMessage;
    });
}

it('§10.8 production thiếu BACKUP_ARCHIVE_PASSWORD: backup:run không tạo archive và xếp hàng thư báo lỗi nêu nguyên nhân', function () {
    Queue::fake();

    $result = runProductionBackup('backup_guard_disk_1', ['backup.backup.password' => null]);

    expect($result['exit'])->toBe(1)
        ->and($result['files'])->toBe([]);

    assertBackupFailedAlertQueued(__('backup.errors.password_required_in_production'));
});

it('§10.8 production có mật khẩu nhưng máy chủ không mã hoá được AES-256: backup:run không tạo archive và xếp hàng thư báo lỗi', function () {
    // Trên libzip cũ, `ZipArchive::EM_AES_256` không tồn tại, nên
    // `Spatie\Backup\Enums\Encryption::Aes256->algorithm()` trả `null` và `Zip` lặng lẽ ghi
    // archive KHÔNG mã hoá dù có mật khẩu. Không giả được `defined()` của một hằng số lớp, nên
    // test đi đúng nhánh đó qua `encryption = none`: `algorithm()` cũng trả `null`, và
    // `shouldEncrypt()` — thứ guard hỏi — trả `false` y hệt trường hợp libzip cũ.
    Queue::fake();

    $result = runProductionBackup('backup_guard_disk_2', [
        'backup.backup.password' => 'mat-khau-gia-lap',
        'backup.backup.encryption' => 'none',
    ]);

    expect($result['exit'])->toBe(1)
        ->and($result['files'])->toBe([]);

    assertBackupFailedAlertQueued(__('backup.errors.encryption_unavailable_in_production'));
});

it('§10.8 production có mật khẩu và AES-256: backup:run tạo archive, không thư báo lỗi', function () {
    Queue::fake();

    $result = runProductionBackup('backup_guard_disk_3', [
        'backup.backup.password' => 'mat-khau-gia-lap',
        'backup.backup.encryption' => 'aes256',
    ]);

    expect($result['exit'])->toBe(0)
        ->and($result['files'])->toHaveCount(1);

    Queue::assertNotPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
});

it('§10.8 gọi thẳng BackupJob (không qua lệnh backup:run) ở production thiếu mật khẩu vẫn bị chặn', function () {
    Storage::fake('backup_guard_disk_4');
    app()['env'] = 'production';

    config([
        'backup.backup.destination.disks' => ['backup_guard_disk_4'],
        'backup.backup.password' => null,
    ]);
    Config::rebind();

    $job = BackupJobFactory::createFromConfig(app(Config::class))
        ->dontBackupDatabases()
        ->disableSignals();

    expect(fn () => $job->run())->toThrow(function (BackupFailed $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(BackupEncryptionRequired::class);
    });

    expect(Storage::disk('backup_guard_disk_4')->allFiles())->toBe([]);
});

it('§10.8 không chặn ở môi trường testing dù thiếu mật khẩu', function () {
    expect(app()->environment())->toBe('testing');

    config(['backup.backup.password' => null]);
    Config::rebind();

    expect(fn () => app(GuardBackupEncryption::class)->handle())->not->toThrow(BackupEncryptionRequired::class);
});
