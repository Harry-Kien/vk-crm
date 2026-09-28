<?php

use App\Support\Backup\BackupDisks;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config as FlysystemConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/*
|--------------------------------------------------------------------------
| §10.8 — vkcrm:backup-check (M8a Task 2)
|--------------------------------------------------------------------------
|
| Ghi/đọc/xoá một tệp nhỏ trên mỗi disk trong BACKUP_DISKS, cộng đích rclone khi đã cấu hình.
| `Process::fake()` cho phần rclone — không cần binary thật.
*/

it('§10.8 mặc định (không {target}) kiểm HẾT các disk và rclone khi remote đã bật, thoát mã 0', function () {
    Storage::fake('backup_check_disk_ok');
    Storage::fake(BackupDisks::DEFAULT_DISK);
    config([
        // local_backups PHẢI có mặt — fix I2 (vòng rà soát 1): thiếu nó, lượt đẩy rclone không
        // bao giờ chạy, và checkRclone() từ chối thẳng trước khi chạm tới bất kỳ lệnh rclone nào.
        'backup.backup.destination.disks' => ['backup_check_disk_ok', BackupDisks::DEFAULT_DISK],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    // `lsjson` phải trả về đúng tệp thử vừa "đẩy" để lượt kiểm rclone thành công — dùng một biến
    // bắt tên tệp thật sự được `copy`, vì tên đó là ngẫu nhiên (Str::random()).
    $copiedFile = null;
    Process::fake(function ($process) use (&$copiedFile) {
        if (in_array('copy', $process->command, true)) {
            $copiedFile = basename($process->command[array_key_last($process->command) - 1] ?? '');

            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            // Lệnh kiểm (`CheckBackupDestinations::checkRclone()`) chỉ so TÊN, không so dung
            // lượng — khác `PushBackupArchiveToRclone::verify()`. Dung lượng ở đây vì vậy không
            // cần khớp thật, chỉ cần đủ khoá.
            return Process::result(output: json_encode([
                ['Name' => $copiedFile, 'Size' => 999, 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        if (in_array('deletefile', $process->command, true)) {
            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    $exitCode = Artisan::call('vkcrm:backup-check');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('backup_check_disk_ok')
        ->and($output)->toContain('rclone')
        ->and($output)->toContain('Tất cả đích sao lưu đều ổn');
});

it('§10.8 một disk cụ thể, ghi được đọc được: OK, thoát mã 0', function () {
    Storage::fake('backup_check_disk_single');
    config(['backup.backup.destination.disks' => ['backup_check_disk_single']]);

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'backup_check_disk_single']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('OK');
});

/*
| `Illuminate\Console\Application::output()` gọi `BufferedOutput::fetch()`, thứ VỪA TRẢ VỀ VỪA
| LÀM RỖNG bộ đệm (Symfony) — gọi `Artisan::output()` LẦN THỨ HAI trong cùng một bài test luôn
| trả về CHUỖI RỖNG, không phải nội dung cũ. Mọi bài dưới đây (trừ bài ngay trên, chỉ gọi MỘT
| lần) đọc kết quả vào một biến CỤC BỘ trước khi kiểm nhiều điều kiện trên cùng nội dung đó.
*/

it('§10.8 một disk hỏng khi ghi: LỖI, thoát mã khác 0, không kiểm disk khác', function () {
    $root = sys_get_temp_dir().'/vkcrm-backup-check-fails-'.uniqid();
    File::ensureDirectoryExists($root);

    Storage::extend('vkcrm_check_write_fails', function ($app, array $config) {
        $adapter = new class($config['root']) extends LocalFilesystemAdapter
        {
            public function write(string $path, string $contents, FlysystemConfig $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk giả lập hỏng khi ghi');
            }

            public function writeStream(string $path, $contents, FlysystemConfig $config): void
            {
                throw UnableToWriteFile::atLocation($path, 'disk giả lập hỏng khi ghi');
            }
        };

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });

    config([
        'filesystems.disks.backup_check_disk_fail' => [
            'driver' => 'vkcrm_check_write_fails',
            'root' => $root,
        ],
        'backup.backup.destination.disks' => ['backup_check_disk_fail'],
    ]);

    try {
        $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'backup_check_disk_fail']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0)
            ->and($output)->toContain('LỖI')
            ->and($output)->toContain('backup_check_disk_fail');
    } finally {
        File::deleteDirectory($root);
    }
});

it('§10.8 đích rclone hỏng (copy thất bại): LỖI, thoát mã khác 0', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'khong noi duoc Google Drive'));

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'rclone']);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('LỖI')
        ->and($output)->toContain('rclone');
});

it('§10.8 target không tồn tại: LỖI nêu các đích hợp lệ, thoát mã khác 0', function () {
    Storage::fake('backup_check_disk_named');
    config([
        'backup.backup.destination.disks' => ['backup_check_disk_named'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'khong-ton-tai']);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('khong-ton-tai')
        ->and($output)->toContain('backup_check_disk_named')
        ->and($output)->not->toContain('rclone');
});

it('§10.8 "rclone" không phải một target hợp lệ khi BACKUP_RCLONE_REMOTE rỗng', function () {
    config(['vkcrm.backup.rclone.remote' => null]);

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'rclone']);

    expect($exitCode)->not->toBe(0);
});

it('§10.8 fix I1 — tệp thử rclone đi vào .backup-check, không phải gốc archive', function () {
    config(['vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups']);

    $copyDestination = null;
    $probeFilename = null;
    $probeLocalPath = null;
    $probeExistedWhenCopied = false;
    $lsjsonDestination = null;
    $deleteDestination = null;

    Process::fake(function ($process) use (&$copyDestination, &$probeFilename, &$probeLocalPath, &$probeExistedWhenCopied, &$lsjsonDestination, &$deleteDestination) {
        if (in_array('copy', $process->command, true)) {
            $probeLocalPath = $process->command[array_key_last($process->command) - 1];
            $probeExistedWhenCopied = is_file($probeLocalPath);
            $probeFilename = basename($probeLocalPath);
            $copyDestination = end($process->command);

            return Process::result(exitCode: 0);
        }

        if (in_array('lsjson', $process->command, true)) {
            $lsjsonDestination = end($process->command);

            return Process::result(output: json_encode([
                ['Name' => $probeFilename, 'Size' => 999, 'ModTime' => now()->toIso8601String(), 'IsDir' => false],
            ]));
        }

        if (in_array('deletefile', $process->command, true)) {
            $deleteDestination = end($process->command);

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 1, errorOutput: 'lệnh không mong đợi');
    });

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'rclone']);

    expect($exitCode)->toBe(0)
        ->and($copyDestination)->toBe('gdrive:VK-CRM-backups/.backup-check')
        ->and($lsjsonDestination)->toBe('gdrive:VK-CRM-backups/.backup-check')
        ->and($deleteDestination)->toBe('gdrive:VK-CRM-backups/.backup-check/'.$probeFilename);

    // Fix M7 (lượt rà soát cuối M8a): tệp thử CỤC BỘ nằm dưới thư mục tạm của sao lưu
    // (`backup.backup.temporary_directory`, production là `storage/app/backup-temp`), không ở
    // `sys_get_temp_dir()` — trên shared hosting `/tmp` có thể nằm ngoài `open_basedir`, dùng chung
    // với tài khoản khác, hay bị dọn giữa chừng. Và được dọn sau khi kiểm xong.
    expect($probeLocalPath)->toStartWith(config('backup.backup.temporary_directory').DIRECTORY_SEPARATOR)
        ->and($probeExistedWhenCopied)->toBeTrue()
        ->and(is_file($probeLocalPath))->toBeFalse();
});

it('§10.8 fix I2 — BACKUP_RCLONE_REMOTE bật nhưng BACKUP_DISKS thiếu local_backups: LỖI rõ ràng, không chạm tới rclone', function () {
    Storage::fake('backup_check_disk_without_local');
    config([
        'backup.backup.destination.disks' => ['backup_check_disk_without_local'],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    Process::fake();

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => 'rclone']);
    $output = Artisan::output();

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('LỖI')
        ->and($output)->toContain('BACKUP_DISKS')
        ->and($output)->toContain(BackupDisks::DEFAULT_DISK);

    // Cấu hình đã sai từ đầu — không tốn một lệnh rclone nào để phát hiện ra điều đó.
    Process::assertNothingRan();
});
