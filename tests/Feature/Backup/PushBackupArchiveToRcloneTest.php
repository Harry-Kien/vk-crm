<?php

use App\Actions\Backup\PushBackupArchiveToRclone;
use App\Notifications\Backup\BackupHasFailedNotification;
use App\Support\Backup\BackupDisks;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 — đẩy archive vừa sao lưu xong lên Google Drive bằng rclone (M8a Task 2, Ruling 1 và 2)
|--------------------------------------------------------------------------
|
| `Process::fake()` — không cần binary `rclone` thật (brief: "tests fake Process; no real rclone
| is needed"). Đường thất bại đi qua chính đường báo lỗi của Task 1 (`BackupHasFailed` →
| `BackupHasFailedNotification` → hàng đợi), kiểm bằng `Queue::fake()`, cùng thành ngữ với
| `GuardBackupEncryptionTest.php`.
*/

beforeEach(function () {
    EventHandler::enable();
});

afterEach(function () {
    EventHandler::enable();
});

/** Bắt một BackupHasFailedNotification đã xếp hàng, nêu đúng "đích" (diskName) và thông điệp. */
function assertRcloneFailureQueued(string $expectedDiskNamePrefix, ?string $expectedMessageContains = null): void
{
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($expectedDiskNamePrefix, $expectedMessageContains) {
        if (! $job->notification instanceof BackupHasFailedNotification) {
            return false;
        }

        if (! str_starts_with((string) $job->notification->diskName, $expectedDiskNamePrefix)) {
            return false;
        }

        if ($expectedMessageContains !== null && ! str_contains($job->notification->exceptionMessage, $expectedMessageContains)) {
            return false;
        }

        return true;
    });
}

function assertNoRcloneFailureQueued(): void
{
    Queue::assertNotPushed(fn (SendQueuedNotifications $job) => $job->notification instanceof BackupHasFailedNotification);
}

it('§10.8 BACKUP_RCLONE_REMOTE rỗng: không chạy rclone nào, không dọn gì', function () {
    config(['vkcrm.backup.rclone.remote' => null]);
    Process::fake();
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);
    $disk->put('VK-CRM/new.zip', 'noi-dung');

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    Process::assertNothingRan();
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 sự kiện của một disk KHÁC local_backups bị bỏ qua dù remote đã cấu hình', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    Process::fake();

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful('office_sftp', 'VK-CRM'));

    Process::assertNothingRan();
});

it('§10.8 thư mục local_backups rỗng: không có gì để đẩy, không chạy rclone', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    Process::fake();
    Storage::fake(BackupDisks::DEFAULT_DISK);

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    Process::assertNothingRan();
});

it('§10.8 rclone copy thất bại: báo lỗi nêu tên REMOTE (không phải disk), không dọn máy chủ', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);
    $disk->put('VK-CRM/new.zip', 'noi-dung-archive');

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'het han muc Google Drive'));

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:gdrive:VK-CRM-backups', 'het han muc Google Drive');
    // Không dọn máy chủ: archive vẫn còn nguyên.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 rclone copy thoát mã 0 nhưng lsjson KHÔNG thấy tệp: coi là thất bại, không dọn gì', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);
    $disk->put('VK-CRM/new.zip', 'noi-dung-archive');

    Process::fake(function ($process) {
        if (in_array('copy', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode([])); // remote rỗng — không thấy tệp
        }

        return Process::result(exitCode: 1, errorOutput: 'không nên gọi deletefile');
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:gdrive:VK-CRM-backups');
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 rclone copy thoát mã 0 nhưng dung lượng trên remote KHÔNG khớp: coi là thất bại', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);
    $disk->put('VK-CRM/new.zip', 'noi-dung-archive'); // 16 byte

    Process::fake(function ($process) {
        if (in_array('copy', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode([
                ['Name' => 'new.zip', 'Size' => 999, 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        return Process::result(exitCode: 1, errorOutput: 'không nên gọi deletefile');
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:gdrive:VK-CRM-backups');
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 đẩy và xác minh thành công: dọn remote xuống config keep, dọn máy chủ xuống BACKUP_LOCAL_KEEP', function () {
    Queue::fake();

    config([
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
        'vkcrm.backup.rclone.keep' => 1,
        'vkcrm.backup.local_keep' => 1,
    ]);

    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);

    // Hai bản CŨ trên máy chủ (staging) — phải bị dọn (local_keep = 1).
    $disk->put('VK-CRM/local-old-1.zip', 'cu-1');
    touch($disk->path('VK-CRM/local-old-1.zip'), now()->copy()->subMinutes(10)->getTimestamp());
    $disk->put('VK-CRM/local-old-2.zip', 'cu-2');
    touch($disk->path('VK-CRM/local-old-2.zip'), now()->copy()->subMinutes(5)->getTimestamp());

    $content = 'noi-dung-archive-vua-tao';
    $disk->put('VK-CRM/new.zip', $content);

    $deletedOnRemote = [];

    Process::fake(function ($process) use ($content, &$deletedOnRemote) {
        if (in_array('copy', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode([
                ['Name' => 'remote-old-1.zip', 'Size' => 500, 'ModTime' => now()->subDays(5)->toIso8601String(), 'IsDir' => false],
                ['Name' => 'remote-old-2.zip', 'Size' => 500, 'ModTime' => now()->subDays(4)->toIso8601String(), 'IsDir' => false],
                ['Name' => 'new.zip', 'Size' => strlen($content), 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        if (in_array('deletefile', $process->command, true)) {
            $deletedOnRemote[] = end($process->command);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertNoRcloneFailureQueued();

    // Remote: giữ đúng "new.zip", xoá hai bản cũ.
    expect($deletedOnRemote)->toHaveCount(2)
        ->and(collect($deletedOnRemote)->contains(fn (string $t) => str_contains($t, 'remote-old-1.zip')))->toBeTrue()
        ->and(collect($deletedOnRemote)->contains(fn (string $t) => str_contains($t, 'remote-old-2.zip')))->toBeTrue();

    // Máy chủ: chỉ còn bản mới nhất.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 dọn remote hỏng (deletefile lỗi) VẪN báo lỗi, nhưng KHÔNG chặn dọn máy chủ', function () {
    Queue::fake();

    config([
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
        'vkcrm.backup.rclone.keep' => 1,
        'vkcrm.backup.local_keep' => 1,
    ]);

    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);

    $disk->put('VK-CRM/local-old-1.zip', 'cu-1');
    touch($disk->path('VK-CRM/local-old-1.zip'), now()->copy()->subMinutes(10)->getTimestamp());

    $content = 'noi-dung-archive-vua-tao';
    $disk->put('VK-CRM/new.zip', $content);

    Process::fake(function ($process) use ($content) {
        if (in_array('copy', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode([
                ['Name' => 'remote-old-1.zip', 'Size' => 500, 'ModTime' => now()->subDays(5)->toIso8601String(), 'IsDir' => false],
                ['Name' => 'new.zip', 'Size' => strlen($content), 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        if (in_array('deletefile', $process->command, true)) {
            return Process::result(exitCode: 1, errorOutput: 'remote treo giữa chừng');
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:gdrive:VK-CRM-backups', 'remote treo giữa chừng');

    // Bản MỚI đã lên remote và được xác minh — dọn máy chủ vẫn chạy dù dọn remote hỏng.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});
