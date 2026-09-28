<?php

use App\Support\Billing\Vat;

it('carves the tax out of a total that already includes it, the net part taking the remainder', function () {
    // 33.333.333 × 10 / 110 = 3.030.303 (chia hết); phần chưa thuế = 30.303.030.
    expect(Vat::tax(33_333_333, 10))->toBe(3_030_303)
        ->and(Vat::net(33_333_333, 10))->toBe(30_303_030)
        ->and(Vat::tax(33_333_333, 10) + Vat::net(33_333_333, 10))->toBe(33_333_333);
});

it('rounds the tax down and gives the leftover dong to the net part', function () {
    // 10.000.001 × 8 / 108 = 740.740,8… → thuế 740.740, chưa thuế nhận phần dư 9.259.261.
    expect(Vat::tax(10_000_001, 8))->toBe(740_740)
        ->and(Vat::net(10_000_001, 8))->toBe(9_259_261);
});

it('reads a 55.000.000 total at 10 percent as 50.000.000 plus 5.000.000', function () {
    expect(Vat::net(55_000_000, 10))->toBe(50_000_000)
        ->and(Vat::tax(55_000_000, 10))->toBe(5_000_000);
});

/**
 * `null` và `0` là hai chuyện khác nhau ở `contracts.vat_rate_percent` (không có dòng thuế, và
 * có hoá đơn thuế suất 0%), nhưng cùng một con số: không đồng thuế nào nằm trong tổng.
 */
it('has no tax for a null rate or a zero rate', function (?int $rate) {
    expect(Vat::tax(50_000_000, $rate))->toBe(0)
        ->and(Vat::net(50_000_000, $rate))->toBe(50_000_000);
})->with(['no tax line' => null, 'zero-rated' => 0]);
