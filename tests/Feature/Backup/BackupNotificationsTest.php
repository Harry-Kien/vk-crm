<?php

use App\Enums\OutboundStatus;
use App\Mail\Staff\BackupAlert;
use App\Models\OutboundMessage;
use App\Notifications\Backup\BackupHasFailedNotification;
use App\Notifications\Backup\CleanupHasFailedNotification;
use App\Notifications\Backup\UnhealthyBackupWasFoundNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Notifications\EventHandler;

/*
|--------------------------------------------------------------------------
| §10.8 / R3 — thư báo lỗi sao lưu, dọn dẹp, bản sao không lành mạnh
|--------------------------------------------------------------------------
|
| Ba lớp notification của dự án thay bản gốc của gói qua `config('backup.notifications.
| notifications')` (móc theo class basename, xem docblock ở `config/backup.php`). Phần "toMail()
| dựng đúng BackupAlert" kiểm trực tiếp, không qua dispatch — nhanh và không phụ thuộc hàng đợi.
| Phần "đi hết đường thật" (queue → mail → outbound_messages) kiểm bằng cách dispatch THẬT sự
| kiện của gói, với `queue.default=sync` CHỈ TRONG TEST NÀY (không đụng config/queue.php hay
| QUEUE_CONNECTION) để job hàng đợi chạy ngay trong tiến trình test.
*/

beforeEach(function () {
    // `Spatie\Backup\Notifications\EventHandler::$enabled` là cờ STATIC, sống qua CẢ TIẾN TRÌNH
    // test (không riêng tệp này) — `BackupRunIntegrationTest.php` và `BackupCleanupTest.php` gọi
    // `backup:run`/`backup:clean` với `--disable-notifications`, tắt cờ này và KHÔNG BAO GIỜ tự
    // bật lại. Khi `--parallel` gộp tệp đó và tệp này vào CÙNG một worker, thứ tự chạy quyết định
    // bài "đi hết đường thật" ở đây có thấy `BackupHasFailedNotification` nào được gửi hay không.
    // Đo được: chạy riêng tệp này luôn xanh; chạy CẢ BỘ (`test --parallel --processes=2`) có lúc
    // đỏ với "No query results for model [OutboundMessage]" — không phải lỗi ở mã sản phẩm.
    EventHandler::enable();
});

it('§10.8 BackupHasFailedNotification dựng BackupAlert nêu đúng disk và lỗi, xếp hàng đợi', function () {
    $notification = new BackupHasFailedNotification(
        new BackupHasFailed(new Exception('Ổ đĩa từ chối kết nối'), 'google', 'VK-CRM'),
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new stdClass))->toBe(['mail']);

    $mail = $notification->toMail(new stdClass);

    expect($mail)->toBeInstanceOf(BackupAlert::class)
        ->and($mail->kind)->toBe(BackupAlert::KIND_BACKUP_FAILED)
        ->and($mail->diskName)->toBe('google')
        ->and($mail->detail)->toBe('Ổ đĩa từ chối kết nối');
});

it('§10.8 CleanupHasFailedNotification dựng BackupAlert nêu đúng disk và lỗi, xếp hàng đợi', function () {
    $notification = new CleanupHasFailedNotification(
        new CleanupHasFailed(new Exception('Không xoá được tệp cũ'), 'office', 'VK-CRM'),
    );

    expect($notification)->toBeInstanceOf(ShouldQueue::class);

    $mail = $notification->toMail(new stdClass);

    expect($mail->kind)->toBe(BackupAlert::KIND_CLEANUP_FAILED)
        ->and($mail->diskName)->toBe('office')
        ->and($mail->detail)->toBe('Không xoá được tệp cũ');
});

it('§10.8 UnhealthyBackupWasFoundNotification gộp các lỗi kiểm tra sức khoẻ vào BackupAlert', function () {
    $notification = new UnhealthyBackupWasFoundNotification(new UnhealthyBackupWasFound(
        diskName: 'google',
        backupName: 'VK-CRM',
        failureMessages: new Collection([
            ['check' => 'MaximumAgeInDays', 'message' => 'Bản mới nhất đã 3 ngày tuổi.'],
        ]),
    ));

    expect($notification)->toBeInstanceOf(ShouldQueue::class);

    $mail = $notification->toMail(new stdClass);

    expect($mail->kind)->toBe(BackupAlert::KIND_UNHEALTHY)
        ->and($mail->diskName)->toBe('google')
        ->and($mail->detail)->toContain('Bản mới nhất đã 3 ngày tuổi.');
});

it('§10.8 một BackupHasFailed thật đi hết đường: hàng đợi, thư thương hiệu, ghi outbound_messages', function () {
    config([
        'queue.default' => 'sync',
        'vkcrm.backup.notify_email' => 'ops@luatvukhang.com',
        'backup.backup.password' => 'mat-khau-that-khong-duoc-lo',
    ]);

    // Bắt đúng thư ĐÃ GỬI (tiêu đề + thân HTML + thân chữ), không chỉ tiêu đề trong nhật ký (fix
    // lượt rà soát cuối M8a: câu kiểm cũ chỉ nhìn tiêu đề, nơi mật khẩu không có đường nào lọt
    // vào — nó không bao giờ đỏ được). Thân thư là chỗ một template lỡ in cấu hình sẽ làm lộ.
    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
        $sent[] = $event->message;
    });

    event(new BackupHasFailed(new Exception('Hết dung lượng lưu trữ'), 'google', 'VK-CRM'));

    $row = OutboundMessage::query()->sole();

    expect($row->status)->toBe(OutboundStatus::Sent)
        ->and($row->recipient)->toBe('ops@luatvukhang.com')
        ->and($row->template)->toBe('staff.backup_alert.backup_failed')
        ->and($row->payload['subject'] ?? null)->toContain('google');

    expect($sent)->toHaveCount(1);

    $html = (string) $sent[0]->getHtmlBody();
    $text = (string) $sent[0]->getTextBody();

    // Tự kiểm: đúng là đang đọc thân thư thật (có chi tiết lỗi), không phải một chuỗi rỗng.
    expect($html)->toContain('Hết dung lượng lưu trữ')
        ->and($text)->toContain('Hết dung lượng lưu trữ');

    foreach ([$sent[0]->getSubject(), $html, $text, json_encode($row->payload)] as $part) {
        expect((string) $part)->not->toContain('mat-khau-that-khong-duoc-lo');
    }
});
