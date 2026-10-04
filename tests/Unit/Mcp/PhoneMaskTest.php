<?php

use App\Support\Mcp\PhoneMask;

/*
| M11 R4 (Task 9): số điện thoại chỉ ra khỏi hệ thống ở dạng che `***678` [DC:108].
*/

it('masks (+84) 912 345 678 to ***678', function () {
    expect(PhoneMask::mask('(+84) 912 345 678'))->toBe('***678');
});

it('keeps only the last three digits whatever the spelling', function (string $phone) {
    expect(PhoneMask::mask($phone))->toBe('***678');
})->with([
    '0912345678',
    '0912.345.678',
    '+84912345678',
    '84912345678',
    '0084 912 345 678',
    '912345678',
]);

it('returns null when there is no phone number at all', function (?string $phone) {
    expect(PhoneMask::mask($phone))->toBeNull();
})->with([null, '', '   ', 'không có', '+()-']);

it('reveals no digit at all of a value too short to be a phone number', function (string $phone) {
    expect(PhoneMask::mask($phone))->toBe('***');
})->with(['1', '123', '12345', '1-2-3-4-5']);

it('never contains more than three digits', function () {
    foreach (['0912345678', '(028) 3822 1234', '123456', '+84 (0) 912 345 678 ext 99'] as $phone) {
        expect(preg_match_all('/\d/', PhoneMask::mask($phone)))->toBe(3, $phone);
    }
});
