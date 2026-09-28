<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
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
| chỉ riêng đích rclone là bị bỏ lỡ. Test đầu xác nhận CẢ HAI vế: `backup:run` vẫn thành công VÀ
| thư báo lỗi vẫn được xếp hàng.
|
| Fix I2 (lượt rà soát cuối M8a): KHÔNG test nào ở đây còn dùng `--disable-notifications`. Hai test
| "không báo lỗi" trước đây tắt thư báo — tức chúng KHÔNG THỂ thấy một thư báo nhầm. Bây giờ chúng
| chạy với thư báo BẬT và khẳng định không có `BackupHasFailedNotification` nào (chiều "không báo
| động giả"). Test có `local_backups` + remote đi đúng đường đẩy thật, nên nó giả `Process` TƯỜNG
| MINH — mọi test ở thư mục này đều bị `tests/Pest.php` chặn tiến trình thật, nên không bao giờ
| gọi được `rclone` thật (trước fix, test đó chạy `rclone copy` thật lên remote `gdrive`).
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

    Process::assertNothingRan();

    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) {
        return $job->notification instanceof BackupHasFailedNotification
            && str_starts_with((string) $job->notification->diskName, 'rclone:gdrive:VK-CRM-backups')
            && str_contains($job->notification->exceptionMessage, 'BACKUP_DISKS')
            && str_contains($job->notification->exceptionMessage, BackupDisks::DEFAULT_DISK);
    });
});

it('§10.8 BACKUP_DISKS có local_backups: backup:run KHÔNG báo lỗi cấu hình rclone, không báo động giả', function () {
    Queue::fake();
    Storage::fake(BackupDisks::DEFAULT_DISK);

    config([
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);
    Config::rebind();

    // Đích rclone giả nhưng TRUNG THỰC: `lsjson` trả đúng tên và dung lượng của archive vừa
    // `copy`, như một Google Drive đã nhận đủ tệp — để lượt đẩy thật (PushBackupArchiveToRclone)
    // đi hết đường thành công, và mọi thư báo lỗi còn lại đều là báo động giả.
    $copied = null;

    Process::fake(function ($process) use (&$copied) {
        if (in_array('copy', $process->command, true)) {
            $copied = $process->command[array_search('copy', $process->command, true) + 1];

            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode($copied === null ? [] : [[
                'Name' => basename($copied),
                'Size' => filesize($copied),
                'ModTime' => now()->toIso8601String(),
                'IsDir' => false,
            ]]));
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi: '.implode(' ', $process->command));
    });

    $exitCode = Artisan::call('backup:run', ['--only-files' => true]);

    expect($exitCode)->toBe(0)
        ->and($copied)->not->toBeNull();

    Queue::assertNotPushed(fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
});

it('§10.8 BACKUP_RCLONE_REMOTE rỗng: backup:run không kiểm BACKUP_DISKS, không chạy rclone, không báo lỗi', function () {
    Queue::fake();
    Storage::fake('guard_rclone_reachable_disk_2');

    config([
        'backup.backup.destination.disks' => ['guard_rclone_reachable_disk_2'],
        'vkcrm.backup.rclone.remote' => null,
    ]);
    Config::rebind();

    $exitCode = Artisan::call('backup:run', ['--only-files' => true]);

    expect($exitCode)->toBe(0);

    Process::assertNothingRan();
    Queue::assertNotPushed(fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
});
