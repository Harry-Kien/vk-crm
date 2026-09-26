<?php

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
    config([
        'backup.backup.destination.disks' => ['backup_check_disk_ok'],
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
