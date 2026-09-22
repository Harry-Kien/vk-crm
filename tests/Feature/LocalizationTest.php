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
 *
 * # Lỗ hổng mà chính tệp này đã CẤU TRÚC không nhìn thấy, và cách nó được đóng ở M5
 *
 * Bản trước duyệt `lang/vendor/**\/vi/**` — tức chỉ những tệp ĐÃ CÓ AI ĐÓ PUBLISH. Một tệp chưa
 * ai publish thì không có dòng nào để duyệt, nên nó im lặng đúng. Đo được: 19 khoá Filament vẫn
 * ra tiếng Anh trong khi bộ test xanh, trong đó có `filament::components/input/one-time-code`
 * `aria_label` — chính là ô khách hàng gõ mã đăng nhập cổng (SPEC §8.1), một tệp mà gói
 * `filament/support` KHÔNG có bản `vi` nào cả.
 *
 * Bản này đi từ ĐẦU KIA: duyệt mọi tệp `en` của mọi gói Filament, rồi hỏi chính bộ dịch xem
 * locale `vi` trả về gì. Nhờ vậy nó nhìn thấy cả ba hình dạng lỗ hổng:
 *
 *  - gói có bản `vi` nhưng THIẾU khoá;
 *  - gói KHÔNG có bản `vi` cho tệp đó (toàn bộ tệp rơi về `en`);
 *  - và bản `vi` có khoá nhưng giá trị y hệt tiếng Anh.
 *
 * Danh sách gói cũng không còn viết tay: nó lấy từ chính các namespace mà các gói Filament đã
 * đăng ký với bộ dịch, nên một gói Filament mới được cài sẽ tự vào phạm vi kiểm tra. Bản trước
 * có một bảng ánh xạ tay và bảng đó đã thiếu `filament-actions` lẫn `filament-query-builder`.
 *
 * # Thứ tệp này VẪN không nhìn thấy, và người sau nên biết trước khi tin nó
 *
 * Ba hình dạng trên đều là "khoá chưa có bản `vi` dùng được". Có một hình dạng thứ tư mà cấu
 * trúc ở đây **không thể** bắt: một chuỗi bundled ĐÃ CÓ bản `vi`, dịch đúng nghĩa, nhưng **sai
 * xưng hô**. Với test này thì khoá ấy đã xong — nó có mặt, nó là tiếng Việt, nó khác bản `en`.
 *
 * Đo được ở vòng hợp nhất M5: `filament-panels::auth/pages/login.multi_factor.subheading` và
 * `.multi_factor.form.provider.label` gọi khách là "bạn" trên chính màn hình nhập mã đăng nhập,
 * trong khi mọi dòng khác của cổng gọi "anh/chị" — và bộ test này xanh suốt. Hai khoá ấy nay
 * được publish ở `lang/vendor/filament-panels/vi/auth/pages/login.php`.
 *
 * Không mở rộng test này ra để bắt loại lỗi đó, vì "đúng xưng hô" là một phán đoán về văn cảnh
 * chứ không phải một phép so cấu trúc: một danh sách từ cấm sẽ vừa bỏ sót vừa báo nhầm. Thứ bắt
 * được nó là một test RENDER một màn hình thật rồi đọc chữ trên đó; `LoginTest` nay có một
 * ("speaks to the client as anh/chị on the one time code screen"), và mọi màn hình cổng khác nên
 * có một dòng như vậy khi có người đi qua chúng.
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
 * Mọi nhóm bản dịch `en` của mọi gói Filament đang cài, kèm namespace để hỏi bộ dịch.
 *
 * @return array<int, array{namespace: string, group: string}>
 */
function filamentTranslationGroups(): array
{
    $groups = [];

    foreach (app('translation.loader')->namespaces() as $namespace => $hintPath) {
        if (! str_contains(strtr($hintPath, [DIRECTORY_SEPARATOR => '/']), '/filament/')) {
            continue;
        }

        $englishBase = "{$hintPath}/en";

        if (! is_dir($englishBase)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($englishBase, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $groups[] = [
                'namespace' => $namespace,
                'group' => strtr(substr($file->getPathname(), strlen($englishBase) + 1, -4), [DIRECTORY_SEPARATOR => '/']),
            ];
        }
    }

    return $groups;
}

/**
 * Những khoá mà giá trị `vi` TRÙNG `en` một cách chính đáng, mỗi khoá kèm lý do. Đây là danh
 * sách miễn trừ, không phải danh sách việc chưa làm — và nó phải ngắn, phải có lý do, và phải
 * liệt kê từng khoá một chứ không phải một mẫu khớp rộng, để không có khoá thật nào lẩn vào.
 *
 * @return array<string, string>
 */
function translationsThatAreTheSameInBothLanguages(): array
{
    return [
        // Mẫu tên tệp xuất/nhập, không phải câu chữ người đọc — đổi chúng là đổi tên tệp tải về.
        'filament-actions::export.file_name' => 'mẫu tên tệp',
        'filament-actions::import.example_csv.file_name' => 'mẫu tên tệp',
        'filament-actions::import.failure_csv.file_name' => 'mẫu tên tệp',
        // Token hướng viết của CSS ("ltr"), không phải chữ.
        'filament-panels::layout.direction' => 'token hướng viết, không phải chữ',
        // Danh từ riêng.
        'filament-panels::widgets/filament-info-widget.actions.open_github.label' => 'tên riêng',
        // Đơn vị và ký hiệu trục dùng nguyên dạng trong tiếng Việt.
        'filament-forms::components.file_upload.editor.fields.height.unit' => 'đơn vị px',
        'filament-forms::components.file_upload.editor.fields.width.unit' => 'đơn vị px',
        'filament-forms::components.file_upload.editor.fields.rotation.unit' => 'đơn vị deg',
        'filament-forms::components.file_upload.editor.fields.x_position.unit' => 'đơn vị px',
        'filament-forms::components.file_upload.editor.fields.y_position.unit' => 'đơn vị px',
        'filament-forms::components.file_upload.editor.fields.x_position.label' => 'ký hiệu trục X',
        'filament-forms::components.file_upload.editor.fields.y_position.label' => 'ký hiệu trục Y',
        'filament-forms::components.rich_editor.actions.link.modal.form.url.label' => 'viết tắt dùng nguyên dạng trong tiếng Việt',
        // Bản `vi` bundled cố ý dùng từ mượn "code" — khoá anh em `code_block` là "Khối code".
        'filament-forms::components.rich_editor.tools.code' => 'từ mượn đã dùng nhất quán ở khoá anh em',
    ];
}

/**
 * `lang/en/validation.php` là một BẢN CHÉP của tệp framework và nó CHE tệp gốc, nên đối chiếu
 * `vi` với chính nó sẽ mù trước một luật mới do một lần nâng Laravel mang tới. Hỏi bộ dịch ở
 * locale `en` thì được bản đã TRỘN (`FileLoader::loadPaths()` gộp
 * `vendor/laravel/framework/.../lang` rồi mới tới `lang/`), tức đúng thứ người dùng sẽ thấy khi
 * một khoá `vi` thiếu.
 */
it('translates every key of the effective English validation file into lang/vi/validation.php, with no English value left behind', function () {
    $english = flattenTranslationKeys(app('translator')->get('validation', [], 'en'));
    $vietnamese = flattenTranslationKeys(app('translator')->get('validation', [], 'vi'));

    expect($english)->not->toBeEmpty();

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

/**
 * Bản `lang/en/validation.php` trong repo không được phép TỤT LẠI so với tệp của framework: nếu
 * nó thiếu một khoá thì khoá đó vẫn về tới người dùng nhờ trộn, nhưng nếu nó giữ một khoá đã bị
 * framework đổi nghĩa thì nó che mất bản mới. Test này ghim đúng một câu: tệp trong repo không
 * che thêm khoá nào mà framework không có.
 */
it('keeps lang/en/validation.php from shadowing keys the framework no longer defines', function () {
    $repository = flattenTranslationKeys(require base_path('lang/en/validation.php'));
    $framework = flattenTranslationKeys(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));

    expect(array_diff(array_keys($repository), array_keys($framework)))->toBe([]);
});

it('has a Vietnamese string for every key any installed Filament package defines in English', function () {
    $groups = filamentTranslationGroups();

    // Nếu việc dò namespace ở trên hỏng thì test này sẽ im lặng đúng — mà đó chính là hình dạng
    // lỗ hổng nó sinh ra để đóng. Nên phải chắc có gì đó để so, và phải có mặt đúng gói từng là
    // điểm mù: `filament` (tức filament/support, nơi ô nhập mã một lần sống).
    expect($groups)->not->toBeEmpty()
        ->and(array_column($groups, 'namespace'))->toContain('filament')
        ->and(array_column($groups, 'group'))->toContain('components/input/one-time-code');

    $translator = app('translator');
    $exempt = translationsThatAreTheSameInBothLanguages();

    $missing = [];
    $untranslated = [];

    foreach ($groups as ['namespace' => $namespace, 'group' => $group]) {
        $english = flattenTranslationKeys($translator->get("{$namespace}::{$group}", [], 'en'));
        $vietnamese = flattenTranslationKeys($translator->get("{$namespace}::{$group}", [], 'vi'));

        foreach ($english as $key => $englishValue) {
            $fullKey = "{$namespace}::{$group}.{$key}";

            if (! array_key_exists($key, $vietnamese)) {
                $missing[] = $fullKey;

                continue;
            }

            if (! is_string($englishValue) || $vietnamese[$key] !== $englishValue) {
                continue;
            }

            // Chuỗi không có chữ cái nào (số, dấu chấm câu) thì không có gì để dịch.
            if (trim($englishValue) === '' || preg_match('/\p{L}/u', $englishValue) !== 1) {
                continue;
            }

            if (! array_key_exists($fullKey, $exempt)) {
                $untranslated[] = $fullKey;
            }
        }
    }

    expect($missing)->toBe([])
        ->and($untranslated)->toBe([]);
});

/**
 * Danh sách miễn trừ phải mục ruỗng theo thời gian chứ không phình ra: một khoá được miễn trừ mà
 * Filament đã dịch (hoặc đã xoá) thì phải rời danh sách, nếu không nó trở thành chỗ để khoá thật
 * lẩn vào sau này.
 */
it('keeps no stale entry in the same-in-both-languages exemption list', function () {
    $translator = app('translator');
    $stale = [];

    foreach (translationsThatAreTheSameInBothLanguages() as $fullKey => $reason) {
        [$namespace, $rest] = explode('::', $fullKey, 2);
        $group = Str::beforeLast($rest, '.');
        $key = Str::afterLast($rest, '.');

        $english = flattenTranslationKeys($translator->get("{$namespace}::{$group}", [], 'en'));
        $vietnamese = flattenTranslationKeys($translator->get("{$namespace}::{$group}", [], 'vi'));

        if (! array_key_exists($key, $english) || ($vietnamese[$key] ?? null) !== $english[$key]) {
            $stale[] = $fullKey;
        }
    }

    expect($stale)->toBe([]);
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
