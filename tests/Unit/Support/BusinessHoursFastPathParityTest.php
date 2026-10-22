<?php

use App\Support\BusinessHours;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/*
 * Rà soát toàn hệ thống 2026-10-22, làn perf2 — `BusinessHours::minutesBetween()` (giờ làm việc M10, dùng chung cho
 * ngưỡng phản hồi lần đầu, đồng hồ nhận việc và thời gian phản hồi P3 của "Hiệu suất theo kỳ") nay tính bằng số
 * nguyên khi múi giờ không đổi độ lệch trong khoảng cần tính. Đáp án phải BẰNG ĐÚNG cách tính theo lịch trước
 * đó. `bhCalendarMinutes()` dưới đây là bản sao NGUYÊN VĂN của thân hàm cũ (vòng từng ngày bằng Carbon) — không
 * sửa nó cùng lúc với lớp: nó là chuẩn để so.
 *
 * Ngày lễ không mô hình hoá (M10): không có biên ngày lễ để thử.
 */
function bhCalendarMinutes(BusinessHours $hours, CarbonInterface $from, CarbonInterface $to): int
{
    $minuteOfDay = function (string $time): int {
        preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts);

        return (int) $parts[1] * 60 + (int) $parts[2];
    };
    $local = fn (CarbonInterface $at): CarbonImmutable => CarbonImmutable::instance($at)->setTimezone($hours->timezone);
    $windowOn = function (CarbonImmutable $day) use ($hours, $minuteOfDay): ?array {
        if (! in_array($day->dayOfWeekIso, $hours->days, true)) {
            return null;
        }

        $midnight = $day->startOfDay();

        return [
            $midnight->addMinutes($minuteOfDay($hours->opensAt)),
            $midnight->addMinutes($minuteOfDay($hours->closesAt)),
        ];
    };

    $from = $local($from);
    $to = $local($to);
    $seconds = 0;
    $cursor = $from;

    for ($i = 0; $i < 3660 && $cursor->lessThan($to); $i++) {
        $window = $windowOn($cursor);

        if ($window !== null) {
            $start = $cursor->max($window[0]);
            $end = $to->min($window[1]);
            $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
        }

        $cursor = $cursor->addDay()->startOfDay();
    }

    return intdiv($seconds, 60);
}

/** Lịch thử: lịch của văn phòng, Thứ Hai–Thứ Bảy, chỉ Chủ nhật, cả tuần gần trọn ngày, một khung một phút. */
function bhCalendars(string $timezone): array
{
    return [
        'T2–T6 08:00–17:30' => new BusinessHours([1, 2, 3, 4, 5], '08:00', '17:30', $timezone),
        'T2–T7 07:30–12:00' => new BusinessHours([1, 2, 3, 4, 5, 6], '07:30', '12:00', $timezone),
        'chỉ Chủ nhật 13:15–20:45' => new BusinessHours([7], '13:15', '20:45', $timezone),
        'cả tuần 00:00–23:59' => new BusinessHours([1, 2, 3, 4, 5, 6, 7], '00:00', '23:59', $timezone),
        'T4 07:15–07:16' => new BusinessHours([3], '07:15', '07:16', $timezone),
    ];
}

/** Một thời điểm có micro giây, đưa vào ở một múi giờ và một lớp Carbon tuỳ ý (hàm phải đọc ở múi giờ văn phòng). */
function bhInstant(int $timestamp, int $micro, int $variant): CarbonInterface
{
    $zones = ['Asia/Ho_Chi_Minh', 'UTC', 'America/Los_Angeles', 'Asia/Tokyo'];
    $at = CarbonImmutable::createFromTimestamp($timestamp, 'UTC')->setMicrosecond($micro)->setTimezone($zones[$variant % 4]);

    return $variant % 2 === 0 ? $at : Carbon::instance($at);
}

it('gives exactly the calendar answer on thousands of random pairs, every calendar and timezone', function (string $timezone) {
    mt_srand(crc32($timezone));
    $compared = 0;

    foreach (bhCalendars($timezone) as $label => $hours) {
        foreach (range(1, 400) as $n) {
            $from = mt_rand(1_577_836_800, 1_924_991_999); // 2020-01-01 … 2030-12-31 UTC
            $span = match ($n % 6) {
                0 => mt_rand(0, 3_600),             // trong một giờ
                1 => mt_rand(0, 96 * 3_600),        // tới bốn ngày — thời gian phản hồi của dữ liệu đo M13
                2 => mt_rand(0, 21 * 86_400),       // tới ba tuần
                3 => mt_rand(-3 * 86_400, 0),       // đảo ngược hoặc bằng nhau
                4 => mt_rand(0, 60 * 86_400),       // tới hai tháng (và qua đổi giờ mùa hè ở múi có đổi giờ)
                default => mt_rand(0, 59),          // dưới một phút
            };
            $fromAt = bhInstant($from, mt_rand(0, 999_999), $n);
            $toAt = bhInstant($from + $span, mt_rand(0, 999_999), $n + 1);

            expect($hours->minutesBetween($fromAt, $toAt))
                ->toBe(bhCalendarMinutes($hours, $fromAt, $toAt), "{$label}: {$fromAt->toIso8601ZuluString('microsecond')} → {$toAt->toIso8601ZuluString('microsecond')}");
            $compared++;
        }
    }

    expect($compared)->toBe(2_000);
})->with([
    'Asia/Ho_Chi_Minh',
    'UTC',
    'Asia/Kathmandu',          // +05:45
    'Europe/Berlin',           // đổi giờ mùa hè
    'America/New_York',        // đổi giờ mùa hè
    'Australia/Lord_Howe',     // đổi giờ nửa tiếng
    '+07:00',                  // dạng độ lệch: không có bảng chuyển đổi
]);

it('gives exactly the calendar answer at the edges of the working day and week', function (string $from, string $to) {
    foreach (bhCalendars('Asia/Ho_Chi_Minh') as $label => $hours) {
        $fromAt = CarbonImmutable::parse($from, 'Asia/Ho_Chi_Minh');
        $toAt = CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh');

        expect($hours->minutesBetween($fromAt, $toAt))->toBe(bhCalendarMinutes($hours, $fromAt, $toAt), $label);
    }
})->with([
    'đúng giờ mở → đúng giờ đóng' => ['2026-10-07 08:00:00', '2026-10-07 17:30:00'],
    'một micro giây trước giờ đóng' => ['2026-10-07 08:00:00', '2026-10-07 17:29:59.999999'],
    'một micro giây sau giờ mở' => ['2026-10-07 08:00:00.000001', '2026-10-07 09:00:00'],
    'cùng một giây, khác micro giây' => ['2026-10-07 09:00:00.100000', '2026-10-07 09:00:00.900000'],
    'giây lẻ ở hai đầu' => ['2026-10-07 09:00:59.999999', '2026-10-07 09:02:00.000001'],
    'qua nửa đêm' => ['2026-10-07 23:59:59.999999', '2026-10-08 00:00:00.000001'],
    'tối → sáng hôm sau' => ['2026-10-07 20:00', '2026-10-08 08:30'],
    'Thứ Sáu chiều → Thứ Hai sáng' => ['2026-10-09 17:29:59.5', '2026-10-12 08:00:00.5'],
    'trong Thứ Bảy' => ['2026-10-10 09:00', '2026-10-10 15:00'],
    'Thứ Bảy → Chủ nhật' => ['2026-10-10 23:00', '2026-10-11 23:30'],
    'đúng nửa đêm → đúng nửa đêm' => ['2026-10-05 00:00', '2026-10-12 00:00'],
    'trước giờ mở cùng ngày' => ['2026-10-07 06:00', '2026-10-07 07:59:59'],
    'sau giờ đóng cùng ngày' => ['2026-10-07 17:30:00.000001', '2026-10-07 23:00'],
    'qua Tết dương lịch' => ['2026-12-31 16:00', '2027-01-04 10:00'],
    'qua ngày nhuận' => ['2028-02-28 16:00', '2028-03-01 09:00'],
    'trước 1970' => ['1969-12-30 10:00:00.25', '1970-01-02 10:00'],
    'qua lần đổi múi giờ cuối của Sài Gòn (1975)' => ['1975-06-10 09:00', '1975-06-17 16:00'],
    'đảo ngược' => ['2026-10-08 10:00', '2026-10-07 10:00'],
    'bằng nhau' => ['2026-10-07 10:00', '2026-10-07 10:00'],
]);

it('gives exactly the calendar answer across daylight saving changes', function (string $timezone, string $from, string $to) {
    foreach (bhCalendars($timezone) as $label => $hours) {
        $fromAt = CarbonImmutable::parse($from, $timezone);
        $toAt = CarbonImmutable::parse($to, $timezone);

        expect($hours->minutesBetween($fromAt, $toAt))->toBe(bhCalendarMinutes($hours, $fromAt, $toAt), $label);
    }
})->with([
    'Berlin, sang giờ mùa hè' => ['Europe/Berlin', '2026-03-27 16:00', '2026-03-30 10:00'],
    'Berlin, về giờ mùa đông' => ['Europe/Berlin', '2026-10-23 16:00', '2026-10-26 10:00'],
    'Berlin, ngày đổi giờ' => ['Europe/Berlin', '2026-03-29 00:00', '2026-03-29 23:59'],
    'New York, sang giờ mùa hè' => ['America/New_York', '2026-03-06 16:00', '2026-03-09 10:00'],
    'Lord Howe, nửa tiếng' => ['Australia/Lord_Howe', '2026-04-03 16:00', '2026-04-06 10:00'],
    'Berlin, đổi giờ cách hai ngày sau đầu cuối' => ['Europe/Berlin', '2026-03-25 09:00', '2026-03-27 12:00'],
]);

it('stops at the same ten-year ceiling as the calendar walk', function () {
    $hours = new BusinessHours([1, 2, 3, 4, 5], '08:00', '17:30', 'Asia/Ho_Chi_Minh');
    $from = CarbonImmutable::parse('2010-03-03 10:00', 'Asia/Ho_Chi_Minh');

    foreach (['2020-02-27 11:00', '2020-03-15 12:00', '2026-10-07 09:00'] as $to) {
        $toAt = CarbonImmutable::parse($to, 'Asia/Ho_Chi_Minh');

        expect($hours->minutesBetween($from, $toAt))->toBe(bhCalendarMinutes($hours, $from, $toAt), $to);
    }
});

it('takes the integer path for the office timezone and still matches the calendar there', function () {
    // Gọi thẳng nhánh số nguyên, để phép so ở trên không thể xanh nhờ mọi cặp lặng lẽ đi đường lịch.
    $hours = new BusinessHours([1, 2, 3, 4, 5], '08:00', '17:30', 'Asia/Ho_Chi_Minh');
    $offset = (fn (int $from, int $to): ?int => $this->fixedOffsetBetween($from, $to))->call($hours, 1_790_000_000, 1_790_400_000);
    $fast = fn (CarbonInterface $from, CarbonInterface $to): int => (fn (): int => $this->minutesBetweenAtFixedOffset($from->getTimestamp(), $to->getTimestamp(), 25_200))->call($hours);

    mt_srand(20261022);
    foreach (range(1, 500) as $n) {
        $from = mt_rand(1_577_836_800, 1_924_991_999);
        $fromAt = bhInstant($from, mt_rand(0, 999_999), $n);
        $toAt = bhInstant($from + mt_rand(1, 10 * 86_400), mt_rand(0, 999_999), $n + 1);

        expect($fast($fromAt, $toAt))->toBe(bhCalendarMinutes($hours, $fromAt, $toAt));
    }

    expect($offset)->toBe(25_200)
        ->and((fn (int $from, int $to): ?int => $this->fixedOffsetBetween($from, $to))->call(new BusinessHours([1], '08:00', '17:30', 'Europe/Berlin'), 1_774_000_000, 1_775_000_000))->toBeNull()
        ->and((fn (int $from, int $to): ?int => $this->fixedOffsetBetween($from, $to))->call(new BusinessHours([1], '08:00', '17:30', '+07:00'), 1_790_000_000, 1_790_400_000))->toBeNull();
});
