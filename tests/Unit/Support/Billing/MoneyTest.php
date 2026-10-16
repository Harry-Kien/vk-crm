<?php

use App\Support\Billing\Money;
use Illuminate\Validation\ValidationException;

it('formats whole dong with dots between thousands and the dong sign', function () {
    expect(Money::format(1_250_000))->toBe('1.250.000 ₫')
        ->and(Money::format(0))->toBe('0 ₫')
        ->and(Money::format(999))->toBe('999 ₫')
        ->and(Money::format(33_333_333))->toBe('33.333.333 ₫');
});

it('parses dots as thousands separators', function () {
    expect(Money::parse('1.250.000'))->toBe(1_250_000);
});

it('parses plain digits', function () {
    expect(Money::parse('1250000'))->toBe(1_250_000);
});

it('ignores surrounding whitespace', function () {
    expect(Money::parse('  1.250.000 '))->toBe(1_250_000);
});

/**
 * Dấu chấm là phân cách NGHÌN, không bao giờ là dấu thập phân: "1.25" không có nghĩa là 1,25
 * đồng (đồng không có phần lẻ) và cũng không phải 125 đồng. Một chuỗi mà dấu chấm không chia
 * đúng nhóm ba chữ số là một chuỗi gõ sai, và phải bị trả lại cho người gõ.
 */
it('refuses a dot that is not a thousands separator, never reading it as a decimal', function (string $input) {
    expect(fn () => Money::parse($input))->toThrow(ValidationException::class);
})->with([
    'decimal-looking' => '1.25',
    'group of two' => '1.250.00',
    'group of four' => '1.2500',
    'leading dot' => '.250',
    'trailing dot' => '250.',
    'comma' => '1,25',
    'negative' => '-1.000',
    'empty' => '',
    'letters' => '12a',
    'spaces inside' => '1 250 000',
]);

it('names the field it was given in the validation error', function () {
    try {
        Money::parse('1.25', 'total_amount');
        $this->fail('Money::parse() đã không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('total_amount')
            ->and($exception->errors()['total_amount'][0])->toBe(__('billing.validation.money_format'));
    }
});

it('accepts exactly the ceiling', function () {
    expect(Money::parse(number_format(Money::MAX, 0, ',', '.')))->toBe(Money::MAX);
});

it('refuses one dong above the ceiling', function () {
    expect(fn () => Money::parse((string) (Money::MAX + 1)))
        ->toThrow(ValidationException::class, __('billing.validation.money_too_large', ['max' => Money::format(Money::MAX)]));
});

it('refuses a number too long to fit in an integer without overflowing', function () {
    expect(fn () => Money::parse('99999999999999999999999'))
        ->toThrow(ValidationException::class, __('billing.validation.money_too_large', ['max' => Money::format(Money::MAX)]));
});

it('formats a value for an input field that parse() reads straight back, without the dong sign', function (int $dong) {
    expect(Money::formatForInput($dong))->not->toContain('₫')
        ->and(Money::parse(Money::formatForInput($dong)))->toBe($dong);
})->with([0, 7, 1_250_000, Money::MAX]);

// =================================================================================================
// Làn rv (2026-10-15) — dạng rút gọn cho trục và nhãn biểu đồ: "60 tr", "1,2 tỷ". Tooltip và bảng số
// vẫn đủ `format()`. `public/charts/money.js` vẽ cùng quy tắc ở phía trình duyệt. Test dưới chỉ ghim
// từng nhánh của bản PHP; bản JS không có test tự động — sửa một bên thì chạy tay các ca này qua
// `compact()` của money.js (vd. bằng node) để hai bên không lệch.
// =================================================================================================

it('shortens an amount for a chart axis: million as "tr", billion as "tỷ", thousand as "nghìn", with a decimal comma', function (int $dong, string $expected) {
    expect(Money::compact($dong))->toBe($expected);
})->with([
    'không' => [0, '0'],
    'dưới một nghìn' => [950, '950'],
    'nghìn' => [500_000, '500 nghìn'],
    'nghìn có lẻ' => [12_500, '12,5 nghìn'],
    'triệu tròn' => [60_000_000, '60 tr'],
    'triệu có lẻ' => [1_250_000, '1,3 tr'],
    'triệu bỏ ,0' => [2_040_000, '2 tr'],
    'gần một tỷ vẫn là triệu' => [999_000_000, '999 tr'],
    'làm tròn lên một nghìn triệu thành tỷ' => [999_960_000, '1 tỷ'],
    'tỷ có lẻ' => [1_200_000_000, '1,2 tỷ'],
    'nghìn tỷ có dấu chấm nhóm' => [1_500_000_000_000, '1.500 tỷ'],
    'số âm giữ dấu' => [-60_000_000, '-60 tr'],
]);
