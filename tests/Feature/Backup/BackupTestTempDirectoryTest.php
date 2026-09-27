<?php

/*
|--------------------------------------------------------------------------
| Nhân chứng cho `beforeEach` toàn cục của thư mục tạm `backup:run` (tests/Pest.php)
|--------------------------------------------------------------------------
|
| M8a Task 3, fix round 1 — I1/C1 (rà soát): vòng sửa đầu đặt
| `config(['backup.backup.temporary_directory' => backupTemporaryTestDirectory()])` trong
| `beforeEach` của TỪNG tệp test gọi `backup:run` thật, và bỏ sót
| `GuardRcloneDestinationReachableTest.php` (M8a Task 2) — tệp đó vẫn đua trên
| `storage_path('app/backup-temp')` dùng chung sau vòng sửa đầu. Cách sửa đúng: một `beforeEach`
| ÁP CHO CẢ THƯ MỤC `Feature/Backup` (`pest()->in('Feature/Backup')->beforeEach(...)` ở
| `tests/Pest.php`), không liệt kê tệp — cùng bài học với đĩa `private` giả (đầu `tests/Pest.php`).
|
| Tệp NÀY, giống `tests/Feature/Storage/PrivateDiskTest.php`, KHÔNG tự đặt
| `backup.backup.temporary_directory` — nó chỉ xanh khi cái `beforeEach` toàn cục ở `tests/Pest.php`
| còn hoạt động. Xoá dòng `pest()->in('Feature/Backup')->beforeEach(...)` đi (hay đổi lại thành một
| `beforeEach` liệt kê tay từng tệp) làm bài dưới đây đỏ ngay, không cần đợi một lượt `--parallel`
| flake mới lộ ra.
*/

it('mọi test trong Feature/Backup có backup.backup.temporary_directory RIÊNG, không phải mặc định production', function () {
    $configured = config('backup.backup.temporary_directory');

    expect($configured)
        ->not->toBe(storage_path('app/backup-temp'))
        ->and($configured)->toBe(backupTemporaryTestDirectory());
});
