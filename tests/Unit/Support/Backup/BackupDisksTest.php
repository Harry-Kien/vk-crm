<?php

use App\Support\Backup\BackupDisks;

/*
|--------------------------------------------------------------------------
| §10.8 / M8a Task 1 — phân tích BACKUP_DISKS
|--------------------------------------------------------------------------
|
| BACKUP_DISKS thay biến BACKUP_DISK cũ (không còn đọc ở đâu — xem docblock
| `App\Support\Backup\BackupDisks`). Test này khoá hành vi phân tích, tách khỏi việc gói
| `spatie/laravel-backup` có đọc đúng cấu hình hay không (test đó ở `BackupConfigTest`).
*/

it('§10.8 tách BACKUP_DISKS theo dấu phẩy, bỏ khoảng trắng và phần tử rỗng', function () {
    expect(BackupDisks::parse(' google, ,office '))->toBe(['google', 'office']);
});

it('§10.8 trả về danh sách rỗng khi mọi phần tử đều rỗng', function () {
    expect(BackupDisks::parse(' , , '))->toBe([BackupDisks::DEFAULT_DISK]);
});

it('§10.8 dùng disk local mặc định khi BACKUP_DISKS chưa khai báo', function () {
    expect(BackupDisks::parse(null))->toBe([BackupDisks::DEFAULT_DISK])
        ->and(BackupDisks::parse(''))->toBe([BackupDisks::DEFAULT_DISK]);
});

it('§10.8 giữ nguyên một disk duy nhất không có dấu phẩy', function () {
    expect(BackupDisks::parse('google'))->toBe(['google']);
});
