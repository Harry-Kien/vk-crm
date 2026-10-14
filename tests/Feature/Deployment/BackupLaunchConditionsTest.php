<?php

use App\Support\Backup\BackupDisks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Làn fc (kiểm tra nghiệp vụ 2026-10-09, mục B "cổng mở hệ thống báo xanh giả")
|--------------------------------------------------------------------------
|
| `vkcrm:preflight` và `vkcrm:backup-check` là hai cổng của Bước 11 ("mở cổng") trong
| `docs/CAI-DAT.md`. Trước làn này cả hai có thể XANH trên một máy chủ thật mà:
|
|  - `BACKUP_ARCHIVE_PASSWORD` trống (hay libzip không mã hoá được AES-256): mọi lượt sao lưu đêm bị
|    `GuardBackupEncryption` từ chối — không có bản sao lưu nào — nhưng chỉ biết qua một thư lúc 02:00;
|  - không có đích ngoài máy chủ (`BACKUP_RCLONE_REMOTE` trống, mọi đĩa của `BACKUP_DISKS` là `local`):
|    `GuardOffServerBackupDestination` báo mỗi đêm, preflight và backup-check không nói gì.
|
| Nay cả hai lệnh hỏi đúng hai điều kiện đó — CÙNG hàm với hai guard — ở production: preflight có hai
| dòng (ĐỎ khi hỏng), backup-check thêm hai dòng và thoát mã 1. Mọi test đi qua lệnh Artisan thật.
|
| Hàm toàn cục mang tiền tố `blc…`.
*/

/** Máy chủ web không lộ storage/app/private, có mariadb-dump: hai dòng preflight không liên quan đứng yên. */
function blcFakeServer(): void
{
    Http::fake(fn () => Http::response('not found', 404));
    Process::fake(['command -v *' => Process::result(exitCode: 0)]);
}

/** @param  array<string, mixed>  $backup */
function blcPreflight(array $backup, string $env = 'production'): array
{
    config(['app.env' => $env, ...$backup]);
    blcFakeServer();

    $exitCode = Artisan::call('vkcrm:preflight');

    return [$exitCode, Artisan::output()];
}

it('preflight production: BACKUP_ARCHIVE_PASSWORD trống là ĐỎ, nêu lý do của guard, mã thoát khác 0', function () {
    [$exitCode, $output] = blcPreflight([
        'backup.backup.password' => null,
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('ops_checks.preflight.backup_encryption_missing', [
            'detail' => __('backup.errors.password_required_in_production'),
        ]))
        ->and($output)->not->toContain(__('ops_checks.preflight.backup_encryption_ok'));
});

it('preflight production: chuỗi rỗng cũng là trống — cùng cách gói sao lưu đọc nó', function () {
    [, $output] = blcPreflight([
        'backup.backup.password' => '',
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    expect($output)->toContain(__('backup.errors.password_required_in_production'));
});

it('preflight production: không đích nào ngoài máy chủ là ĐỎ, nêu các đĩa, mã thoát khác 0', function () {
    [$exitCode, $output] = blcPreflight([
        'backup.backup.password' => 'mat-khau-thu-nghiem',
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain(__('ops_checks.preflight.backup_off_server_missing', ['disks' => BackupDisks::DEFAULT_DISK]));
});

it('preflight production: mật khẩu có và đích rclone có là hai dòng XANH', function () {
    [, $output] = blcPreflight([
        'backup.backup.password' => 'mat-khau-thu-nghiem',
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
        'vkcrm.backup.rclone.remote' => 'gdrive:VK-CRM-backups',
    ]);

    expect($output)->toContain(__('ops_checks.preflight.backup_encryption_ok'))
        ->and($output)->toContain(__('ops_checks.preflight.backup_off_server_ok'))
        ->and($output)->not->toContain(__('backup.errors.password_required_in_production'))
        ->and($output)->not->toContain(__('ops_checks.preflight.backup_off_server_missing', ['disks' => BackupDisks::DEFAULT_DISK]));
});

it('preflight production: một đĩa KHÔNG local (SFTP văn phòng) là đích ngoài máy chủ — XANH dù không có rclone', function () {
    [, $output] = blcPreflight([
        'backup.backup.password' => 'mat-khau-thu-nghiem',
        'filesystems.disks.office_sftp_blc' => ['driver' => 'sftp', 'host' => 'nas.vanphong.test'],
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK, 'office_sftp_blc'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    expect($output)->toContain(__('ops_checks.preflight.backup_off_server_ok'));
});

it('preflight ngoài production: không có hai dòng sao lưu (máy dev không buộc có bí mật hay Google Drive)', function () {
    [, $output] = blcPreflight([
        'backup.backup.password' => null,
        'vkcrm.backup.rclone.remote' => null,
    ], 'staging');

    expect($output)->not->toContain(__('backup.errors.password_required_in_production'))
        ->and($output)->not->toContain(__('ops_checks.preflight.backup_encryption_ok'))
        ->and($output)->not->toContain(__('ops_checks.preflight.backup_off_server_ok'));
});

/** backup-check: đĩa local_backups giả, không rclone — phần kiểm đích luôn OK, chỉ hai điều kiện mới quyết. */
function blcBackupCheck(array $config, string $env = 'production'): array
{
    Storage::fake(BackupDisks::DEFAULT_DISK);
    app()['env'] = $env;
    config(['backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK], ...$config]);

    $exitCode = Artisan::call('vkcrm:backup-check');

    return [$exitCode, Artisan::output()];
}

it('backup-check production: mật khẩu sao lưu trống là LỖI và mã thoát 1, dù mọi đích đều OK', function () {
    [$exitCode, $output] = blcBackupCheck([
        'backup.backup.password' => null,
        'filesystems.disks.office_sftp_blc' => ['driver' => 'sftp', 'host' => 'nas.vanphong.test'],
        'vkcrm.backup.rclone.remote' => null,
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
    ]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('backup.check.disk_ok', ['disk' => BackupDisks::DEFAULT_DISK]))
        ->and($output)->toContain(__('ops_checks.backup_check.encryption_failed', [
            'detail' => __('backup.errors.password_required_in_production'),
        ]))
        ->and($output)->not->toContain(__('backup.check.summary_ok'));
});

it('backup-check production: không đích ngoài máy chủ là LỖI và mã thoát 1', function () {
    [$exitCode, $output] = blcBackupCheck([
        'backup.backup.password' => 'mat-khau-thu-nghiem',
        'vkcrm.backup.rclone.remote' => null,
    ]);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(__('ops_checks.backup_check.off_server_failed', ['disks' => BackupDisks::DEFAULT_DISK]))
        ->and($output)->toContain(__('ops_checks.backup_check.encryption_ok'));
});

it('backup-check production: đủ hai điều kiện và mọi đích OK thì "Tất cả đích sao lưu đều ổn", mã thoát 0', function () {
    // Một đĩa SFTP văn phòng trong BACKUP_DISKS (cấu hình driver `sftp`; `Storage::fake` thay đĩa thật
    // bằng thư mục tạm để phép ghi/đọc thử chạy được mà không đổi cấu hình driver).
    config(['filesystems.disks.office_sftp_blc' => ['driver' => 'sftp', 'host' => 'nas.vanphong.test']]);
    Storage::fake('office_sftp_blc');

    [$exitCode, $output] = blcBackupCheck([
        'backup.backup.password' => 'mat-khau-thu-nghiem',
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK, 'office_sftp_blc'],
        'vkcrm.backup.rclone.remote' => null,
    ]);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('ops_checks.backup_check.encryption_ok'))
        ->and($output)->toContain(__('ops_checks.backup_check.off_server_ok'))
        ->and($output)->toContain(__('backup.check.summary_ok'));
});

it('backup-check ngoài production: không có hai dòng mới, cấu hình máy dev vẫn xanh', function () {
    [$exitCode, $output] = blcBackupCheck([
        'backup.backup.password' => null,
        'vkcrm.backup.rclone.remote' => null,
    ], 'local');

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain(__('ops_checks.backup_check.encryption_ok'))
        ->and($output)->not->toContain(__('backup.errors.password_required_in_production'));
});

it('backup-check production với một đích cụ thể chỉ kiểm đúng đích đó (hai điều kiện thuộc lượt kiểm toàn bộ)', function () {
    Storage::fake(BackupDisks::DEFAULT_DISK);
    app()['env'] = 'production';
    config([
        'backup.backup.destination.disks' => [BackupDisks::DEFAULT_DISK],
        'backup.backup.password' => null,
        'vkcrm.backup.rclone.remote' => null,
    ]);

    $exitCode = Artisan::call('vkcrm:backup-check', ['target' => BackupDisks::DEFAULT_DISK]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(__('backup.check.disk_ok', ['disk' => BackupDisks::DEFAULT_DISK]))
        ->and($output)->not->toContain(__('backup.errors.password_required_in_production'));
});
