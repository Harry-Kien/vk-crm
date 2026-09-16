<?php

use App\Support\Normalizer;

it('normalizes vietnamese names to lowercase ascii with single spaces', function () {
    expect(Normalizer::name('  Nguyễn   Văn  An '))->toBe('nguyen van an')
        ->and(Normalizer::name('Trần Thị Bích Đào'))->toBe('tran thi bich dao')
        ->and(Normalizer::name(''))->toBeNull()
        ->and(Normalizer::name(null))->toBeNull()
        ->and(Normalizer::name("\u{00A0}Lê Văn Cường\u{00A0}"))->toBe('le van cuong');
});

it('normalizes phones to 84 prefix digits only', function () {
    expect(Normalizer::phone('0901 234 567'))->toBe('84901234567')
        ->and(Normalizer::phone('+84 901-234-567'))->toBe('84901234567')
        ->and(Normalizer::phone('84901234567'))->toBe('84901234567')
        ->and(Normalizer::phone('abc'))->toBeNull()
        ->and(Normalizer::phone(null))->toBeNull()
        ->and(Normalizer::phone('+84 0901234567'))->toBe('84901234567')
        ->and(Normalizer::phone('+84 (0) 901 234 567'))->toBe('84901234567')
        ->and(Normalizer::phone('0084 901 234 567'))->toBe('84901234567');
});

it('normalizes a subscriber number whose leading zero was stripped to the same value as the 0 spelling', function () {
    // Bản xuất từ Excel hay ăn mất số 0 đầu; hai cách viết dưới đây là CÙNG một người, nên
    // phải ra cùng một giá trị, nếu không tầng khớp điện thoại (SPEC §6.10 bước 2) mù hẳn.
    expect(Normalizer::phone('912345678'))->toBe(Normalizer::phone('0912345678'))
        ->and(Normalizer::phone('912345678'))->toBe('84912345678')
        ->and(Normalizer::phone('901 234 567'))->toBe('84901234567')
        // Số cố định 8 chữ số sau số 0 (kế hoạch đánh số cũ) cũng phải khớp hai cách viết.
        ->and(Normalizer::phone('38251234'))->toBe(Normalizer::phone('038251234'))
        ->and(Normalizer::phone('38251234'))->toBe('8438251234');
});

it('treats a zero-stripped number that begins with 84 as a subscriber number, not as a country code', function () {
    // 084 là đầu số VinaPhone thật: '0843123456' mất số 0 thành '843123456'. Đọc '84' ở đây như
    // mã quốc gia sẽ để lại phần thuê bao 7 chữ số — không tồn tại trong kế hoạch đánh số.
    expect(Normalizer::phone('843123456'))->toBe(Normalizer::phone('0843123456'))
        ->and(Normalizer::phone('843123456'))->toBe('84843123456');
});

it('leaves numbers it cannot recognise as a vietnamese number untouched', function () {
    expect(Normalizer::phone('+1 212 555 0123'))->toBe('12125550123')
        ->and(Normalizer::phone('123'))->toBe('123');
});

it('is idempotent: normalizing an already normalized phone changes nothing', function () {
    foreach (['0912345678', '912345678', '+84 901 234 567', '0084 901 234 567', '038251234', '0843123456'] as $raw) {
        $once = Normalizer::phone($raw);

        expect(Normalizer::phone($once))->toBe($once);
    }
});

it('hashes id numbers after stripping non digits', function () {
    $expected = hash('sha256', '079090001234');

    expect(Normalizer::idNumberHash('079 090 001 234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe($expected)
        ->and(strlen((string) Normalizer::idNumberHash('1')))->toBe(64)
        ->and(Normalizer::idNumberHash('---'))->toBeNull()
        ->and(Normalizer::idNumberHash(null))->toBeNull();
});
