<?php

use App\Support\BusinessHours;
use Carbon\CarbonImmutable;

/*
 * M10 Task 5 (R5) — "giờ làm việc" của văn phòng: MỘT lịch cho ngưỡng phản hồi lần đầu
 * (`FirstResponseClock`), cho cổng giờ của tác vụ nhắc (`RemindUnansweredIntakes`) và cho thời gian
 * đã chờ trên thư/widget. Mặc định Thứ Hai–Thứ Sáu, 08:00–17:30, giờ `APP_TIMEZONE`; ngày lễ không mô
 * hình hoá ở M10.
 *
 * Ngày cố định: 2026-10-05 là Thứ Hai, 2026-10-07 Thứ Tư, 2026-10-09 Thứ Sáu, 2026-10-10 Thứ Bảy.
 */
function bhAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Ho_Chi_Minh');
}

function bhDefault(): BusinessHours
{
    return new BusinessHours([1, 2, 3, 4, 5], '08:00', '17:30', 'Asia/Ho_Chi_Minh');
}

it('reads Monday to Friday, 08:00 to 17:30, in the application timezone from the configuration', function () {
    $hours = BusinessHours::fromConfig();

    expect($hours->days)->toBe([1, 2, 3, 4, 5])
        ->and($hours->opensAt)->toBe('08:00')
        ->and($hours->closesAt)->toBe('17:30')
        ->and($hours->timezone)->toBe(config('app.timezone'))
        ->and($hours->timezone)->toBe('Asia/Ho_Chi_Minh');
});

it('adds working hours within one day, overnight, over a weekend, and from outside the hours', function (string $start, string $due) {
    expect(bhDefault()->addHours(bhAt($start), 4)->format('Y-m-d H:i'))->toBe($due);
})->with([
    'trong giờ' => ['2026-10-07 09:00', '2026-10-07 13:00'],
    'qua đêm' => ['2026-10-07 16:00', '2026-10-08 10:30'],
    'qua cuối tuần' => ['2026-10-09 15:00', '2026-10-12 09:30'],
    'nhận Thứ Bảy' => ['2026-10-10 10:00', '2026-10-12 12:00'],
    'trước giờ mở cửa' => ['2026-10-07 07:00', '2026-10-07 12:00'],
    'sau giờ đóng cửa' => ['2026-10-07 20:00', '2026-10-08 12:00'],
    'đúng lúc đóng cửa' => ['2026-10-07 17:30', '2026-10-08 12:00'],
    'tới đúng lúc đóng cửa' => ['2026-10-07 13:30', '2026-10-07 17:30'],
]);

it('keeps the seconds of the start when adding working hours', function () {
    expect(bhDefault()->addHours(bhAt('2026-10-07 09:00:42'), 4)->format('H:i:s'))->toBe('13:00:42');
});

it('answers in the office timezone for an instant given in another timezone', function () {
    // 02:00 UTC là 09:00 giờ Việt Nam: bốn giờ làm việc sau là 13:00 giờ Việt Nam.
    $due = bhDefault()->addHours(CarbonImmutable::parse('2026-10-07 02:00', 'UTC'), 4);

    expect($due->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i'))->toBe('2026-10-07 13:00');
});

it('counts a Saturday as a working day only when the configuration says so', function () {
    $withSaturday = new BusinessHours([1, 2, 3, 4, 5, 6], '08:00', '17:30', 'Asia/Ho_Chi_Minh');

    expect($withSaturday->addHours(bhAt('2026-10-09 15:00'), 4)->format('Y-m-d H:i'))->toBe('2026-10-10 09:30')
        ->and(bhDefault()->addHours(bhAt('2026-10-09 15:00'), 4)->format('Y-m-d H:i'))->toBe('2026-10-12 09:30');
});

it('counts the working minutes between two instants, and none backwards', function (string $from, string $to, int $minutes) {
    expect(bhDefault()->minutesBetween(bhAt($from), bhAt($to)))->toBe($minutes);
})->with([
    'trong giờ' => ['2026-10-07 09:00', '2026-10-07 13:15', 255],
    'qua đêm' => ['2026-10-07 16:00', '2026-10-08 10:30', 240],
    'tới trước giờ mở cửa hôm sau' => ['2026-10-07 16:00', '2026-10-08 07:00', 90],
    'qua cuối tuần' => ['2026-10-09 15:00', '2026-10-12 09:30', 240],
    'cả hai ngoài giờ' => ['2026-10-10 10:00', '2026-10-11 22:00', 0],
    'trọn một tuần' => ['2026-10-05 00:00', '2026-10-12 00:00', 5 * 570],
    'ngược chiều' => ['2026-10-07 13:00', '2026-10-07 09:00', 0],
    'chưa đủ một phút' => ['2026-10-07 09:00:00', '2026-10-07 09:00:59', 0],
]);

it('is open from opening time to closing time inclusive on a working day, and closed otherwise', function (string $at, bool $open) {
    expect(bhDefault()->isOpen(bhAt($at)))->toBe($open);
})->with([
    'mở cửa' => ['2026-10-07 08:00', true],
    'giữa ngày' => ['2026-10-07 12:15', true],
    'đúng lúc đóng cửa' => ['2026-10-07 17:30', true],
    'sau đóng cửa' => ['2026-10-07 17:31', false],
    'trước mở cửa' => ['2026-10-07 07:59', false],
    'Thứ Bảy trong khung giờ' => ['2026-10-10 10:00', false],
    'Chủ nhật' => ['2026-10-11 10:00', false],
]);

it('refuses a calendar without a working day or that closes before it opens', function (array $days, string $opens, string $closes) {
    new BusinessHours($days, $opens, $closes, 'Asia/Ho_Chi_Minh');
})->with([
    'không có ngày làm việc' => [[], '08:00', '17:30'],
    'ngày không có thật' => [[1, 8], '08:00', '17:30'],
    'ngày số 0' => [[0, 1, 2], '08:00', '17:30'],
    'đóng trước khi mở' => [[1, 2, 3, 4, 5], '17:30', '08:00'],
    'đóng đúng lúc mở' => [[1, 2, 3, 4, 5], '08:00', '08:00'],
    'giờ không đọc được' => [[1, 2, 3, 4, 5], '8h', '17:30'],
])->throws(InvalidArgumentException::class);
