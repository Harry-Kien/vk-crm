<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 — fix I2 (vòng rà soát 1, phần "consider" của brief): backup:run tự phát hiện cấu hình
| "BACKUP_RCLONE_REMOTE bật nhưng local_backups vắng mặt trong BACKUP_DISKS" NGAY ĐÊM ĐẦU TIÊN
|--------------------------------------------------------------------------
|
| `GuardRcloneDestinationReachable` nghe `BackupManifestWasCreated` — CÙNG sự kiện với
| `GuardBackupEncryption` — nhưng KHÔNG NÉM LỖI: bản sao lưu cục bộ đêm nay vẫn phải thành công,
| chỉ riêng đích rclone là bị bỏ lỡ. Test dưới đây xác nhận CẢ HAI vế: `backup:run` vẫn thành
| công (`--only-files`, không disable-notifications) VÀ thư báo lỗi vẫn được xếp hàng.
|
| Mỗi test một tên disk riêng (không phải `local_backups`) — lý do ở đầu `BackupRunIntegrationTest.php`.
*/

beforeEach(fn () => EventHandler::enable());
afterEach(fn () => EventHandler::enable());

it('§10.8 BACKUP_RCLONE_REMOTE bật nhưng BACKUP_DISKS thiếu local_backups: backup:run vẫn OK, có thư báo lỗi', function () {
    Queue::fake();
    Storage::fake('guard_rclone_reachable_disk_1');

    config([
        'backup.backup.destination.disks' => ['guard_rclone_reachable_disk_1'],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);
    Config::rebind();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true]);

    expect($exitCode)->toBe(0)
        ->and(Storage::disk('guard_rclone_reachable_disk_1')->allFiles())->toHaveCount(1);

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) {
        return $job->notification instanceof BackupHasFailedNotification
            && str_starts_with((string) $job->notification->diskName, 'rclone:gdrive:VK-CRM-backups')
            && str_contains($job->notification->exceptionMessage, 'BACKUP_DISKS')
            && str_contains($job->notification->exceptionMessage, BackupDisks::DEFAULT_DISK);
    });
});

it('§10.8 BACKUP_DISKS có local_backups: backup:run KHÔNG báo lỗi cấu hình rclone', function () {
    Queue::fake();
    Storage::fake(BackupDisks::DEFAULT_DISK);

    config([
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);
    Config::rebind();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    expect($exitCode)->toBe(0);

    Queue::assertNotPushed(fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
});

it('§10.8 BACKUP_RCLONE_REMOTE rỗng: backup:run không kiểm BACKUP_DISKS, không báo lỗi cấu hình rclone', function () {
    Queue::fake();
    Storage::fake('guard_rclone_reachable_disk_2');

    config([
        'backup.backup.destination.disks' => ['guard_rclone_reachable_disk_2'],
        'vkcrm.backup.rclone.remote' => null,
    ]);
    Config::rebind();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true, '--disable-notifications' => true]);

    expect($exitCode)->toBe(0);

    Queue::assertNotPushed(fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
});
