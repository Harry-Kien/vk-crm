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
        // Gói OOXML THẬT, không phải một ZIP chỉ có `[Content_Types].xml`: bản đầu của fixture
        // này thiếu `word/document.xml`, tức nó cũng chính là một ZIP tuỳ ý đội tên .docx.
        UploadedFile::fake()->createWithContent('a.docx', docxPackageBytes()),
        UploadedFile::fake()->createWithContent('a.xlsx', xlsxPackageBytes()),
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

it('rejects một ĐƯỜNG DẪN chứa byte rỗng mà không rò rỉ lỗi hệ thống (TypeError/ValueError)', function () {
    // Đuôi SAU byte rỗng phải là một đuôi HỢP LỆ. Bản đầu của test này dùng "evil.pdf\0.php":
    // `pathinfo()` trả đuôi `php`, nên nhánh whitelist đuôi đã ném rồi và xoá sạch mọi guard về
    // tên thì test vẫn xanh.
    //
    // Đo lại sau khi đổi: với thứ tự này nhánh THẬT SỰ bắt được nó là `guardReadablePath()`
    // (đường dẫn chứa byte rỗng), không phải `guardName()` — nên đừng đọc test này như một cái
    // pin cho `guardName()`. Hai test ngay dưới mới là pin của `guardName()`.
    $path = sys_get_temp_dir().'/'."evil.php\0.pdf";

    expect(fn () => FileGuard::check($path))->toThrow(FileRejected::class);
});

it('rejects một tệp tải lên có byte rỗng trong TÊN dù đường dẫn tạm sạch', function () {
    // Trường hợp mà CHỈ `guardName()` bắt được: byte rỗng nằm trong tên do client khai, còn
    // đường dẫn tệp tạm do PHP sinh ra thì sạch. Không dùng `UploadedFile::fake()` được vì nó
    // tạo một tệp thật mang đúng cái tên đó; dựng thẳng một `UploadedFile` ở chế độ test.
    $path = tempnam(sys_get_temp_dir(), 'fg');
    file_put_contents($path, validPdfBytes());

    $file = new UploadedFile($path, "evil.php\0.pdf", null, null, true);

    try {
        expect(fn () => FileGuard::check($file))->toThrow(FileRejected::class);
    } finally {
        unlink($path);
    }
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

/**
 * Một gói ZIP tuỳ ý: dùng để dựng các tệp "đội lốt" `.docx`/`.xlsx` trong các test dưới đây.
 *
 * **MIME thật mà `finfo` đọc ra cho một gói như thế này KHÔNG cố định** — nó phụ thuộc thứ tự
 * mục và bản libmagic của máy đang chạy (xem docblock `FileGuard::verifyOfficePackage()`, và
 * `DocumentsRelationManagerTest::adminDocxRecognizedAsZipBytes()` cho một phép đo cụ thể: trên
 * container này, `docxPackageBytes()` bên dưới — `[Content_Types].xml` đứng ĐẦU — cho MIME OOXML
 * cụ thể, không phải `application/zip`). Điều đó KHÔNG quan trọng cho các test dưới đây: chúng
 * không khẳng định giá trị MIME cụ thể nào, chỉ khẳng định `FileGuard::check()` chấp nhận hay từ
 * chối — và `FileGuard::ALLOWED` đã liệt kê CẢ HAI (`application/zip` lẫn MIME OOXML cụ thể) cho
 * `docx`/`xlsx`, nên `check()` cho cùng kết quả dù `finfo` đọc ra cái nào.
 *
 * @param  array<string, string>  $entries  tên mục trong gói => nội dung
 */
function zipBytes(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** Gói Office Open XML tối thiểu nhưng THẬT của Word: có cả mục bắt buộc `word/document.xml`. */
function docxPackageBytes(array $extra = []): string
{
    return zipBytes(array_merge([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
    ], $extra));
}

/** Gói Office Open XML tối thiểu nhưng THẬT của Excel: có cả mục bắt buộc `xl/workbook.xml`. */
function xlsxPackageBytes(array $extra = []): string
{
    return zipBytes(array_merge([
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>',
    ], $extra));
}

it('rejects một ZIP bất kỳ chỉ vì nó được đặt tên .docx', function () {
    // Lỗ hổng Critical của vòng rà soát Task 1: `application/zip` nằm trong danh sách MIME được
    // chấp nhận cho `docx`, nên gói này — chứa một .exe — qua được cổng, và scanner mặc định là
    // `NullScanner` nên không còn lớp nào phía sau bắt nó.
    $zip = UploadedFile::fake()->createWithContent('bang-ke.docx', zipBytes([
        'payload.exe' => fakeDosExecutableBytes(),
    ]));

    expect(fn () => FileGuard::check($zip))->toThrow(FileRejected::class);
});

it('rejects một ZIP chứa trang HTML có script dù được đặt tên .xlsx', function () {
    $zip = UploadedFile::fake()->createWithContent('danh-sach.xlsx', zipBytes([
        'index.html' => '<html><script>alert(document.cookie)</script></html>',
    ]));

    expect(fn () => FileGuard::check($zip))->toThrow(FileRejected::class);
});

it('accepts một gói OOXML thật của Word dưới tên .docx và của Excel dưới tên .xlsx', function () {
    // Gọi trực tiếp, không qua `not->toThrow()` — xem ghi chú ở test "accepts mọi đuôi...".
    FileGuard::check(UploadedFile::fake()->createWithContent('don-khoi-kien.docx', docxPackageBytes()));
    FileGuard::check(UploadedFile::fake()->createWithContent('bang-ke.xlsx', xlsxPackageBytes()));

    expect(true)->toBeTrue();
});

it('rejects một gói Word đặt tên .xlsx và một gói Excel đặt tên .docx', function () {
    // Chiều ngược lại của quyết định về ZIP: gói OOXML hợp lệ vẫn phải ĐÚNG loại mà đuôi khai báo.
    $wordUnderXlsx = UploadedFile::fake()->createWithContent('bang-ke.xlsx', docxPackageBytes());
    $excelUnderDocx = UploadedFile::fake()->createWithContent('don.docx', xlsxPackageBytes());

    expect(fn () => FileGuard::check($wordUnderXlsx))->toThrow(FileRejected::class)
        ->and(fn () => FileGuard::check($excelUnderDocx))->toThrow(FileRejected::class);
});

it('rejects một gói Word mang macro VBA dù được đặt tên .docx', function () {
    // `.docm` đổi tên thành `.docx`: đuôi macro không có trong danh sách trắng SPEC §6.6, và một
    // gói `wordprocessingml.document` thật theo chuẩn OOXML không được phép chứa dự án VBA.
    $macro = UploadedFile::fake()->createWithContent('hop-dong.docx', docxPackageBytes([
        'word/vbaProject.bin' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 128),
    ]));

    expect(fn () => FileGuard::check($macro))->toThrow(FileRejected::class);
});

it('rejects một thân OLE2 (.doc) được đặt tên .jpg — cặp đuôi↔MIME, không phải hai danh sách rời', function () {
    $ole = UploadedFile::fake()->createWithContent('anh-cccd.jpg', validOleBytes());

    expect(fn () => FileGuard::check($ole))->toThrow(FileRejected::class);
});

it('accepts đuôi viết hoa vì Windows và điện thoại thường sinh ra .PDF, .DOCX', function () {
    FileGuard::check(UploadedFile::fake()->createWithContent('BIEN-LAI.PDF', validPdfBytes()));
    FileGuard::check(UploadedFile::fake()->createWithContent('DON.DocX', docxPackageBytes()));

    expect(true)->toBeTrue();
});

it('rejects tên tệp chứa ký tự xuống dòng (mưu đồ chèn header)', function () {
    $injected = UploadedFile::fake()->createWithContent("a\"\r\nX-Injected: 1.pdf", validPdfBytes());

    expect(fn () => FileGuard::check($injected))->toThrow(FileRejected::class);
});

it('safeName cắt đường dẫn, ký tự điều khiển và độ dài trước khi tên chạm tới media.file_name', function () {
    expect(FileGuard::safeName('../../etc/cron.d/x.pdf'))->toBe('x.pdf')
        ->and(FileGuard::safeName('/etc/passwd.pdf'))->toBe('passwd.pdf')
        ->and(FileGuard::safeName('..'))->toBe('tep-tai-len')
        ->and(FileGuard::safeName(''))->toBe('tep-tai-len');

    $long = FileGuard::safeName(str_repeat('a', 600).'.pdf');

    expect(strlen($long))->toBeLessThanOrEqual(255)
        ->and($long)->toEndWith('.pdf');
});

it('safeName không bao giờ hi sinh phần đuôi để gọt mấy dấu chấm đầu tên', function () {
    // `....pdf` từng ra thành `pdf`: một cái tên KHÔNG còn đuôi, đúng hậu quả mà quyết định
    // "tên hiển thị giữ nguyên đuôi" sinh ra để tránh. Tệp tải về không có đuôi thì Windows
    // không biết mở bằng gì, và `check()` thì vẫn nhận nó như một `.pdf` hợp lệ — hai chỗ đọc
    // cùng một cái tên ra hai kết quả khác nhau.
    // So bằng `toBe` chứ không `toEndWith`: một bản trả về `.pdf` (đuôi còn, tên không còn) vẫn
    // "kết thúc bằng .pdf" nhưng lại là một tệp ẩn không tên — đã kiểm bằng mutation.
    $fallback = __('documents.fallback_file_name');

    expect(FileGuard::safeName('....pdf'))->toBe($fallback.'.pdf')
        ->and(FileGuard::safeName('.pdf'))->toBe($fallback.'.pdf')
        ->and(FileGuard::safeName('. . .hop-dong.pdf'))->toBe('hop-dong.pdf')
        ->and(FileGuard::safeName('bang-ke.xlsx   '))->toBe('bang-ke.xlsx');
});

it('thông điệp lỗi không tiết lộ MIME thật cho người tải lên', function () {
    // Với khách đang dùng điện thoại đó là chữ vô nghĩa; với người đang dò danh sách trắng đó là
    // một cái máy trả lời miễn phí. MIME thật đi vào log, không đi vào màn hình.
    $fakePdf = UploadedFile::fake()->createWithContent('ho-so.pdf', fakeDosExecutableBytes());

    try {
        FileGuard::check($fakePdf);
        test()->fail('Lẽ ra phải ném FileRejected.');
    } catch (FileRejected $e) {
        expect($e->getMessage())->not->toContain('application/x-dosexec');
    }
});

it('thông điệp lỗi không dài ra theo một cái đuôi 3000 ký tự do client bịa', function () {
    $absurd = UploadedFile::fake()->createWithContent('x.'.str_repeat('a', 3000), validPdfBytes());

    try {
        FileGuard::check($absurd);
        test()->fail('Lẽ ra phải ném FileRejected.');
    } catch (FileRejected $e) {
        expect(mb_strlen($e->getMessage()))->toBeLessThan(400);
    }
});

it('không bao giờ nói "vượt quá 0 MB" khi cấu hình giới hạn bị thiếu', function () {
    config(['vkcrm.upload_max_mb' => null]);

    $file = UploadedFile::fake()->createWithContent('ho-so.pdf', validPdfBytes());

    // Giới hạn thiếu phải rơi về mặc định hợp lý, không biến thành "mọi tệp đều quá lớn".
    FileGuard::check($file);

    expect(true)->toBeTrue();
});

it('safeName cắt tên dài mà không làm hỏng ký tự tiếng Việt có dấu', function () {
    // Tên tệp có dấu là 2 byte mỗi ký tự, nên một lần cắt theo byte rất dễ rơi vào giữa một ký
    // tự. `mb_convert_encoding($x, 'UTF-8', 'UTF-8')` KHÔNG cứu được: nó THAY byte thừa bằng `?`
    // chứ không bỏ đi — đo trực tiếp trong container, độ dài giữ nguyên 195 byte và byte cuối là
    // 0x3F. Phát hiện khi đọc lại từng câu docblock đối chiếu với mã.
    //
    // Chữ 'a' ở đầu KHÔNG thừa: nó đẩy điểm cắt sang một vị trí byte LẺ. Không có nó, 200 - 4
    // (`.pdf`) = 196 rơi đúng vào ranh giới một ký tự 2 byte và test xanh cả với bản sai.
    $safe = FileGuard::safeName('a'.str_repeat('ă', 300).'.pdf');

    expect(strlen($safe))->toBeLessThanOrEqual(255)
        ->and(mb_check_encoding($safe, 'UTF-8'))->toBeTrue()
        ->and($safe)->not->toContain('?')
        ->and($safe)->toEndWith('.pdf');
});

it('safeName không nuốt mất một tên tệp không phải UTF-8 hợp lệ', function () {
    // Điện thoại và máy quét cũ vẫn sinh ra tên mã Latin-1. Với cờ `/u`, `preg_replace` trả
    // `null` trên chuỗi như vậy và cả cái tên biến thành tên dự phòng — mất thông tin mà không
    // ai báo gì.
    $latin1 = "h\xF3-s\xF1.pdf";

    expect(FileGuard::safeName($latin1))->not->toBe(__('documents.fallback_file_name'))
        ->and(FileGuard::safeName($latin1))->toEndWith('.pdf');
});
