<?php

use App\Jobs\PushDocumentFile;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| M14 Task 3 — ba mục lịch của kho tài liệu và các quan hệ thời gian (kế hoạch M14, R2)
|--------------------------------------------------------------------------
|
| Khuôn `QueueHandoverScheduleTest`: ghim tên, tần suất, khoá chống chồng lấn có hạn (không mục nào
| 1440 phút), và ba quan hệ mà một lần "chỉnh cho gọn" sẽ phá lặng lẽ:
|  - `retry_after` của kết nối `storage` > `$timeout` của job đẩy (không thì job tải 2 GB bị nhặt lại
|    và chạy song song với chính nó);
|  - TTL khoá đẩy > `$timeout` (cùng lý do, ở tầng khoá);
|  - khoá chồng lấn của `queue.storage` sống lâu hơn một lượt worker dài nhất.
*/

function stgScheduleEvent(string $name): Event
{
    $event = collect(Schedule::events())->first(fn (Event $event): bool => $event->description === $name);

    expect($event)->not->toBeNull("không có mục lịch {$name}");

    return $event;
}

it('queue.storage: mỗi phút rút hàng storage trên kết nối storage, --timeout bằng $timeout của job', function () {
    $event = stgScheduleEvent('queue.storage');

    expect($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('queue:work storage')
        ->and($event->command)->toContain('--queue=storage')
        ->and($event->command)->toContain('--stop-when-empty')
        ->and($event->command)->toContain('--max-time=50')
        ->and($event->command)->toContain('--timeout='.PushDocumentFile::TIMEOUT_SECONDS);
});

it('queue.storage: chạy nền, không chồng lên chính nó, khoá 40 phút sống lâu hơn một lượt worker dài nhất', function () {
    $event = stgScheduleEvent('queue.storage');

    expect($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(40)
        // Một job nhận ở giây thứ 50 (`--max-time`) chạy tới `$timeout`.
        ->and($event->expiresAt * 60)->toBeGreaterThan(50 + PushDocumentFile::TIMEOUT_SECONDS);
});

it('storage.push-pending: 15 phút một lần, khoá chồng lấn 15 phút', function () {
    $event = stgScheduleEvent('storage.push-pending');

    expect($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});

it('storage.purge-staged: mỗi giờ (phút 17, tránh lượt :00 đông), khoá chồng lấn 60 phút', function () {
    $event = stgScheduleEvent('storage.purge-staged');

    expect($event->expression)->toBe('17 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('không mục nào của kho giữ khoá chồng lấn 1440 phút mặc định', function () {
    foreach (['queue.storage', 'storage.push-pending', 'storage.purge-staged'] as $name) {
        expect(stgScheduleEvent($name)->expiresAt)->toBeLessThan(1440);
    }
});

it('retry_after của kết nối storage và TTL khoá đẩy đều lớn hơn $timeout của job đẩy', function () {
    expect(config('queue.connections.storage.retry_after'))->toBeGreaterThan(PushDocumentFile::TIMEOUT_SECONDS)
        ->and(config('vkcrm.storage.lock_ttl_seconds'))->toBeGreaterThan(PushDocumentFile::TIMEOUT_SECONDS)
        ->and((new PushDocumentFile(1))->timeout)->toBe(PushDocumentFile::TIMEOUT_SECONDS);
});
