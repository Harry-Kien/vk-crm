<?php

use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Nhân chứng cho hai hàng rào toàn thư mục của `tests/Feature/Backup` (tests/Pest.php, fix I2
| của lượt rà soát cuối M8a)
|--------------------------------------------------------------------------
|
| Cùng thành ngữ với `BackupTestTempDirectoryTest.php` và `tests/Feature/Storage/PrivateDiskTest.php`:
| tệp này KHÔNG tự gọi `Process::fake()` hay tự đặt `backup.backup.source.files.include` — nó chỉ
| xanh khi cái `beforeEach` áp cho cả thư mục ở `tests/Pest.php` còn hoạt động.
|
| 1. Không test nào trong thư mục này chạy được một tiến trình THẬT qua facade `Process` (tức
|    `rclone` — `App\Support\Backup\RcloneProcess` là nơi duy nhất trong mã gọi facade đó). Trước
|    fix I2, `GuardRcloneDestinationReachableTest` gọi `backup:run` thật trên disk `local_backups`
|    với `BACKUP_RCLONE_REMOTE=gdrive:...` và KHÔNG giả `Process` — trên một máy có cài `rclone` và
|    có remote `gdrive`, lượt test đó đẩy archive thật lên Google Drive thật.
| 2. `backup:run` thật trong thư mục này nén một thư mục NGUỒN TẠM riêng cho tiến trình, không bao
|    giờ nén `storage/app/private` thật của máy đang chạy test (hồ sơ khách thật trên máy chủ, dữ
|    liệu mẫu trên máy dev).
*/

it('fix I2 — mọi test trong Feature/Backup không chạy được tiến trình thật nào qua Process nếu không tự giả nó', function () {
    expect(fn () => Process::run(['rclone', 'version']))
        ->toThrow(RuntimeException::class, 'without a matching fake');
});

it('fix I2 — mọi test trong Feature/Backup sao lưu một thư mục nguồn tạm, không phải storage/app/private thật', function () {
    $include = config('backup.backup.source.files.include');

    expect($include)->toBe([backupSourceTestDirectory()])
        ->and($include)->not->toContain(storage_path('app/private'))
        ->and(glob(backupSourceTestDirectory().'/*'))->not->toBe([]);
});
