<?php

use App\Exceptions\FileRejected;
use App\Support\Files\FileGuard;
use Illuminate\Http\UploadedFile;

/**
 * Bytes tối thiểu để `finfo` (đọc byte thật, không phải phần mở rộng) nhận ra từng định dạng.
 * Xem báo cáo Task 1: các giá trị MIME thật dưới đây được đo trực tiếp trong container
 * `webdevops/php:8.3-alpine` của dự án bằng `finfo`, không phải suy đoán.
 */
function validPdfBytes(): string
{
    return "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
}

function validPngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
}

function validJpegBytes(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB";
}

/** Compound File Binary (OLE2) — định dạng nền của .doc/.xls cũ. */
function validOleBytes(): string
{
    return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 504);
}

/** Office Open XML (.docx/.xlsx) thực chất là một gói ZIP. */
function validOoxmlBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ooxml');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * MZ + PE tối thiểu — đủ để `finfo` nhận ra `application/x-dosexec`, không cần là một executable
 * chạy được thật. Một `"MZ"` trần không đủ: libmagic đòi con trỏ hợp lệ ở offset 0x3C trỏ tới một
 * chữ ký `PE\0\0`, xem báo cáo Task 1.
 */
function fakeDosExecutableBytes(): string
{
    $header = str_repeat("\x00", 64);
    $header[0] = 'M';
    $header[1] = 'Z';
    $peOffset = 0x40;
    $pointer = pack('V', $peOffset);
    $header[0x3C] = $pointer[0];
    $header[0x3D] = $pointer[1];
    $header[0x3E] = $pointer[2];
    $header[0x3F] = $pointer[3];

    $peHeader = "PE\x00\x00".pack('v', 0x014C).pack('v', 0).str_repeat("\x00", 16);

    return $header.$peHeader.str_repeat("\x00", 200);
}

it('rejects .svg tệp vì SVG có thể mang JavaScript', function () {
    // Nội dung THẬT SỰ mang script — không phải chỉ là một cái tên .svg suông — để test này
    // đúng nghĩa "SVG chứa được JS", như docblock FileGuard giải thích.
    $svg = UploadedFile::fake()->createWithContent(
        'anh-cccd.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
    );

    expect(fn () => FileGuard::check($svg))->toThrow(FileRejected::class);
});

it('rejects tệp vượt quá giới hạn kích thước cấu hình', function () {
    config(['vkcrm.upload_max_mb' => 1]);

    $big = UploadedFile::fake()->create('hop-dong.pdf', 1025); // 1025 KB > 1 MB

    expect(fn () => FileGuard::check($big))->toThrow(FileRejected::class);
});

it('rejects tệp đuôi .pdf nhưng byte thật là một file thực thi DOS/Windows', function () {
    $fakePdf = UploadedFile::fake()->createWithContent('ho-so.pdf', fakeDosExecutableBytes());

    expect(fn () => FileGuard::check($fakePdf))->toThrow(FileRejected::class);
});

it('thông điệp lỗi nói rõ việc cần làm, không phải một câu chung chung kiểu "Upload failed"', function () {
    config(['vkcrm.upload_max_mb' => 1]);
    $big = UploadedFile::fake()->create('hop-dong.pdf', 1025);

    try {
        FileGuard::check($big);
        $this->fail('Lẽ ra phải ném FileRejected.');
    } catch (FileRejected $e) {
        expect($e->getMessage())
            ->not->toBe('Upload failed')
            ->not->toBeEmpty()
            ->toContain('MB') // nêu rõ giới hạn
            ->toContain('chụp lại'); // nêu rõ việc cần làm tiếp theo (SPEC §8.4)
    }

    $fakePdf = UploadedFile::fake()->createWithContent('ho-so.pdf', fakeDosExecutableBytes());

    try {
        FileGuard::check($fakePdf);
        $this->fail('Lẽ ra phải ném FileRejected.');
    } catch (FileRejected $e) {
        expect($e->getMessage())
            ->toContain('pdf') // nêu tên đuôi đã khai báo
            ->toContain('mở lại tệp gốc'); // nêu việc cần làm
    }
});

it('accepts mọi đuôi trong danh sách trắng khi byte thật khớp đuôi khai báo', function () {
    // Cố ý KHÔNG dùng `expect(fn () => ...)->not->toThrow(Throwable::class)` ở đây: `Throwable`
    // là một interface, `class_exists('Throwable')` trả `false`, và nhánh xử lý "tên lớp không
    // tồn tại" bên trong `toThrow()` của Pest biến `->not->toThrow(Throwable::class)` thành một
    // assertion LUÔN xanh bất kể `FileGuard` có thật sự ném lỗi hay không — kiểm chứng khi viết
    // test này: nó vẫn xanh cả lúc `FileGuard` chưa hề tồn tại. Gọi trực tiếp, không qua
    // `expect()`, để một `FileRejected` bị ném sai sẽ làm test thất bại thật sự.
    $files = [
        UploadedFile::fake()->createWithContent('a.pdf', validPdfBytes()),
        UploadedFile::fake()->createWithContent('a.png', validPngBytes()),
        UploadedFile::fake()->createWithContent('a.jpg', validJpegBytes()),
        UploadedFile::fake()->createWithContent('a.jpeg', validJpegBytes()),
        UploadedFile::fake()->createWithContent('a.doc', validOleBytes()),
        UploadedFile::fake()->createWithContent('a.xls', validOleBytes()),
        UploadedFile::fake()->createWithContent('a.docx', validOoxmlBytes()),
        UploadedFile::fake()->createWithContent('a.xlsx', validOoxmlBytes()),
    ];

    foreach ($files as $file) {
        FileGuard::check($file);
    }

    expect(true)->toBeTrue();
});

it('rejects tệp rỗng (0 byte) với thông điệp riêng, không lẫn với lỗi MIME', function () {
    $empty = UploadedFile::fake()->createWithContent('trong.pdf', '');

    try {
        FileGuard::check($empty);
        test()->fail('Lẽ ra phải ném FileRejected.');
    } catch (FileRejected $e) {
        expect($e->getMessage())->toContain('0 KB');
    }
});

it('rejects tên tệp không có phần mở rộng', function () {
    $noExtension = UploadedFile::fake()->createWithContent('bien-lai', validPdfBytes());

    expect(fn () => FileGuard::check($noExtension))->toThrow(FileRejected::class);
});

it('rejects tên tệp chứa byte rỗng mà không rò rỉ lỗi hệ thống (TypeError/ValueError)', function () {
    $path = sys_get_temp_dir().'/'."evil.pdf\0.php";

    expect(fn () => FileGuard::check($path))->toThrow(FileRejected::class);
});

it('rejects một đường dẫn (string) không tồn tại trên đĩa', function () {
    $missing = sys_get_temp_dir().'/khong-ton-tai-'.uniqid().'.pdf';

    expect(fn () => FileGuard::check($missing))->toThrow(FileRejected::class);
});

it('accepts một đường dẫn string thật trỏ tới một PDF hợp lệ', function () {
    $path = tempnam(sys_get_temp_dir(), 'fg').'.pdf';
    file_put_contents($path, validPdfBytes());

    // Xem ghi chú ở test "accepts mọi đuôi..." — gọi trực tiếp, không qua `not->toThrow(Throwable::class)`.
    FileGuard::check($path);

    unlink($path);

    expect(true)->toBeTrue();
});
