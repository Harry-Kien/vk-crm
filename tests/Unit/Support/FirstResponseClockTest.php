<?php

use App\Support\Intake\FirstResponseClock;

/*
 * M10 Task 5 (R5) — cách thư, thông báo và widget "Liên hệ chưa ai gọi lại" nói một khoảng chờ: không
 * bao giờ "0 giờ 35 phút" hay "4 giờ 0 phút".
 */
it('says a waiting time in hours and minutes, dropping a zero part', function (int $minutes, string $expected) {
    expect(FirstResponseClock::formatMinutes($minutes))->toBe($expected);
})->with([
    'chỉ phút' => [35, '35 phút'],
    'giờ tròn' => [240, '4 giờ'],
    'giờ và phút' => [260, '4 giờ 20 phút'],
    'chưa tới một phút' => [0, '0 phút'],
]);

it('reads the threshold of working hours from the configuration', function () {
    config(['vkcrm.intake_response_hours' => 6]);

    expect(FirstResponseClock::fromConfig()->thresholdHours)->toBe(6);
});
