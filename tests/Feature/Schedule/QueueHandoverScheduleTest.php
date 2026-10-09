<?php

use App\Actions\Matter\RequestHandoverPackage;
use App\Jobs\GenerateHandoverPackage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/**
 * Mục lịch riêng và kết nối hàng đợi riêng của gói bàn giao (M7 Task 4, R9). Ghim ba thứ có thể
 * lặng lẽ hỏng: tên/tần suất/khoá của mục lịch, quan hệ `retry_after` > `$timeout` (nếu không, một
 * gói chạy lâu bị worker khác nhặt lại và chạy song song), và việc mục `queue.drain` cũ không đổi.
 */
function qhsEvent(string $name): Event
{
    $event = collect(Schedule::events())->first(fn (Event $event): bool => $event->description === $name);

    expect($event)->not->toBeNull();

    return $event;
}

it('đăng ký mục queue.handover rút hàng handover trên kết nối handover, mỗi phút', function () {
    $event = qhsEvent('queue.handover');

    expect($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('queue:work handover')
        ->and($event->command)->toContain('--queue=handover')
        ->and($event->command)->toContain('--stop-when-empty')
        ->and($event->command)->toContain('--timeout='.GenerateHandoverPackage::TIMEOUT_SECONDS);
});

it('queue.handover không bao giờ chồng lên chính nó, và khoá hết hạn trong 25 phút chứ không 24 giờ', function () {
    $event = qhsEvent('queue.handover');

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(25)
        // Khoá sống lâu hơn một lần chạy tối đa của job, nếu không hai worker cùng dựng gói.
        ->and($event->expiresAt * 60)->toBeGreaterThan(GenerateHandoverPackage::TIMEOUT_SECONDS);
});

it('queue.handover chạy NỀN: một gói nén nhiều phút không giữ tiến trình schedule:run của phút đó', function () {
    // Chạy tiền cảnh, một lần dựng gói tới `--timeout` giây chặn mọi mục lịch đăng ký SAU nó
    // trong cùng lượt `schedule:run` — gồm các tác vụ hằng ngày Task 5/6 thêm vào cuối tệp.
    expect(qhsEvent('queue.handover')->runInBackground)->toBeTrue();
});

it('queue.drain vẫn chỉ rút hàng chính: không nhắc tới handover và không đổi tham số', function () {
    $drain = qhsEvent('queue.drain');

    expect($drain->command)->toContain('queue:work --stop-when-empty --max-time=50')
        ->and($drain->command)->not->toContain('handover')
        ->and($drain->expiresAt)->toBe(10);
});

it('kết nối handover: driver database, hàng handover, retry_after lớn hơn $timeout của job', function () {
    $connection = config('queue.connections.handover');

    expect($connection['driver'])->toBe('database')
        ->and($connection['queue'])->toBe('handover')
        ->and($connection['retry_after'])->toBe(1500)
        ->and($connection['retry_after'])->toBeGreaterThan(GenerateHandoverPackage::TIMEOUT_SECONDS)
        // Kết nối `database` chung giữ 90 giây — đúng thứ mà một gói lâu sẽ vượt qua.
        ->and(config('queue.connections.database.retry_after'))->toBeLessThan(GenerateHandoverPackage::TIMEOUT_SECONDS);
});

it('kết nối handover không đổi theo QUEUE_CONNECTION: test chạy sync vẫn đẩy gói vào bảng jobs', function () {
    expect(config('queue.default'))->toBe('sync')
        ->and(config('queue.connections.handover.driver'))->toBe('database');
});

/*
 * M14 Task 4 (kế hoạch R12): gói bàn giao nay còn phải TẢI tới 2 GB từ kho về thư mục làm việc trước
 * khi nén (ước trên shared hosting: tải ≈ 410 giây, nén 120–300 giây, chép ≈ 60 giây). Năm con số
 * tính lại cùng nhau; ba quan hệ dưới đây là thứ làm chúng đúng, không phải chính các con số.
 */
it('M14 R12: năm con số thời gian của gói bàn giao', function () {
    expect(GenerateHandoverPackage::TIMEOUT_SECONDS)->toBe(1200)
        ->and(config('queue.connections.handover.retry_after'))->toBe(1500)
        ->and(qhsEvent('queue.handover')->command)->toContain('--timeout=1200')
        ->and(qhsEvent('queue.handover')->expiresAt)->toBe(25)
        ->and(RequestHandoverPackage::STALE_AFTER_MINUTES)->toBe(90);
});

it('M14 R12: retry_after > $timeout, và --timeout của lịch = $timeout của job', function () {
    $job = new GenerateHandoverPackage(1, 1);

    expect(config('queue.connections.handover.retry_after'))->toBeGreaterThan(GenerateHandoverPackage::TIMEOUT_SECONDS)
        ->and($job->timeout)->toBe(GenerateHandoverPackage::TIMEOUT_SECONDS);

    preg_match_all('/--timeout=(\d+)/', qhsEvent('queue.handover')->command, $matches);

    expect($matches[1])->toBe([(string) GenerateHandoverPackage::TIMEOUT_SECONDS]);
});

it('M14 R12: "kẹt" chỉ sau khi mọi lượt của job chắc chắn đã chết — STALE × 60 > retry_after + tries × timeout + Σbackoff', function () {
    // Nhỏ hơn thì nút "sinh lại" mở khoá giữa một lượt còn sống: hai gói cùng dựng.
    $job = new GenerateHandoverPackage(1, 1);
    $worst = config('queue.connections.handover.retry_after')
        + $job->tries * GenerateHandoverPackage::TIMEOUT_SECONDS
        + array_sum($job->backoff());

    expect(RequestHandoverPackage::STALE_AFTER_MINUTES * 60)->toBeGreaterThan($worst);
});
