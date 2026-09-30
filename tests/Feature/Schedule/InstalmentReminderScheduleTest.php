<?php

use App\Actions\Schedule\RemindOverdueInstalments;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Schedule;

/**
 * M9 Task 11 — mục lịch của `RemindOverdueInstalments`: 08:00 giờ Việt Nam hằng ngày, khoá chống
 * chồng lấn có hạn 60 phút, một tên máy đọc được (`instalments.remind`).
 *
 * Một lượt 08:00 bị lỡ chỉ trễ một ngày (lượt sau vẫn thấy đợt quá hạn và chưa nhắc trong 7 ngày),
 * nên — khác `deadlines.check` — không chạy lặp trong ngày.
 */
function instalmentReminderEvent(): ?CallbackEvent
{
    return collect(Schedule::events())->first(fn ($event) => $event->description === 'instalments.remind');
}

it('registers the overdue instalment reminder under a stable machine name', function () {
    $event = instalmentReminderEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs the RemindOverdueInstalments action, not something else', function () {
    $event = instalmentReminderEvent();
    $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($event);

    expect($callback)->toBeInstanceOf(RemindOverdueInstalments::class);
});

it('is due at 08:00 Vietnam time every day, and at no other minute', function () {
    $event = instalmentReminderEvent();
    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(8, 0));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 08:00');

    foreach (['00:00', '07:59', '08:01', '08:30', '12:00', '15:00', '20:00'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('is due on the eight o clock of a different day too', function () {
    $event = instalmentReminderEvent();

    $this->travelTo(today()->addDays(9)->setTime(8, 0));

    expect($event->isDue(app()))->toBeTrue();
});

it('lets a killed run hold its overlap lock for an hour at most, not the default 1440 minutes', function () {
    expect(instalmentReminderEvent()->expiresAt)->toBe(60);
});
