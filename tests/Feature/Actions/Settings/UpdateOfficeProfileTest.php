<?php

use App\Actions\Settings\UpdateOfficeProfile;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\OfficeProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 10 — `UpdateOfficeProfile`: lưu chín thông tin văn phòng vào bảng `settings` (khoá
 * `office.<trường>`), kiểm tra đầu vào LẦN NỮA ở tầng Action (form đã kiểm, Action không tin
 * form), chuẩn hoá mã số thuế và hotline, ghi một dòng audit nêu TÊN trường đã đổi.
 *
 * Màn hình được test qua Livewire ở `tests/Feature/Filament/OfficeProfilePageTest.php`; tệp này đo
 * luật của chính Action, nơi mọi đường gọi khác (M8 preflight, M11) sẽ đi qua.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function updateOfficeProfile(User $actor, array $input): array
{
    return app(UpdateOfficeProfile::class)->handle($actor, $input);
}

function storedOfficeValue(string $field): ?string
{
    return Setting::query()->where('key', OfficeProfile::settingKey($field))->value('value');
}

/** @return array<string, string> chín trường hợp lệ, đúng như admin sẽ gõ */
function validOfficeInput(): array
{
    return [
        'legal_name' => 'Công ty Luật TNHH Vũ Khang Solutions & Partners',
        'tax_code' => '0312345678',
        'bar_association' => 'Đoàn Luật sư TP. Hồ Chí Minh',
        'licence_number' => '41.02.1234/TP/ĐKHĐ',
        'office_address' => '123 Đường Lê Lợi, Quận 1, TP.HCM',
        'hotline' => '0832 270 898',
        'zalo' => 'https://zalo.me/0832270898',
        'website' => 'https://luatvukhang.com',
        'reply_to' => 'lienhe@luatvukhang.com',
    ];
}

function officeValidationErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('lưu chín trường dưới khoá office.<trường>, ghi người lưu, và trả tên các trường đã đổi', function () {
    $changed = updateOfficeProfile($this->admin, validOfficeInput());

    expect($changed)->toBe(array_keys(OfficeProfile::FIELDS))
        ->and(storedOfficeValue('legal_name'))->toBe('Công ty Luật TNHH Vũ Khang Solutions & Partners')
        ->and(storedOfficeValue('tax_code'))->toBe('0312345678')
        ->and(storedOfficeValue('bar_association'))->toBe('Đoàn Luật sư TP. Hồ Chí Minh')
        ->and(storedOfficeValue('licence_number'))->toBe('41.02.1234/TP/ĐKHĐ')
        ->and(storedOfficeValue('office_address'))->toBe('123 Đường Lê Lợi, Quận 1, TP.HCM')
        ->and(storedOfficeValue('hotline'))->toBe('0832270898')
        ->and(storedOfficeValue('zalo'))->toBe('https://zalo.me/0832270898')
        ->and(storedOfficeValue('website'))->toBe('https://luatvukhang.com')
        ->and(storedOfficeValue('reply_to'))->toBe('lienhe@luatvukhang.com')
        ->and(Setting::query()->where('key', 'office.tax_code')->value('updated_by'))->toBe($this->admin->id);
});

/**
 * Chỉ admin có `settings.manage` (SPEC §5). Mutation probe: bỏ câu hỏi Gate đầu `handle()` làm
 * test đỏ (trưởng phòng lưu được).
 */
it('từ chối mọi người không có settings.manage, và không ghi gì', function (Role $role) {
    $actor = User::factory()->withRole($role)->create();

    expect(fn () => updateOfficeProfile($actor, validOfficeInput()))->toThrow(AuthorizationException::class)
        ->and(Setting::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'office_profile_updated')->count())->toBe(0);
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

/**
 * Audit nêu TÊN đúng những trường đổi, không nêu giá trị (phán quyết controller Task 10).
 * Mutation probe: so sánh cũ/mới trong `WriteSettings` luôn coi là "đã đổi" làm test đỏ (lần lưu
 * thứ hai liệt kê cả chín trường).
 */
it('ghi audit office_profile_updated nêu đúng tên các trường đã đổi, không nêu giá trị', function () {
    updateOfficeProfile($this->admin, validOfficeInput());

    Activity::query()->delete();

    $changed = updateOfficeProfile($this->admin, [
        ...validOfficeInput(),
        'tax_code' => '0312345678-001',
        'hotline' => '0909123456',
    ]);

    $activity = Activity::query()->where('event', 'office_profile_updated')->sole();

    expect($changed)->toBe(['tax_code', 'hotline'])
        ->and($activity->properties->get('changed_fields'))->toBe(['tax_code', 'hotline'])
        ->and($activity->causer?->is($this->admin))->toBeTrue()
        ->and($activity->subject_type)->toBeNull()
        ->and(json_encode($activity->properties))->not->toContain('0909123456')
        ->and(json_encode($activity->properties))->not->toContain('0312345678');
});

/**
 * Mutation probe: bỏ điều kiện "chỉ ghi khi có trường đổi" làm test đỏ (một dòng audit rỗng mỗi
 * lần admin bấm Lưu mà không sửa gì).
 */
it('không ghi audit nào khi không trường nào đổi', function () {
    updateOfficeProfile($this->admin, validOfficeInput());

    Activity::query()->delete();

    expect(updateOfficeProfile($this->admin, validOfficeInput()))->toBe([])
        ->and(Activity::query()->where('event', 'office_profile_updated')->count())->toBe(0);
});

/** Trống = "không đặt", rơi về `.env`. Xoá một giá trị đã lưu cũng là một lần đổi. */
it('để trống thì xoá giá trị đã lưu, rơi về cấu hình, và tính là một trường đã đổi', function () {
    config(['vkcrm.brand.office_address' => null]);

    updateOfficeProfile($this->admin, validOfficeInput());

    $changed = updateOfficeProfile($this->admin, [...validOfficeInput(), 'office_address' => '   ']);

    expect($changed)->toBe(['office_address'])
        ->and(storedOfficeValue('office_address'))->toBeNull()
        ->and(OfficeProfile::current()->officeAddress())->toBeNull();
});

/**
 * Trường không có trong `$input` thì GIỮ NGUYÊN (Action dùng lại được cho một lần sửa từng phần).
 * Mutation probe: coi trường vắng mặt là `null` làm test đỏ (zalo bị xoá).
 */
it('không đụng tới trường vắng mặt trong đầu vào', function () {
    updateOfficeProfile($this->admin, validOfficeInput());

    $changed = updateOfficeProfile($this->admin, ['hotline' => '0909123456']);

    expect($changed)->toBe(['hotline'])
        ->and(storedOfficeValue('zalo'))->toBe('https://zalo.me/0832270898')
        ->and(storedOfficeValue('legal_name'))->toBe('Công ty Luật TNHH Vũ Khang Solutions & Partners');
});

/**
 * Màu, logo, font không sửa được trong app. Mutation probe: bỏ lần lọc theo
 * `OfficeProfile::FIELDS` làm test đỏ (một dòng `office.colors` xuất hiện).
 */
it('bỏ qua mọi khoá ngoài chín trường', function () {
    updateOfficeProfile($this->admin, ['colors' => '#000000', 'office.hotline' => '0909123456', 'font' => 'Comic Sans']);

    expect(Setting::query()->count())->toBe(0);
});

it('cắt khoảng trắng hai đầu trước khi lưu', function () {
    updateOfficeProfile($this->admin, ['bar_association' => "  Đoàn Luật sư TP. Hà Nội \n"]);

    expect(storedOfficeValue('bar_association'))->toBe('Đoàn Luật sư TP. Hà Nội');
});

// ---------------------------------------------------------------------------------------------
// Mã số thuế: 10 chữ số, hoặc 13 chữ số dạng 0123456789-001.
// ---------------------------------------------------------------------------------------------

it('nhận mã số thuế 10 hoặc 13 chữ số và lưu 13 chữ số theo dạng có gạch', function (string $input, string $stored) {
    updateOfficeProfile($this->admin, ['tax_code' => $input]);

    expect(storedOfficeValue('tax_code'))->toBe($stored);
})->with([
    '10 chữ số' => ['0312345678', '0312345678'],
    '13 chữ số có gạch' => ['0312345678-001', '0312345678-001'],
    '13 chữ số liền' => ['0312345678001', '0312345678-001'],
    'có khoảng trắng' => [' 0312 345 678 - 001 ', '0312345678-001'],
]);

/** Mutation probe: nới regex (bỏ neo `$`, hay đổi `{10}` thành `{9,}`) làm ít nhất một ca đỏ. */
it('từ chối mã số thuế sai dạng, và không ghi gì', function (string $input) {
    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, ['tax_code' => $input]));

    expect($errors)->toHaveKey('tax_code')
        ->and(Setting::query()->count())->toBe(0);
})->with([
    '9 chữ số' => ['031234567'],
    '11 chữ số' => ['03123456789'],
    'đuôi 2 chữ số' => ['0312345678-01'],
    'đuôi 4 chữ số' => ['0312345678-0011'],
    'có chữ' => ['03123456AB'],
    'gạch sai chỗ' => ['031234-5678001'],
]);

// ---------------------------------------------------------------------------------------------
// Hotline: chuẩn hoá qua Normalizer::phone(), lưu theo cách viết trong nước.
// ---------------------------------------------------------------------------------------------

it('chuẩn hoá hotline qua Normalizer::phone() rồi lưu theo cách viết trong nước', function (string $input, string $stored) {
    updateOfficeProfile($this->admin, ['hotline' => $input]);

    expect(storedOfficeValue('hotline'))->toBe($stored);
})->with([
    'có khoảng trắng' => ['0832 270 898', '0832270898'],
    '+84' => ['+84 832 270 898', '0832270898'],
    '84 liền' => ['84832270898', '0832270898'],
    'mất số 0 đầu' => ['832270898', '0832270898'],
    'chấm' => ['0832.270.898', '0832270898'],
    'cố định Hà Nội' => ['024 3822 1234', '02438221234'],
]);

/**
 * Mutation probe: bỏ lần kiểm hình dạng sau `Normalizer::phone()` làm test đỏ — `12345` được lưu
 * thành một "hotline" in lên chân mọi thư.
 */
it('từ chối hotline không phải một số điện thoại Việt Nam', function (string $input) {
    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, ['hotline' => $input]));

    expect($errors)->toHaveKey('hotline')
        ->and(Setting::query()->count())->toBe(0);
})->with([
    'quá ngắn' => ['12345'],
    'không có chữ số' => ['gọi văn phòng'],
    'quá dài' => ['0790123456789'],
]);

// ---------------------------------------------------------------------------------------------
// Zalo, website: URL http(s). Email liên hệ: địa chỉ thư hợp lệ.
// ---------------------------------------------------------------------------------------------

/** Mutation probe: bỏ luật `url:http,https` làm test đỏ (một `javascript:` đi thẳng vào thẻ `<a href>`). */
it('từ chối zalo và website không phải URL http(s)', function (string $field, string $input) {
    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, [$field => $input]));

    expect($errors)->toHaveKey($field)
        ->and(Setting::query()->count())->toBe(0);
})->with([
    'zalo thiếu giao thức' => ['zalo', 'zalo.me/0832270898'],
    'zalo javascript:' => ['zalo', 'javascript:alert(1)'],
    'website ftp' => ['website', 'ftp://luatvukhang.com'],
    'website chữ thường' => ['website', 'luật vũ khang'],
]);

/** Mutation probe: bỏ luật `email` làm test đỏ (một Reply-To hỏng làm hỏng MỌI thư — `RfcComplianceException`). */
it('từ chối email liên hệ không hợp lệ', function () {
    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, ['reply_to' => 'lienhe-at-luatvukhang']));

    expect($errors)->toHaveKey('reply_to')
        ->and(Setting::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Độ dài: giới hạn ký tự của từng trường (OfficeProfile::FIELDS), kiểm ở Action lẫn form.
// ---------------------------------------------------------------------------------------------

/** Mutation probe: bỏ luật `max:` làm test đỏ. */
it('từ chối giá trị dài quá giới hạn của trường, nhận đúng bằng giới hạn', function (string $field) {
    $limit = OfficeProfile::FIELDS[$field];

    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, [$field => str_repeat('ạ', $limit + 1)]));

    expect($errors)->toHaveKey($field)
        ->and(Setting::query()->count())->toBe(0);

    updateOfficeProfile($this->admin, [$field => str_repeat('ạ', $limit)]);

    expect(mb_strlen((string) storedOfficeValue($field)))->toBe($limit);
})->with(['legal_name', 'bar_association', 'licence_number', 'office_address']);

it('từ chối cả lô khi một trường sai, không lưu nửa chừng', function () {
    $errors = officeValidationErrors(fn () => updateOfficeProfile($this->admin, [
        ...validOfficeInput(),
        'reply_to' => 'khong-phai-email',
    ]));

    expect($errors)->toHaveKey('reply_to')
        ->and(Setting::query()->count())->toBe(0);
});
