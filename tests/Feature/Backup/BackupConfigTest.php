<?php

use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use App\Support\Backup\BackupDisks;

/*
|--------------------------------------------------------------------------
| §10.8 — hình dạng cấu hình `config/backup.php`
|--------------------------------------------------------------------------
*/

it('§10.8 nguồn sao lưu gồm storage/app/private, không gồm storage/logs', function () {
    $include = config('backup.backup.source.files.include');
    $exclude = config('backup.backup.source.files.exclude');

    expect($include)->toContain(storage_path('app/private'))
        ->and($include)->not->toContain(storage_path('logs'))
        ->and($exclude)->toContain(storage_path('logs'));
});

it('§10.8 sao lưu CSDL mặc định của kết nối hiện tại', function () {
    expect(config('backup.backup.source.databases'))->toContain(config('database.default'));
});

it('§10.8 tiếp tục ghi các disk còn lại khi một disk hỏng', function () {
    expect(config('backup.backup.destination.continue_on_failure'))->toBeTrue();
});

it('§10.8 mã hoá archive bằng AES-256', function () {
    expect(config('backup.backup.encryption'))->toBe('aes256');
});

it('§10.8 ba notification của dự án thay bản gốc của gói, gửi mail', function () {
    $notifications = config('backup.notifications.notifications');

    expect($notifications[BackupHasFailedNotification::class] ?? null)->toBe(['mail'])
        ->and($notifications[CleanupHasFailedNotification::class] ?? null)->toBe(['mail'])
        ->and($notifications[UnhealthyBackupWasFoundNotification::class] ?? null)->toBe(['mail']);
});

it('§10.8 khi BACKUP_DISKS chưa khai báo, đích mặc định là disk local_backups đã cấu hình', function () {
    expect(config('backup.backup.destination.disks'))->toBe([BackupDisks::DEFAULT_DISK])
        ->and(config('filesystems.disks.'.BackupDisks::DEFAULT_DISK.'.driver'))->toBe('local')
        ->and(config('filesystems.disks.'.BackupDisks::DEFAULT_DISK.'.root'))->toBe(storage_path('app/backups'));
});

it('§10.8 giữ 30 bản hằng ngày theo cấu hình dọn dẹp', function () {
    // Con số CHÍNH XÁC (29, không phải 30) và lý do được kiểm bằng mô phỏng thật ở
    // `BackupCleanupTest`; test này chỉ khoá lại giá trị đã chọn không bị đổi nhầm khi sửa file.
    expect(config('backup.cleanup.default_strategy.keep_all_backups_for_days'))->toBe(29)
        ->and(config('backup.cleanup.default_strategy.keep_daily_backups_for_days'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'))->toBe(0)
        ->and(config('backup.cleanup.default_strategy.keep_yearly_backups_for_years'))->toBe(0);
});
