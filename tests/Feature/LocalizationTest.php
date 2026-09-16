<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Vá lỗ hổng tiếng Anh còn sót: lang/vi/validation.php không tồn tại trước task này (mọi lỗi
 * xác thực của framework rơi về tiếng Anh), và bản dịch sẵn có của Filament cho locale `vi` thiếu
 * một số khoá so với bản gốc `en` của từng gói (SPEC yêu cầu toàn bộ panel là tiếng Việt).
 *
 * Cố ý viết CẤU TRÚC (đối chiếu khoá, không đối chiếu chữ) — mục tiêu là tự động bắt được lỗ hổng
 * KẾ TIẾP (một khoá mới trong bản `en` của framework/Filament mà bản `vi` chưa kịp dịch), không
 * phải ghim cứng câu chữ hôm nay. Một test không thể đỏ thì tệ hơn không có test.
 */
function flattenTranslationKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $keys += flattenTranslationKeys($value, $path);
        } else {
            $keys[$path] = $value;
        }
    }

    return $keys;
}

/**
 * Mọi tệp vi override dưới lang/vendor/ đã publish trong task này, cùng với tệp `en` gốc của
 * đúng gói Filament tương ứng để so khoá — xem bin/dev artisan vendor:publish --tag=... Tên thư
 * mục publish (vd. `filament-infolists`) khác tên gói composer con (`infolists`) nên cần bảng ánh
 * xạ thay vì suy luận tên.
 */
function filamentVendorTranslationFiles(): array
{
    $packageSourceDirectories = [
        'filament-forms' => 'forms',
        'filament-infolists' => 'infolists',
        'filament-notifications' => 'notifications',
        'filament-panels' => 'filament',
        'filament-schemas' => 'schemas',
        'filament-tables' => 'tables',
        'filament-widgets' => 'widgets',
        'filament' => 'support',
    ];

    $pairs = [];

    foreach ($packageSourceDirectories as $publishedDirectory => $composerPackage) {
        $viBase = base_path("lang/vendor/{$publishedDirectory}/vi");

        if (! is_dir($viBase)) {
            continue;
        }

        $enBase = base_path("vendor/filament/{$composerPackage}/resources/lang/en");

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viBase, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viBase) + 1));

            $pairs[] = [
                'vi' => $file->getPathname(),
                'en' => "{$enBase}/{$relative}",
                'label' => "lang/vendor/{$publishedDirectory}/vi/{$relative}",
            ];
        }
    }

    return $pairs;
}

it('translates every key of lang/en/validation.php into lang/vi/validation.php, recursively, with no English value left behind', function () {
    $english = flattenTranslationKeys(require base_path('lang/en/validation.php'));
    $vietnamese = flattenTranslationKeys(require base_path('lang/vi/validation.php'));

    $missingKeys = array_diff(array_keys($english), array_keys($vietnamese));

    expect($missingKeys)->toBe([]);

    $untranslated = [];

    foreach ($english as $key => $englishValue) {
        // 'custom' chỉ là khung ví dụ ("attribute-name.rule-name"), không có nội dung thật để dịch.
        if (str_starts_with($key, 'custom.')) {
            continue;
        }

        if ($vietnamese[$key] === $englishValue) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([]);
});

it('has a Vietnamese override for every key the English source of a published Filament vendor translation file has', function () {
    $pairs = filamentVendorTranslationFiles();

    // Nếu bản publish ở trên không tìm thấy tệp nào, test này im lặng đúng — nhưng đó chính là lỗ
    // hổng đang được vá lại (SPEC yêu cầu Filament không còn khoá tiếng Anh nào), nên phải chắc có
    // ít nhất một cặp tệp để so sánh.
    expect($pairs)->not->toBeEmpty();

    $failures = [];

    foreach ($pairs as $pair) {
        expect($pair['en'])->toBeFile();

        $english = flattenTranslationKeys(require $pair['en']);
        $vietnamese = flattenTranslationKeys(require $pair['vi']);

        $missingKeys = array_diff(array_keys($english), array_keys($vietnamese));

        if ($missingKeys !== []) {
            $failures[$pair['label']] = $missingKeys;
        }
    }

    expect($failures)->toBe([]);
});

/**
 * Đầu-cuối thật: gửi một form Filament thiếu trường bắt buộc và đọc đúng thông điệp lỗi hiện lên
 * — chứng minh phần nối dây (Filament truyền đúng $attributes/$messages cho Validator, locale
 * `vi` được dùng) chứ không chỉ nội dung tệp ngôn ngữ đúng suông mà không ai gọi tới.
 */
it('renders a real Filament form validation failure in Vietnamese, not English', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $this->livewire(CreateClient::class)
        ->fillForm([
            'type' => 'individual',
            // 'name' bị bỏ trống có chủ đích: đây là trường bắt buộc của ClientForm.
            'name' => '',
            'id_number' => '079012345678',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required'])
        // Filament tự xây :attribute từ label của field bằng Str::lcfirst() (xem
        // vendor/filament/forms/src/Components/Concerns/CanBeValidated.php::getValidationAttribute())
        // — đúng cơ chế biến "khách hàng" (viết thường) thành vế sau trong câu lỗi thật, khác với
        // "Khách hàng" hoa chữ đầu dùng làm nhãn ô. Test này khớp đúng hành vi thật, không phải
        // suy đoán.
        ->assertSee(__('validation.required', ['attribute' => Str::lcfirst(__('clients.fields.name'))]))
        ->assertDontSee('The name field is required')
        ->assertDontSee('field is required');
});
