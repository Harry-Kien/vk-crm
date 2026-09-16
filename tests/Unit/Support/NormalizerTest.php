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

it('normalizes a zero-stripped landline to the same value as every other spelling of it', function () {
    // Số cố định Việt Nam dài 10 chữ số SAU số 0 gọi nội hạt (2 số mã vùng + 8 thuê bao ở Hà Nội /
    // TP.HCM, 3 + 7 ở các tỉnh còn lại) — dài hơn di động một chữ số. Đây chính là định danh mà
    // một bên đối lập là DOANH NGHIỆP nhiều khả năng có nhất, và cũng là ô hay bị Excel ăn mất số
    // 0 nhất. Mọi cách viết dưới đây phải ra cùng một giá trị.
    expect(Normalizer::phone('2838221234'))->toBe(Normalizer::phone('028 3822 1234'))
        ->and(Normalizer::phone('2838221234'))->toBe(Normalizer::phone('+84 28 3822 1234'))
        ->and(Normalizer::phone('2838221234'))->toBe(Normalizer::phone('+84 (0)28 3822 1234'))
        ->and(Normalizer::phone('2838221234'))->toBe('842838221234')
        // Hà Nội, mã vùng 2 số.
        ->and(Normalizer::phone('2438251234'))->toBe(Normalizer::phone('024 3825 1234'))
        // Cần Thơ, mã vùng 3 số + 7 số thuê bao — vẫn đúng 10 chữ số sau số 0.
        ->and(Normalizer::phone('2923812345'))->toBe(Normalizer::phone('0292 381 2345'))
        // Di động 11 số của kế hoạch cũ (trước chuyển đổi 2018) cũng còn nằm trong dữ liệu tiếp nhận.
        ->and(Normalizer::phone('1661234567'))->toBe(Normalizer::phone('0166 123 4567'));
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

/**
 * Luỹ đẳng là một tính chất được KHẲNG ĐỊNH trong docblock của `phone()`, nên nó phải được chứng
 * minh bằng những đầu vào có thể làm nó SAI, không phải bằng sáu số đẹp. Ba fixture cuối là những
 * đầu vào từng phá vỡ nó: nhánh "cách viết trong nước" không có sàn độ dài nên `'01234567'` cho ra
 * `'841234567'` — một giá trị không phải dạng đã chuẩn hoá của bất kỳ số nào (`84` + 7 chữ số
 * không tồn tại trong kế hoạch đánh số) — và lần chuẩn hoá thứ hai đọc nó như một số thuê bao
 * trần rồi thêm `84` lần nữa: `'84841234567'`.
 */
it('is idempotent: normalizing an already normalized phone changes nothing', function () {
    $fixtures = [
        '0912345678', '912345678', '+84 901 234 567', '0084 901 234 567', '038251234', '0843123456',
        '028 3822 1234', '2838221234', '+65 9123 4567',
        // Những đầu vào quá ngắn để là số thuê bao Việt Nam — nơi tính chất này từng sai.
        '01234567', '0123456', '0', '00 00',
    ];

    foreach ($fixtures as $raw) {
        $once = Normalizer::phone($raw);

        expect(Normalizer::phone($once))->toBe($once, "không luỹ đẳng với đầu vào {$raw}");
    }
});

/**
 * Nguyên nhân gốc của lỗi luỹ đẳng ở trên: một dãy số bắt đầu bằng `0` nhưng quá ngắn để là số
 * thuê bao Việt Nam KHÔNG phải một số điện thoại trong nước, nên thêm `84` vào đó chỉ tạo ra một
 * giá trị giả dạng đã chuẩn hoá. Giữ nguyên là câu trả lời trung thực: nó không khớp với gì cả,
 * và nó không giả vờ khớp.
 */
it('leaves a national-format run too short to be a subscriber number untouched', function () {
    expect(Normalizer::phone('01234567'))->toBe('01234567')
        ->and(Normalizer::phone('0123456'))->toBe('0123456')
        // Một ô chỉ toàn số 0 không mang thông tin nào: null (= thiếu định danh), không phải
        // chuỗi rỗng và cũng không phải chính dãy số 0 đó. Một `phone_normalized = ''` sẽ bị
        // truy vấn của RunConflictCheck bỏ qua (`when('')` là falsy) trong khi vẫn được đếm là
        // "đã có định danh" — vô hình với cả hai phía.
        ->and(Normalizer::phone('0'))->toBeNull()
        ->and(Normalizer::phone('00'))->toBeNull()
        ->and(Normalizer::phone('0 0 0 0'))->toBeNull();
});

it('hashes id numbers after stripping non digits', function () {
    $expected = hash('sha256', '079090001234');

    expect(Normalizer::idNumberHash('079 090 001 234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe($expected)
        ->and(strlen((string) Normalizer::idNumberHash('1')))->toBe(64)
        ->and(Normalizer::idNumberHash('---'))->toBeNull()
        ->and(Normalizer::idNumberHash(null))->toBeNull();
});
