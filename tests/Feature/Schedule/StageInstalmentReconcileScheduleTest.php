<?php

use App\Actions\Schedule\ReconcileStageTriggeredInstalments;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Schedule;

/**
 * M9 Task 6 — mục lịch của `ReconcileStageTriggeredInstalments`: 07:00 giờ Việt Nam hằng ngày
 * (phán quyết controller 4), TRƯỚC lượt nhắc quá hạn 08:00 (`instalments.remind`) để một đợt vừa
 * được đối chiếu kích hoạt với hạn ghi lùi được nhắc ngay sáng hôm đó; khoá chống chồng lấn có hạn
 * 60 phút; một tên máy đọc được (`instalments.reconcile-stage`).
 */
function stageReconcileEvent(): ?CallbackEvent
{
    return collect(Schedule::events())->first(fn ($event) => $event->description === 'instalments.reconcile-stage');
}

it('registers the stage-triggered instalment reconciliation under a stable machine name', function () {
    $event = stageReconcileEvent();

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs the ReconcileStageTriggeredInstalments action, not something else', function () {
    $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue(stageReconcileEvent());

    expect($callback)->toBeInstanceOf(ReconcileStageTriggeredInstalments::class);
});

it('is due at 07:00 Vietnam time every day, and at no other minute', function () {
    $event = stageReconcileEvent();
    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(7, 0));
    expect($event->isDue(app()))->toBeTrue('phải tới hạn lúc 07:00');

    foreach (['00:00', '06:59', '07:01', '07:30', '08:00', '12:00', '19:00'] as $notDue) {
        [$hour, $minute] = explode(':', $notDue);
        $this->travelTo($today->copy()->setTime((int) $hour, (int) $minute));
        expect($event->isDue(app()))->toBeFalse("không được tới hạn lúc {$notDue}");
    }
});

it('is due at seven o clock on a different day too', function () {
    $this->travelTo(today()->addDays(11)->setTime(7, 0));

    expect(stageReconcileEvent()->isDue(app()))->toBeTrue();
});

it('lets a killed run hold its overlap lock for an hour at most, not the default 1440 minutes', function () {
    expect(stageReconcileEvent()->expiresAt)->toBe(60);
});

/** Một đợt vừa được đối chiếu kích hoạt với hạn ghi lùi phải kịp vào lượt nhắc quá hạn CÙNG buổi sáng. */
it('runs before the overdue instalment reminder of the same morning', function () {
    $reminder = collect(Schedule::events())->first(fn ($event) => $event->description === 'instalments.remind');
    $today = today()->startOfDay();

    $this->travelTo($today->copy()->setTime(7, 0));
    expect(stageReconcileEvent()->isDue(app()))->toBeTrue()
        ->and($reminder->isDue(app()))->toBeFalse();

    $this->travelTo($today->copy()->setTime(8, 0));
    expect($reminder->isDue(app()))->toBeTrue()
        ->and(stageReconcileEvent()->isDue(app()))->toBeFalse();
});
