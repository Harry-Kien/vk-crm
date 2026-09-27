<?php

use App\Support\Billing\SplitByPercent;
use Illuminate\Validation\ValidationException;

/**
 * Ba trường hợp phần dư của kế hoạch M9 Task 4: mọi đợt trừ đợt cuối lấy `intdiv(total × p, 100)`,
 * đợt cuối lấy phần còn lại — nên tổng các đợt luôn bằng ĐÚNG `total`, không lệch một đồng.
 */
it('splits 33/33/34 of 100.000.000 exactly', function () {
    expect(SplitByPercent::split(100_000_000, [33, 33, 34]))
        ->toBe([33_000_000, 33_000_000, 34_000_000]);
});

it('splits 10.000.000 into three even parts with the leftover dong on the last one', function () {
    expect(SplitByPercent::evenly(10_000_000, 3))
        ->toBe([3_333_333, 3_333_333, 3_333_334]);
});

it('drops the rounding remainder of a percent split onto the last instalment', function () {
    // 33.333.333 × 30% = 9.999.999,9 → 9.999.999 cho mỗi đợt đầu; đợt cuối nhận
    // 33.333.333 − 19.999.998 = 13.333.335, không phải 40% làm tròn (13.333.333).
    expect(SplitByPercent::split(33_333_333, [30, 30, 40]))
        ->toBe([9_999_999, 9_999_999, 13_333_335]);
});

it('always sums back to the total', function (int $total, array $percents) {
    expect(array_sum(SplitByPercent::split($total, $percents)))->toBe($total);
})->with([
    [1, [50, 50]],
    [7, [33.33, 33.33, 33.34]],
    [99_999_999, [12.5, 12.5, 75]],
    [123_456_789, [10, 20, 30, 40]],
]);

it('reads two-decimal percents without float drift', function () {
    // 33,33% của 100.000.000 = 33.330.000 đúng; 0.1 + 0.2 kiểu float sẽ lệch ở đây nếu tính
    // bằng số thực thay vì phần vạn nguyên.
    expect(SplitByPercent::split(100_000_000, ['33.33', '33.33', '33.34']))
        ->toBe([33_330_000, 33_330_000, 33_340_000]);
});

it('refuses percents that do not add up to exactly 100', function (array $percents) {
    expect(fn () => SplitByPercent::split(100_000_000, $percents))
        ->toThrow(ValidationException::class, __('billing.validation.percents_must_total_100'));
})->with([
    'short' => [[30, 30, 30]],
    'over' => [[50, 50, 1]],
    'short by a hundredth' => [['33.33', '33.33', '33.33']],
]);

it('refuses a zero or negative percent', function (array $percents) {
    expect(fn () => SplitByPercent::split(100_000_000, $percents))
        ->toThrow(ValidationException::class, __('billing.validation.percent_out_of_range'));
})->with([
    'zero' => [[0, 100]],
    'negative' => [[-10, 110]],
]);

it('refuses a percent with more than two decimals', function () {
    expect(fn () => SplitByPercent::split(100_000_000, ['33.333', '66.667']))
        ->toThrow(ValidationException::class, __('billing.validation.percent_out_of_range'));
});

it('refuses an empty percent split, whose percents add up to 0', function () {
    expect(fn () => SplitByPercent::split(100_000_000, []))
        ->toThrow(ValidationException::class, __('billing.validation.percents_must_total_100'));
});

it('refuses an even split into zero parts', function () {
    expect(fn () => SplitByPercent::evenly(100_000_000, 0))
        ->toThrow(ValidationException::class, __('billing.validation.split_needs_parts'));
});

it('keeps one split into a single instalment whole', function () {
    expect(SplitByPercent::split(50_000_000, [100]))->toBe([50_000_000])
        ->and(SplitByPercent::evenly(50_000_000, 1))->toBe([50_000_000]);
});

/**
 * `amountsForRows()` — lượt rà soát cuối M9, I5: số tiền của từng dòng lịch thu nhập bằng phần
 * trăm, xem trước VÀ lưu bằng cùng một hàm.
 */
it('gives the leftover dong to the last row when every row is a percent and they add up to 100', function () {
    expect(SplitByPercent::amountsForRows(33_333_333, ['30', '30', '40']))
        ->toBe([9_999_999, 9_999_999, 13_333_335]);
});

it('rounds each percent row down, with no leftover row, when some row is typed as an amount', function () {
    expect(SplitByPercent::amountsForRows(33_333_333, ['30', null, '']))
        ->toBe([9_999_999, null, null]);
});

it('rounds each percent row down, with no leftover row, when the percents do not add up to 100', function () {
    expect(SplitByPercent::amountsForRows(33_333_333, ['30', '30']))
        ->toBe([9_999_999, 9_999_999]);
});

it('points a malformed or over-100 percent at its own row field', function (string $percent) {
    try {
        SplitByPercent::amountsForRows(10_000_000, ['50', $percent], 'instalment_changes');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('instalment_changes.1.percent_basis');

        return;
    }

    test()->fail('Không ném ValidationException.');
})->with(['12,5', '100.01', '0']);

it('gives an empty list for no rows', function () {
    expect(SplitByPercent::amountsForRows(10_000_000, []))->toBe([]);
});

it('reads one percent of a total the same way split() rounds its non-last parts', function () {
    expect(SplitByPercent::part(33_333_333, '30'))->toBe(9_999_999);
});
