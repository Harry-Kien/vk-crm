<?php

use App\Support\Audit;
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
        // Hai hình dạng mà docblock CŨ khẳng định là không tồn tại (sửa round 4): `84` + 11 chữ
        // số từ một nhánh có tiền tố (không có trần), và một dãy chỉ tình cờ bắt đầu bằng `84`
        // nhưng quá ngắn cho nhánh mã quốc gia nên được trả về nguyên văn.
        '079012345678', '8412345',
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

/**
 * M8 Task 4 (SPEC §10.5): băm CÓ KHOÁ — HMAC-SHA256 với `APP_KEY` — không bao giờ `sha256` trần.
 * Một CCCD chỉ có 12 chữ số (sáu chữ số đầu còn là mã tỉnh, thế kỷ/giới tính, năm sinh), nên một
 * `sha256` trần trong `matter_parties.id_number_hash` dò ngược được bằng vét cạn: với bên là khách
 * hàng, nó là `clients.id_number` gần như ở dạng rõ nằm ngoài cột đã mã hoá; với bên đối lập, nó là
 * dạng lưu DUY NHẤT của số của họ. `PersonalDataSpec105Test` bắt đúng cột đó trước bản sửa này.
 */
it('hashes id numbers after stripping non digits, with APP_KEY as the key — never a bare sha256', function () {
    $expected = hash_hmac('sha256', '079090001234', (string) config('app.key'));

    expect(Normalizer::idNumberHash('079 090 001 234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe($expected)
        ->and(Normalizer::idNumberHash('079090001234'))->not->toBe(hash('sha256', '079090001234'))
        ->and(strlen((string) Normalizer::idNumberHash('1')))->toBe(64)
        ->and(Normalizer::idNumberHash('---'))->toBeNull()
        ->and(Normalizer::idNumberHash(null))->toBeNull();
});

/** Một định nghĩa, không hai (phán quyết Task 4): cột so trùng và nhật ký băm cùng một cách. */
it('hashes an id number exactly as the audit log hashes an identifier', function () {
    expect(Normalizer::idNumberHash('079-090-001-234'))->toBe(Audit::identifierHash('079090001234'));
});

/**
 * Hệ quả phải biết trước (Ghi chú M8, Task 4): khoá là `APP_KEY`, nên sinh khoá mới làm MỌI hash
 * đã lưu thôi khớp — so trùng CCCD của kiểm tra xung đột lợi ích mù với mọi bên nhập trước đó.
 */
it('changes with APP_KEY, so a new key also stops every stored id number hash from matching', function () {
    $before = Normalizer::idNumberHash('079090001234');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(Normalizer::idNumberHash('079090001234'))->not->toBe($before)
        ->and(Normalizer::idNumberHash('079090001234'))->toBe(Audit::identifierHash('079090001234'));
});

/**
 * Câu bất biến ghi trong docblock `phone()` phải là câu đã ĐO, không phải câu nghe hợp lý — đây là
 * lần thứ ba một bất biến sai được phát hiện trên nhánh này. Test này ghim đúng hai hình dạng mà
 * câu cũ ("mọi giá trị có tiền tố `84` đều là `84` + 8…10 chữ số") loại trừ nhưng hàm vẫn sinh ra.
 *
 * Không phải một test đỏ-trước: hành vi của hàm ĐÚNG và không đổi ở vòng này, chỉ docblock sai. Nó
 * tồn tại để lần sau ai đó tin vào câu chữ mà siết độ dài lại thì phải làm đỏ một test, thay vì
 * lặng lẽ làm hai cách viết của cùng một số thôi khớp nhau.
 */
it('has no upper bound on the two prefixed branches, and leaves a short 84-run alone', function () {
    // Nhánh "cách viết trong nước" và nhánh mã quốc gia chỉ có sàn: 11 chữ số sau `84` là hợp lệ.
    expect(Normalizer::phone('079012345678'))->toBe('8479012345678')
        ->and(Normalizer::phone('+84 79012345678'))->toBe('8479012345678')
        ->and(Normalizer::phone('07901234567890'))->toBe('847901234567890')
        // Quá ngắn cho nhánh mã quốc gia, không bắt đầu bằng `0`, ngoài khoảng của nhánh trần:
        // trả về nguyên văn — một giá trị mang tiền tố `84` mà không nhánh nào gắn vào cả.
        ->and(Normalizer::phone('8412345'))->toBe('8412345')
        ->and(Normalizer::phone('841234'))->toBe('841234');
});
