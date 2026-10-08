<?php

use App\Support\Billing\RevenueFilters;
use App\Support\Performance\PerformancePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * M13 Task 6 — `PerformancePeriod` (R16, R19): kỳ của trang "Hiệu suất theo kỳ". Mặc định tháng trước;
 * `custom` dài tối đa 366 ngày, ngày cuối không quá hôm nay; hai cận đủ giờ như `RevenueFilters::bounds()`
 * (test đồng nhất cho `this_month`, `this_quarter`, `custom`; `last_month`, `last_quarter` so qua `custom`
 * với cùng hai ngày); `cutoff()` = min(23:59:59 ngày cuối kỳ, now()).
 *
 * Chạy cả dưới `test:mariadb` (tuần tự). Hàm toàn cục mang tiền tố `m13bPp` (làn m13b, tệp này).
 */

/** Thông điệp lỗi của trường `$field` khi `fromFilters($filters)` từ chối; ném nếu nó không từ chối. */
function m13bPpError(array $filters, string $field): string
{
    try {
        PerformancePeriod::fromFilters($filters);
    } catch (ValidationException $exception) {
        return (string) ($exception->errors()[$field][0] ?? '');
    }

    throw new RuntimeException('fromFilters() đã nhận một kỳ đáng lẽ bị từ chối.');
}

/** @return array{0: string, 1: string} */
function m13bPpRange(PerformancePeriod $period): array
{
    return [$period->from->toDateString(), $period->to->toDateString()];
}

it('defaults to last month, for no filters, an empty array and a blank period', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    foreach ([null, [], ['period' => null], ['period' => '']] as $filters) {
        $period = PerformancePeriod::fromFilters($filters);

        expect($period->key)->toBe(PerformancePeriod::LAST_MONTH)
            ->and(m13bPpRange($period))->toBe(['2026-09-01', '2026-09-30']);
    }

    expect(PerformancePeriod::DEFAULT)->toBe(PerformancePeriod::LAST_MONTH);
});

/** Ngày 31: `subMonth()` trần lật sang chính tháng này (31/09 không có) — kỳ phải là tháng 9 nguyên vẹn. */
it('reads last month on 31 October as September, and on 1 November as October', function () {
    $this->travelTo(Carbon::parse('2026-10-31 23:30:00'));
    expect(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_month'])))->toBe(['2026-09-01', '2026-09-30']);

    $this->travelTo(Carbon::parse('2026-11-01 00:00:05'));
    expect(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_month'])))->toBe(['2026-10-01', '2026-10-31']);

    $this->travelTo(Carbon::parse('2026-03-31 09:00:00'));
    expect(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_month'])))->toBe(['2026-02-01', '2026-02-28']);
});

it('reads this month, this quarter and last quarter, across a year boundary', function () {
    $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));

    expect(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'this_month'])))->toBe(['2026-10-01', '2026-10-31'])
        ->and(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'this_quarter'])))->toBe(['2026-10-01', '2026-12-31'])
        ->and(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_quarter'])))->toBe(['2026-07-01', '2026-09-30']);

    $this->travelTo(Carbon::parse('2027-01-15 10:00:00'));

    expect(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_quarter'])))->toBe(['2026-10-01', '2026-12-31'])
        ->and(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'last_month'])))->toBe(['2026-12-01', '2026-12-31']);
});

it('takes a custom period of exactly 366 days, ending today', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $period = PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2025-10-15', 'date_to' => '2026-10-15']);

    expect($period->key)->toBe(PerformancePeriod::CUSTOM)
        ->and(m13bPpRange($period))->toBe(['2025-10-15', '2026-10-15'])
        ->and(PerformancePeriod::MAX_CUSTOM_DAYS)->toBe(366);
});

it('refuses a custom period of 367 days with a vietnamese message on the end date', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $message = m13bPpError(['period' => 'custom', 'date_from' => '2025-10-14', 'date_to' => '2026-10-15'], 'date_to');

    expect($message)->toBe(__('performance.period.errors.too_long', ['max' => 366, 'days' => 367]))
        ->and($message)->not->toStartWith('performance.')
        ->and($message)->toContain('366');
});

it('refuses a custom period ending tomorrow, and takes the same one ending today', function () {
    $this->travelTo(Carbon::parse('2026-10-15 23:59:00'));

    expect(m13bPpError(['period' => 'custom', 'date_from' => '2026-10-01', 'date_to' => '2026-10-16'], 'date_to'))
        ->toBe(__('performance.period.errors.future'))
        ->and(__('performance.period.errors.future'))->not->toStartWith('performance.')
        ->and(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2026-10-01', 'date_to' => '2026-10-15'])))
        ->toBe(['2026-10-01', '2026-10-15']);
});

it('refuses a custom period that starts after it ends, and takes a single day', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    expect(m13bPpError(['period' => 'custom', 'date_from' => '2026-09-10', 'date_to' => '2026-09-09'], 'date_from'))
        ->toBe(__('performance.period.errors.order'))
        ->and(m13bPpRange(PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2026-09-10', 'date_to' => '2026-09-10'])))
        ->toBe(['2026-09-10', '2026-09-10']);
});

it('refuses a custom period with a missing or impossible date, and an unknown period', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    expect(m13bPpError(['period' => 'custom', 'date_to' => '2026-09-30'], 'date_from'))->toBe(__('performance.period.errors.dates_required'))
        ->and(m13bPpError(['period' => 'custom', 'date_from' => '2026-09-01', 'date_to' => ''], 'date_to'))->toBe(__('performance.period.errors.dates_required'))
        ->and(m13bPpError(['period' => 'custom', 'date_from' => '2026-02-31', 'date_to' => '2026-03-10'], 'date_from'))->toBe(__('performance.period.errors.invalid_date'))
        ->and(m13bPpError(['period' => 'custom', 'date_from' => 'hôm qua', 'date_to' => '2026-03-10'], 'date_from'))->toBe(__('performance.period.errors.invalid_date'))
        ->and(m13bPpError(['period' => 'this_year'], 'period'))->toBe(__('performance.period.errors.unknown'));
});

/** R16: `PerformancePeriod` là bộ đọc kỳ thứ hai có chủ đích — hai cận phải trùng `RevenueFilters` từng giây. */
it('gives the same two full-day bounds as RevenueFilters for this month, this quarter and custom, on the last day of a month', function () {
    $this->travelTo(Carbon::parse('2026-09-30 18:00:00'));

    foreach (['this_month', 'this_quarter'] as $key) {
        expect(PerformancePeriod::fromFilters(['period' => $key])->bounds())
            ->toBe(RevenueFilters::fromPageFilters(['period' => $key])->bounds());
    }

    $custom = ['period' => 'custom', 'date_from' => '2026-08-15', 'date_to' => '2026-09-30'];

    expect(PerformancePeriod::fromFilters($custom)->bounds())->toBe(RevenueFilters::fromPageFilters($custom)->bounds())
        ->and(PerformancePeriod::fromFilters(['period' => 'this_month'])->bounds())->toBe(['2026-09-01 00:00:00', '2026-09-30 23:59:59']);
});

it('gives last month and last quarter the bounds RevenueFilters gives the same two days as a custom period', function () {
    $this->travelTo(Carbon::parse('2026-10-31 18:00:00'));

    foreach (['last_month', 'last_quarter'] as $key) {
        $period = PerformancePeriod::fromFilters(['period' => $key]);
        [$from, $to] = m13bPpRange($period);

        expect($period->bounds())->toBe(RevenueFilters::fromPageFilters(['period' => 'custom', 'date_from' => $from, 'date_to' => $to])->bounds());
    }

    expect(PerformancePeriod::fromFilters(['period' => 'last_quarter'])->bounds())->toBe(['2026-07-01 00:00:00', '2026-09-30 23:59:59']);
});

it('cuts a closed period at 23:59:59 of its last day, and a running one at now', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:20:30'));

    $closed = PerformancePeriod::fromFilters(['period' => 'last_month']);
    $running = PerformancePeriod::fromFilters(['period' => 'this_month']);
    $endingToday = PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2026-10-01', 'date_to' => '2026-10-15']);

    expect($closed->cutoff()->toDateTimeString())->toBe('2026-09-30 23:59:59')
        ->and($closed->isRunning())->toBeFalse()
        ->and($running->cutoff()->toDateTimeString())->toBe('2026-10-15 10:20:30')
        ->and($running->isRunning())->toBeTrue()
        ->and($endingToday->cutoff()->toDateTimeString())->toBe('2026-10-15 10:20:30')
        ->and($endingToday->isRunning())->toBeTrue();

    $this->travelTo(Carbon::parse('2026-10-16 00:00:00'));

    expect($endingToday->cutoff()->toDateTimeString())->toBe('2026-10-15 23:59:59')
        ->and($endingToday->isRunning())->toBeFalse();
});

it('reads the trailing 90 days on 4 October 2026 as 6 July to 3 October, a closed period', function () {
    $this->travelTo(Carbon::parse('2026-10-04 08:00:00'));

    $period = PerformancePeriod::trailingDays(90);

    expect(m13bPpRange($period))->toBe(['2026-07-06', '2026-10-03'])
        ->and($period->key)->toBe(PerformancePeriod::TRAILING)
        ->and((int) $period->from->diffInDays($period->to) + 1)->toBe(90)
        ->and($period->cutoff()->toDateTimeString())->toBe('2026-10-03 23:59:59')
        ->and($period->isRunning())->toBeFalse();
});

it('labels a period in vietnamese with its name and its two days', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    expect(PerformancePeriod::fromFilters(['period' => 'last_month'])->label())
        ->toBe(__('performance.period.label', ['name' => __('performance.period.options.last_month'), 'from' => '01/09/2026', 'to' => '30/09/2026']))
        ->and(PerformancePeriod::fromFilters(['period' => 'last_month'])->label())->toContain('Tháng trước')
        ->and(PerformancePeriod::trailingDays(90)->label())->toContain('90')
        ->and(PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2026-09-05', 'date_to' => '2026-09-20'])->label())
        ->toContain('05/09/2026')->toContain('20/09/2026');

    foreach (PerformancePeriod::CHOICES as $key) {
        expect(__("performance.period.options.{$key}"))->not->toStartWith('performance.');
    }
});

it('round-trips the filters it was read from, so the page can keep them in a locked property', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $custom = PerformancePeriod::fromFilters(['period' => 'custom', 'date_from' => '2026-09-05', 'date_to' => '2026-09-20']);
    $preset = PerformancePeriod::fromFilters(['period' => 'last_quarter']);

    expect($custom->toFilters())->toBe(['period' => 'custom', 'date_from' => '2026-09-05', 'date_to' => '2026-09-20'])
        ->and(PerformancePeriod::fromFilters($custom->toFilters())->bounds())->toBe($custom->bounds())
        ->and($preset->toFilters())->toBe(['period' => 'last_quarter'])
        ->and(PerformancePeriod::fromFilters($preset->toFilters())->bounds())->toBe($preset->bounds());
});
