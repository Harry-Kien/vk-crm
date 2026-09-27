<?php

use App\Actions\Backup\GuardOffServerBackupDestination;
use App\Notifications\Backup\BackupHasFailedNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 — fix I4 (lượt rà soát cuối M8a): production không có bản sao NGOÀI máy chủ thì báo lỗi
| MỖI ĐÊM
|--------------------------------------------------------------------------
|
| SPEC §10 mục 8 đòi bản sao lưu ra khỏi máy chủ. Trước fix này, một máy chủ production để trống
| `BACKUP_RCLONE_REMOTE` và chỉ có đĩa local trong `BACKUP_DISKS` sao lưu "thành công" mỗi đêm vào
| CHÍNH ổ đĩa mà nó đang bảo vệ, và không ai được báo. Nay: production + remote trống + mọi đĩa
| đích dùng driver `local` → `BackupHasFailed` với lý do "không có bản sao ngoài máy chủ", nhưng
| KHÔNG chặn bản sao cục bộ (nó vẫn có ích khi chỉ hỏng CSDL, không hỏng máy).
*/

beforeEach(fn () => EventHandler::enable());
afterEach(fn () => EventHandler::enable());

/** Một disk có cấu hình THẬT driver `local` (như `local_backups`), rồi giả nó cho test. */
function localDriverDisk(string $name): void
{
    config(["filesystems.disks.{$name}" => ['driver' => 'local', 'root' => storage_path("app/{$name}")]]);
    Storage::fake($name);
}

it('§10.8 production, không remote, mọi đĩa là local: backup:run vẫn tạo archive VÀ xếp hàng thư "không có bản sao ngoài máy chủ"', function () {
    Queue::fake();
    localDriverDisk('off_server_guard_disk_1');
    app()['env'] = 'production';

    config([
        'backup.backup.destination.disks' => ['off_server_guard_disk_1'],
        'backup.backup.password' => 'mat-khau-thu-nghiem-khong-dung-that',
        'vkcrm.backup.rclone.remote' => null,
    ]);
    Config::rebind();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true]);

    expect($exitCode)->toBe(0)
        ->and(Storage::disk('off_server_guard_disk_1')->allFiles())->toHaveCount(1);

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification
        && str_contains($job->notification->exceptionMessage, 'không có bản sao ngoài máy chủ')
        && str_contains($job->notification->exceptionMessage, 'off_server_guard_disk_1'));
});

it('§10.8 production có BACKUP_RCLONE_REMOTE: không báo "không có bản sao ngoài máy chủ"', function () {
    Event::fake([BackupHasFailed::class]);
    app()['env'] = 'production';
    config([
        'backup.backup.destination.disks' => ['local_backups'],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    app(GuardOffServerBackupDestination::class)->handle();

    Event::assertNotDispatched(BackupHasFailed::class);
});

it('§10.8 production có một đĩa KHÔNG phải local (ví dụ SFTP văn phòng): không báo', function () {
    Event::fake([BackupHasFailed::class]);
    app()['env'] = 'production';
    config([
        'filesystems.disks.office_sftp_guard' => ['driver' => 'sftp', 'host' => 'nas.vanphong.test'],
        'backup.backup.destination.disks' => ['local_backups', 'office_sftp_guard'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    app(GuardOffServerBackupDestination::class)->handle();

    Event::assertNotDispatched(BackupHasFailed::class);
});

it('§10.8 ngoài production (máy dev, staging): không báo dù chỉ có đĩa local', function () {
    Event::fake([BackupHasFailed::class]);
    app()['env'] = 'staging';
    config([
        'backup.backup.destination.disks' => ['local_backups'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    app(GuardOffServerBackupDestination::class)->handle();

    Event::assertNotDispatched(BackupHasFailed::class);
});

it('§10.8 production, không remote, local_backups: phát đúng một BackupHasFailed nêu đĩa và lý do', function () {
    Event::fake([BackupHasFailed::class]);
    app()['env'] = 'production';
    config([
        'backup.backup.destination.disks' => ['local_backups'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    app(GuardOffServerBackupDestination::class)->handle();

    Event::assertDispatchedTimes(BackupHasFailed::class, 1);
    Event::assertDispatched(BackupHasFailed::class, fn (BackupHasFailed $event) => $event->diskName === 'local_backups'
        && str_contains($event->exception->getMessage(), 'không có bản sao ngoài máy chủ'));
});
