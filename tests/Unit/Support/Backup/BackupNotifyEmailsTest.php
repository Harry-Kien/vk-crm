<?php

use App\Support\Backup\BackupNotifyEmails;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| §10.8 / fix I1 — BACKUP_NOTIFY_EMAIL chấp nhận danh sách phẩy, lọc email hỏng
|--------------------------------------------------------------------------
|
| Giá trị của biến này KHÔNG BAO GIỜ được đưa thẳng vào `config('backup.notifications.mail.to')`
| (trường đó validate CHẶT và phá vỡ MỌI lệnh artisan nếu sai — xem docblock ở
| `config/backup.php` và `tests/Feature/Backup/BackupConfigTest.php`). Việc phân tích và validate
| BACKUP_NOTIFY_EMAIL sống Ở ĐÂY, tách hẳn khỏi gói.
*/

it('§10.8 tách danh sách phẩy, bỏ khoảng trắng và phần tử rỗng', function () {
    expect(BackupNotifyEmails::parse(' ops@vidu.test, ,ke-toan@vidu.test '))
        ->toBe(['ops@vidu.test', 'ke-toan@vidu.test']);
});

it('§10.8 trả về rỗng khi chưa cấu hình', function () {
    expect(BackupNotifyEmails::parse(null))->toBe([])
        ->and(BackupNotifyEmails::parse(''))->toBe([]);
});

it('§10.8 loại bỏ địa chỉ không hợp lệ và ghi log cảnh báo, không ném lỗi', function () {
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/không hợp lệ/'));

    $result = BackupNotifyEmails::parse('ops@vidu.test, khong-phai-email');

    expect($result)->toBe(['ops@vidu.test']);
});

it('§10.8 giữ một địa chỉ hợp lệ duy nhất không có dấu phẩy', function () {
    expect(BackupNotifyEmails::parse('ops@vidu.test'))->toBe(['ops@vidu.test']);
});
