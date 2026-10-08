<?php

use App\Actions\Schedule\CapturePerformanceSnapshots;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Schedule;

/**
 * M13 Task 7 — mục lịch của `CapturePerformanceSnapshots` (R10): 23:50 giờ Việt Nam hằng ngày, tên máy
 * đọc được `performance.snapshot`, khoá chống chồng lấn 30 phút. Khuôn `tests/Feature/Schedule/*`.
 */
function m13t7SchedEvent(): ?CallbackEvent
{
    return collect(Schedule::events())->first(fn ($event) => $event->description === 'performance.snapshot');
}

it('registers the daily snapshot under a stable machine name, with an overlap lock', function () {
    $event = m13t7SchedEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs the CapturePerformanceSnapshots action, not something else', function () {
    $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue(m13t7SchedEvent());

    expect($callback)->toBeInstanceOf(CapturePerformanceSnapshots::class);
});

it('is due at 23:50 Vietnam time every day, and at no other minute', function () {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');

    $event = m13t7SchedEvent();
    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(23, 50));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 23:50');

    foreach (['00:00', '07:00', '16:50', '23:49', '23:51', '23:59'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }

    $this->travelTo($today->copy()->addDays(9)->setTime(23, 50));
    expect($event->isDue(app()))->toBeTrue('mọi ngày, không chỉ hôm nay');
});

it('lets a killed run hold its overlap lock for 30 minutes at most, so the next night still runs', function () {
    expect(m13t7SchedEvent()->expiresAt)->toBe(30);
});
