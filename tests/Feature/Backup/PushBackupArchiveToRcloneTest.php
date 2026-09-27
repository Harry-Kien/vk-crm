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
|
| Fix I3 (lượt rà soát cuối M8a): archive đi vào thư mục RIÊNG của môi trường,
| `{BACKUP_RCLONE_REMOTE}/{slug(backup.name)}` — ở đây `gdrive:VK-CRM-backups/vk-crm` cho tên
| sao lưu "VK-CRM". Tên archive trong các test "thành công" là tên THẬT của gói
| (`vk-crm-Y-m-d-H-i-s.zip`), vì lượt dọn remote chỉ đếm đúng hình dạng đó.
*/

const PUSH_TEST_REMOTE = 'gdrive:VK-CRM-backups';
const PUSH_TEST_FOLDER = 'gdrive:VK-CRM-backups/vk-crm';

beforeEach(function () {
    EventHandler::enable();
    $this->freezeTime();
    // Tường minh, không lệ thuộc APP_NAME của môi trường test — PruneRcloneRemoteBackups lọc
    // theo đúng tiền tố này trước khi đếm/xoá.
    config(['backup.backup.destination.filename_prefix' => 'vk-crm-']);
});

afterEach(function () {
    EventHandler::enable();
});

function pushTestArchiveName(int $ageInMinutes): string
{
    return 'vk-crm-'.now()->copy()->subMinutes($ageInMinutes)->format('Y-m-d-H-i-s').'.zip';
}

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
    config(['vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE]);
    Process::fake();

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful('office_sftp', 'VK-CRM'));

    Process::assertNothingRan();
});

it('§10.8 fix M4 — sao lưu báo thành công mà local_backups không có archive nào: báo lỗi như một lượt đẩy hỏng', function () {
    // Trước fix này nhánh này `return` lặng lẽ: bản sao ngoài máy chủ đêm nay không có, và không ai
    // biết. Đó đúng là một lượt đẩy hỏng — cùng đường báo lỗi.
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE]);
    Process::fake();
    Storage::fake(BackupDisks::DEFAULT_DISK);

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    Process::assertNothingRan();
    assertRcloneFailureQueued('rclone:'.PUSH_TEST_REMOTE, BackupDisks::DEFAULT_DISK);
});

it('§10.8 rclone copy thất bại: báo lỗi nêu tên REMOTE (không phải disk), không dọn máy chủ', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE]);
    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);
    $disk->put('VK-CRM/new.zip', 'noi-dung-archive');

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'het han muc Google Drive'));

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:'.PUSH_TEST_REMOTE, 'het han muc Google Drive');
    // Không dọn máy chủ: archive vẫn còn nguyên.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 rclone copy thoát mã 0 nhưng lsjson KHÔNG thấy tệp: coi là thất bại, không dọn gì', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE]);
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

    assertRcloneFailureQueued('rclone:'.PUSH_TEST_REMOTE);
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 rclone copy thoát mã 0 nhưng dung lượng trên remote KHÔNG khớp: coi là thất bại', function () {
    Queue::fake();
    config(['vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE]);
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

    assertRcloneFailureQueued('rclone:'.PUSH_TEST_REMOTE);
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/new.zip']);
});

it('§10.8 đẩy và xác minh thành công: đẩy vào thư mục môi trường, dọn remote xuống config keep, dọn máy chủ xuống BACKUP_LOCAL_KEEP', function () {
    Queue::fake();

    config([
        'vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE,
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
    $newName = pushTestArchiveName(0);
    $disk->put('VK-CRM/'.$newName, $content);

    $old1 = pushTestArchiveName(5 * 1440);
    $old2 = pushTestArchiveName(4 * 1440);

    $copyTarget = null;
    $lsjsonTargets = [];
    $deletedOnRemote = [];

    Process::fake(function ($process) use ($content, $newName, $old1, $old2, &$copyTarget, &$lsjsonTargets, &$deletedOnRemote) {
        if (in_array('copy', $process->command, true)) {
            $copyTarget = end($process->command);

            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            $lsjsonTargets[] = end($process->command);

            return Process::result(output: json_encode([
                ['Name' => $old1, 'Size' => 500, 'ModTime' => now()->subDays(5)->toIso8601String(), 'IsDir' => false],
                ['Name' => $old2, 'Size' => 500, 'ModTime' => now()->subDays(4)->toIso8601String(), 'IsDir' => false],
                ['Name' => $newName, 'Size' => strlen($content), 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
                // Tệp lạ, không khớp hình dạng archive — phải sống sót, dù CŨ NHẤT trong tất cả và
                // remote đang dọn xuống chỉ còn 1 bản.
                ['Name' => 'ghi-chu-van-phong.pdf', 'Size' => 200, 'ModTime' => now()->subDays(30)->toIso8601String(), 'IsDir' => false],
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

    // Fix I3: đẩy, xác minh, dọn — cả ba đều ở ĐÚNG thư mục của môi trường, không ở gốc remote.
    expect($copyTarget)->toBe(PUSH_TEST_FOLDER)
        ->and(array_unique($lsjsonTargets))->toBe([PUSH_TEST_FOLDER]);

    // Remote: giữ đúng bản mới, xoá hai bản archive cũ — KHÔNG đụng tệp lạ.
    expect($deletedOnRemote)->toEqualCanonicalizing([
        PUSH_TEST_FOLDER.'/'.$old1,
        PUSH_TEST_FOLDER.'/'.$old2,
    ]);

    // Máy chủ: chỉ còn bản mới nhất.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/'.$newName]);
});

it('§10.8 dọn remote hỏng (deletefile lỗi) VẪN báo lỗi, nhưng KHÔNG chặn dọn máy chủ', function () {
    Queue::fake();

    config([
        'vkcrm.backup.rclone.remote' => PUSH_TEST_REMOTE,
        'vkcrm.backup.rclone.keep' => 1,
        'vkcrm.backup.local_keep' => 1,
    ]);

    Storage::fake(BackupDisks::DEFAULT_DISK);
    $disk = Storage::disk(BackupDisks::DEFAULT_DISK);

    $disk->put('VK-CRM/local-old-1.zip', 'cu-1');
    touch($disk->path('VK-CRM/local-old-1.zip'), now()->copy()->subMinutes(10)->getTimestamp());

    $content = 'noi-dung-archive-vua-tao';
    $newName = pushTestArchiveName(0);
    $disk->put('VK-CRM/'.$newName, $content);
    $old = pushTestArchiveName(5 * 1440);

    Process::fake(function ($process) use ($content, $newName, $old) {
        if (in_array('copy', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            return Process::result(output: json_encode([
                ['Name' => $old, 'Size' => 500, 'ModTime' => now()->subDays(5)->toIso8601String(), 'IsDir' => false],
                ['Name' => $newName, 'Size' => strlen($content), 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        if (in_array('deletefile', $process->command, true)) {
            return Process::result(exitCode: 1, errorOutput: 'remote treo giữa chừng');
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    app(PushBackupArchiveToRclone::class)->handle(new BackupWasSuccessful(BackupDisks::DEFAULT_DISK, 'VK-CRM'));

    assertRcloneFailureQueued('rclone:'.PUSH_TEST_REMOTE, 'remote treo giữa chừng');

    // Bản MỚI đã lên remote và được xác minh — dọn máy chủ vẫn chạy dù dọn remote hỏng.
    expect($disk->allFiles('VK-CRM'))->toBe(['VK-CRM/'.$newName]);
});
