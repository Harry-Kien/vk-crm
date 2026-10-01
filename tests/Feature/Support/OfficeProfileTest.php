<?php

use App\Models\Setting;
use App\Support\OfficeProfile;
use Illuminate\Support\Facades\DB;

/**
 * M7 Task 10 — `App\Support\OfficeProfile`, nơi DUY NHẤT đọc chín thông tin văn phòng sửa được
 * trong app (bốn thông tin pháp lý, tên pháp lý, hotline, Zalo, website, email liên hệ).
 *
 * Thứ tự: bảng `settings` (khoá `office.<trường>`, giá trị không rỗng) → `config('vkcrm.brand.*')`
 * (giá trị `.env` hoặc mặc định). Mỗi `current()` là một lần đọc MỚI: worker hàng đợi sống lâu,
 * và một giá trị giữ qua hai job là thư của job sau mang thông tin cũ.
 */

/** Ghi thẳng một dòng `settings` — dữ liệu đầu vào cho phép đo, không phải đường ghi của app. */
function officeSetting(string $field, ?string $value): void
{
    Setting::query()->updateOrCreate(['key' => OfficeProfile::settingKey($field)], ['value' => $value]);
}

/**
 * Mutation probe: đảo thứ tự trong `OfficeProfile::value()` (cấu hình trước, bảng sau) làm test này
 * đỏ — hotline trả về số của `.env`, không phải số admin vừa lưu.
 */
it('đọc bảng settings trước, cấu hình sau', function () {
    config(['vkcrm.brand.hotline' => '0832270898']);

    expect(OfficeProfile::current()->hotline())->toBe('0832270898');

    officeSetting('hotline', '0909123456');

    expect(OfficeProfile::current()->hotline())->toBe('0909123456')
        ->and(OfficeProfile::current()->value('hotline'))->toBe('0909123456');
});

/**
 * "Trống = dùng cấu hình" (phán quyết controller Task 10). Mutation probe: bỏ `filled()` ở nhánh
 * bảng `settings` làm test đỏ — một dòng rỗng che mất giá trị `.env`.
 */
it('dòng settings rỗng hoặc chỉ khoảng trắng rơi về cấu hình', function (?string $stored) {
    config(['vkcrm.brand.legal_name' => 'Công ty Luật TNHH Vũ Khang Solutions & Partners']);

    officeSetting('legal_name', $stored);

    expect(OfficeProfile::current()->legalName())->toBe('Công ty Luật TNHH Vũ Khang Solutions & Partners');
})->with([
    'null' => [null],
    'chuỗi rỗng' => [''],
    'chỉ khoảng trắng' => ['   '],
]);

/**
 * Blank-safety của Reply-To (M6.5 Task 12) nay nằm ở ĐÂY, không ở `BrandedMailable`: một `.env`
 * gõ nhầm `BRAND_REPLY_TO_ADDRESS=" "` là "chưa cấu hình". Mutation probe: bỏ `filled()` ở nhánh
 * cấu hình làm test đỏ (trả `' '`).
 */
it('cấu hình rỗng hoặc chỉ khoảng trắng là null, không phải chuỗi', function (?string $configured) {
    config(['vkcrm.brand.reply_to' => $configured]);

    expect(OfficeProfile::current()->replyTo())->toBeNull();
})->with([
    'null' => [null],
    'chuỗi rỗng' => [''],
    'chỉ khoảng trắng' => [' '],
]);

it('trả đúng từng trường qua getter của nó', function () {
    $values = [
        'legal_name' => 'Tên pháp lý A',
        'tax_code' => '0312345678-001',
        'bar_association' => 'Đoàn Luật sư TP. Hà Nội',
        'licence_number' => '01021234/TP/ĐKHĐ',
        'office_address' => '12 Tràng Thi, Hoàn Kiếm, Hà Nội',
        'hotline' => '0909123456',
        'zalo' => 'https://zalo.me/0909123456',
        'website' => 'https://vukhang.vn',
        'reply_to' => 'hotro@vukhang.vn',
    ];

    foreach ($values as $field => $value) {
        officeSetting($field, $value);
    }

    $office = OfficeProfile::current();

    expect($office->legalName())->toBe($values['legal_name'])
        ->and($office->taxCode())->toBe($values['tax_code'])
        ->and($office->barAssociation())->toBe($values['bar_association'])
        ->and($office->licenceNumber())->toBe($values['licence_number'])
        ->and($office->officeAddress())->toBe($values['office_address'])
        ->and($office->hotline())->toBe($values['hotline'])
        ->and($office->zalo())->toBe($values['zalo'])
        ->and($office->website())->toBe($values['website'])
        ->and($office->replyTo())->toBe($values['reply_to']);
});

/** `stored()` và `configured()` là hai nửa của `value()` — trang sửa dùng chúng để hiện "đang dùng giá trị nào". */
it('tách được giá trị đã lưu khỏi giá trị cấu hình', function () {
    config(['vkcrm.brand.website' => 'https://luatvukhang.com']);

    $office = OfficeProfile::current();

    expect($office->stored('website'))->toBeNull()
        ->and($office->configured('website'))->toBe('https://luatvukhang.com');

    officeSetting('website', 'https://vukhang.vn');

    $office = OfficeProfile::current();

    expect($office->stored('website'))->toBe('https://vukhang.vn')
        ->and($office->configured('website'))->toBe('https://luatvukhang.com');
});

/**
 * Worker hàng đợi (`queue:work`) sống qua nhiều job. Mỗi `current()` phải đọc lại bảng: một thư
 * xếp hàng TRƯỚC lần lưu nhưng render SAU nó mang giá trị mới. Ghi bằng `DB::table()` (không qua
 * model, không sự kiện nào) để mô phỏng một tiến trình KHÁC vừa lưu.
 *
 * Mutation probe: cho `current()` trả một thể hiện giữ trong thuộc tính `static` làm test này đỏ.
 */
it('mỗi lần current() là một lần đọc mới, không giữ giá trị qua hai lần render', function () {
    officeSetting('hotline', '0909000001');

    expect(OfficeProfile::current()->hotline())->toBe('0909000001');

    DB::table('settings')->where('key', 'office.hotline')->update(['value' => '0909000002']);

    expect(OfficeProfile::current()->hotline())->toBe('0909000002');
});

/**
 * Trong MỘT lần render (một đối tượng), mọi trường đến từ CÙNG một lần đọc: chân thư không thể
 * mang tên pháp lý cũ cạnh hotline mới. Đồng thời là ngân sách truy vấn: chín getter = một truy vấn.
 *
 * Mutation probe: đọc bảng ở mỗi lời gọi (bỏ chỗ nhớ `$stored`) làm test đỏ (9 truy vấn).
 */
it('một đối tượng đọc bảng settings đúng một lần cho cả chín trường', function () {
    $office = OfficeProfile::current();

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach (array_keys(OfficeProfile::FIELDS) as $field) {
        $office->value($field);
    }

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

/** Màu, logo, font, lockup KHÔNG sửa được trong app (`BrandingTest` ghim chúng). */
it('không nhận một trường ngoài chín trường sửa được', function (string $field) {
    expect(fn () => OfficeProfile::current()->value($field))->toThrow(InvalidArgumentException::class)
        ->and(fn () => OfficeProfile::settingKey($field))->toThrow(InvalidArgumentException::class);
})->with(['colors', 'font', 'lockup', 'short_name', 'tagline', 'primary_ramp']);

it('đặt khoá của mọi trường dưới tiền tố office.', function () {
    foreach (array_keys(OfficeProfile::FIELDS) as $field) {
        expect(OfficeProfile::settingKey($field))->toBe('office.'.$field);
    }

    expect(array_keys(OfficeProfile::FIELDS))->toBe([
        'legal_name', 'tax_code', 'bar_association', 'licence_number', 'office_address',
        'hotline', 'zalo', 'website', 'reply_to',
    ]);
});

// ---------------------------------------------------------------------------------------------
// Test cấu trúc: không còn lời gọi `config('vkcrm.brand.<trường sửa được>')` nào ngoài service.
// ---------------------------------------------------------------------------------------------

/**
 * Mọi tệp `.php` (gồm `.blade.php`) dưới một thư mục. `scandir()` chứ không
 * `RecursiveDirectoryIterator`: ổ 9p của Docker Desktop trên Windows làm `rewinddir()` bỏ sót mục
 * trong thư mục lớn (xem `bin/container-test`), và `scandir()` không gọi `rewinddir()`. Test dưới
 * còn tự kiểm danh sách đã quét có đủ những tệp biết chắc phải có.
 *
 * @return list<string> đường dẫn tương đối từ gốc dự án
 */
function officeProfileScanFiles(string $directory): array
{
    $files = [];

    foreach (scandir(base_path($directory)) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $relative = $directory.'/'.$entry;

        if (is_dir(base_path($relative))) {
            array_push($files, ...officeProfileScanFiles($relative));
        } elseif (str_ends_with($entry, '.php')) {
            $files[] = $relative;
        }
    }

    return $files;
}

/**
 * Những đoạn đọc cấu hình thương hiệu bị cấm trong một nội dung tệp:
 *  - `vkcrm.brand.<một trong chín trường>` dưới mọi dạng gọi (`config()`, `Config::get()`,
 *    `config()->get()`), kể cả trong chú thích — chú thích chỉ sai đường cho người đọc sau;
 *  - đọc NGUYÊN khối `'vkcrm.brand'` hay nguyên `'vkcrm'`, vì từ đó `$brand['hotline']` lách được
 *    phép quét theo tên trường.
 *
 * @return list<string>
 */
function officeProfileForbiddenReads(string $contents): array
{
    $fields = implode('|', array_map('preg_quote', array_keys(OfficeProfile::FIELDS)));

    preg_match_all(
        '/vkcrm\.brand\.(?:'.$fields.')\b|([\'"])vkcrm\.brand\1|([\'"])vkcrm\2/',
        $contents,
        $matches,
    );

    return $matches[0];
}

it('bắt được mọi dạng đọc cấu hình bị cấm (đối chứng của phép quét)', function () {
    expect(officeProfileForbiddenReads("config('vkcrm.brand.hotline')"))->toBe(['vkcrm.brand.hotline'])
        ->and(officeProfileForbiddenReads('Config::get("vkcrm.brand.reply_to")'))->toBe(['vkcrm.brand.reply_to'])
        ->and(officeProfileForbiddenReads("config()->get('vkcrm.brand.tax_code')"))->toBe(['vkcrm.brand.tax_code'])
        ->and(officeProfileForbiddenReads("\$brand = config('vkcrm.brand');"))->toBe(["'vkcrm.brand'"])
        ->and(officeProfileForbiddenReads("config('vkcrm')['brand']['zalo']"))->toBe(["'vkcrm'"])
        // Trường KHÔNG sửa được trong app thì vẫn đọc cấu hình như cũ.
        ->and(officeProfileForbiddenReads("config('vkcrm.brand.colors')"))->toBe([])
        ->and(officeProfileForbiddenReads("config('vkcrm.brand.lockup.name')"))->toBe([])
        ->and(officeProfileForbiddenReads("config('vkcrm.brand.short_name')"))->toBe([])
        ->and(officeProfileForbiddenReads("config('vkcrm.brand.font')"))->toBe([])
        ->and(officeProfileForbiddenReads("config('vkcrm.brand.tagline')"))->toBe([])
        ->and(officeProfileForbiddenReads("config('vkcrm.upload_max_mb')"))->toBe([]);
});

/**
 * Mutation probe: trả lại một lời gọi `config('vkcrm.brand.hotline')` vào bất kỳ view nào (ví dụ
 * `errors/404.blade.php`) làm test này đỏ và nêu đích danh tệp:dòng.
 *
 * Sau khi gộp `main` (M6 phần còn lại), test này đỏ ở những mẫu thư M6 thêm mà vẫn đọc
 * `config('vkcrm.brand.legal_name')`/`hotline` — sửa từng chỗ sang `OfficeProfile::current()`.
 */
it('không còn chỗ nào ngoài OfficeProfile đọc chín trường từ cấu hình', function () {
    $files = [
        ...officeProfileScanFiles('app'),
        ...officeProfileScanFiles('resources/views'),
        ...officeProfileScanFiles('routes'),
    ];

    // Phép quét có thấy đủ chỗ: mỗi tệp dưới đây từng đọc thẳng cấu hình trước Task 10.
    expect($files)->toContain(
        'app/Support/BrandFooter.php',
        'app/Mail/BrandedMailable.php',
        'app/Actions/Matter/RenderHandoverIndex.php',
        'app/Filament/Portal/Pages/SubmitDocument.php',
        'resources/views/emails/layout.blade.php',
        'resources/views/emails/layout-text.blade.php',
        'resources/views/brand/login-footer.blade.php',
        'resources/views/errors/404.blade.php',
        'resources/views/filament/portal/pages/my-matters.blade.php',
        'app/Support/OfficeProfile.php',
    );

    $offenders = [];

    foreach ($files as $file) {
        if ($file === 'app/Support/OfficeProfile.php') {
            continue;
        }

        foreach (file(base_path($file)) ?: [] as $number => $line) {
            foreach (officeProfileForbiddenReads($line) as $match) {
                $offenders[] = $file.':'.($number + 1).' — '.$match;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * `Setting` không nằm trong ranh giới cổng (miễn trừ ở `PortalCoverageTest`), nên ranh giới là ở
 * mã: cổng chỉ đọc chín trường công khai qua `OfficeProfile`, không bao giờ đọc thẳng bảng — nơi
 * M11 sẽ để công tắc MCP và những khoá nội bộ khác.
 */
it('không màn hình nào của cổng khách đọc thẳng model Setting', function () {
    $files = [
        ...officeProfileScanFiles('app/Filament/Portal'),
        ...officeProfileScanFiles('resources/views/filament/portal'),
    ];

    expect($files)->toContain('app/Filament/Portal/Pages/MyMatters.php');

    $offenders = array_values(array_filter(
        $files,
        fn (string $file): bool => preg_match('/\bApp\\\\Models\\\\Setting\b|\bSetting::/', (string) file_get_contents(base_path($file))) === 1,
    ));

    expect($offenders)->toBe([]);
});
