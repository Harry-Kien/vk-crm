<?php

use App\Support\Normalizer;

it('normalizes vietnamese names to lowercase ascii with single spaces', function () {
    expect(Normalizer::name('  Nguyễn   Văn  An '))->toBe('nguyen van an')
        ->and(Normalizer::name('Trần Thị Bích Đào'))->toBe('tran thi bich dao')
        ->and(Normalizer::name(''))->toBeNull()
        ->and(Normalizer::name(null))->toBeNull();
});

it('normalizes phones to 84 prefix digits only', function () {
    expect(Normalizer::phone('0901 234 567'))->toBe('84901234567')
        ->and(Normalizer::phone('+84 901-234-567'))->toBe('84901234567')
        ->and(Normalizer::phone('84901234567'))->toBe('84901234567')
        ->and(Normalizer::phone('abc'))->toBeNull()
        ->and(Normalizer::phone(null))->toBeNull();
});

it('hashes id numbers after stripping non digits', function () {
    $expected = hash('sha256', '079090001234');

    expect(Normalizer::idNumberHash('079 090 001 234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe($expected)
        ->and(strlen((string) Normalizer::idNumberHash('1')))->toBe(64)
        ->and(Normalizer::idNumberHash('---'))->toBeNull()
        ->and(Normalizer::idNumberHash(null))->toBeNull();
});
