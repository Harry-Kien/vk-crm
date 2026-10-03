<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Reflector;
use Livewire\Component;
use NotificationChannels\WebPush\PushSubscription;

/*
|--------------------------------------------------------------------------
| M12 R8 — không màn hình nào truy vấn thẳng `PushSubscription`
|--------------------------------------------------------------------------
|
| `NotificationChannels\WebPush\PushSubscription` là model của gói, nằm trong `vendor/` — ngoài lưới
| `app/Models` của `PortalCoverageTest`, cùng hoàn cảnh với `Activity` và `Media`
| (`tests/Feature/Authorization/PortalIsolationSweepTest.php`). Không global scope nào cắt nó theo
| người đang đăng nhập: một `PushSubscription::query()` viết vội ở một trang của cổng khách liệt
| kê thiết bị của MỌI người, và một endpoint là một URL mang quyền gửi thông báo tới máy đó.
|
| Luật: mã trong `app/`, `routes/` và `resources/views/` chỉ chạm bảng này qua quan hệ gọi trên MỘT
| người (`$user->pushSubscriptions()`, `updatePushSubscription()` của trait), trừ đúng các Action
| trong danh sách dưới — những nơi mà việc "nhìn mọi dòng" CHÍNH LÀ nghiệp vụ. Test này là lưới duy
| nhất (kế hoạch M12, "Những chỗ đã biết trước là sẽ cắn").
|
| Quét bằng token PHP (cùng cách `ActivityLogEventTranslationsTest`); tệp `.blade.php` được biên
| dịch bằng trình biên dịch Blade của ứng dụng trước — để thô thì cả tệp là MỘT token HTML, và
| `{{ … }}`, `@php`, `@foreach (…)`, thuộc tính `:x="…"` của component đều lọt.
|
| Một tham chiếu tới lớp — tên trần, bí danh (`use … as X`, kể cả trong `use …\{…}`), tên có
| namespace một phần hay đầy đủ — là chạm thẳng khi:
|  - theo sau là `::`, KỂ CẢ `::class`: chuỗi tên lớp chính là thứ mà `$model` của một Filament
|    Resource, `Rule::exists(…)`, `app(…)->newQuery()` nhận vào để tự truy vấn MỌI dòng;
|  - đứng sau `new`, hoặc sau `extends` (lớp con là một model không scope khác trên cùng bảng).
| Không tính: câu `use`, gợi ý kiểu, `instanceof`, chú thích — chúng không tự lấy dòng nào.
|
| Một chuỗi (kể cả chuỗi có biến, heredoc) là chạm thẳng khi chứa:
|  - từ `push_subscriptions` — `DB::table('push_subscriptions as ps')`, `exists:`/`unique:`, SQL thô;
|  - từ `PushSubscription` — tên lớp viết thành chuỗi, Blade nội tuyến của một component Livewire;
|  - đúng `pushSubscriptions` — tên quan hệ dạng chuỗi: `with()`/`has()`/`withCount()` trên truy vấn
|    NHIỀU người, `$relationship` của relation manager (thiết bị của người đang được XEM);
|  - đúng `webpush`, `webpush.model`, `webpush.table_name` — lớp hay bảng lấy động từ cấu hình.
|
| Gợi ý kiểu có MỘT chỗ tự truy vấn: route model binding (`{device}` → `PushSubscription $device`
| trên closure, controller, `mount()` của trang Livewire/Filament, hay thuộc tính public cùng tên của
| trang ấy) lấy dòng theo id của BẤT KỲ ai. Máy quét token không phân biệt được chỗ ấy với tham số
| của một hàm thường, nên hai test cuối đọc thẳng các route đã đăng ký.
|
| Ngoài tầm lưới này: gọi quan hệ trên một người KHÁC người đang đăng nhập
| (`User::find($id)->pushSubscriptions()`) — không phân biệt được bằng cú pháp; test màn hình của
| trang thiết bị (Task 5) canh việc đó.
|
| Duyệt thư mục bằng `scandir()`, KHÔNG bằng `RecursiveDirectoryIterator`: trên ổ 9p của Docker
| Desktop (Windows) iterator bỏ sót IM LẶNG mục của thư mục lớn (`bin/container-test`) — đo
| 2026-10-03: iterator thấy 412/451 tệp `.php` của `app/`, thiếu 39 tệp của `app/Exceptions`. Test
| "duyệt đủ" đối chiếu với `find`.
*/

/**
 * Đường dẫn (tính từ gốc dự án) được phép chạm thẳng bảng đăng ký. Mỗi dòng một lý do; tệp chưa
 * tồn tại là của task sau trong kế hoạch M12, ghi trước tên để task đó không phải mở rộng luật.
 * Nếu sau này cần một bí danh morph cho `PushSubscription` (`enforceMorphMap`), thêm
 * `app/Providers/AppServiceProvider.php` vào đây kèm lý do — và nới test "là một Action" cho đúng tệp
 * đó.
 *
 * @return array<string, string>
 */
function pushSubscriptionDirectAccessAllowed(): array
{
    return [
        // R7, Task 4 — `vkcrm:push-reset`: xoá MỌI đăng ký sau khi đổi khoá VAPID.
        'app/Actions/Push/ResetPushSubscriptions.php' => 'xoá mọi dòng',
        // R8, Task 5 — nút "Bật trên máy này": chuyển chủ endpoint (máy dùng chung).
        'app/Actions/Push/RegisterPushDevice.php' => 'tìm endpoint bất kể chủ',
        // R9, Task 6 — đăng xuất gỡ đúng thiết bị đang đăng xuất.
        'app/Actions/Push/ForgetPushDevice.php' => 'gỡ theo endpoint trong phiên',
        // R9, Task 6 — dọn đăng ký của tài khoản đã vô hiệu/xoá mềm và đăng ký cũ hơn 180 ngày.
        'app/Actions/Schedule/PrunePushSubscriptions.php' => 'dọn hằng ngày',
    ];
}

/**
 * Thư mục được quét, tính từ gốc dự án.
 *
 * @return list<string>
 */
function pushSubscriptionScannedRoots(): array
{
    return ['app', 'routes', 'resources/views'];
}

/**
 * Mọi tệp `.php` (gồm `.blade.php`) dưới các thư mục được quét, tính từ gốc dự án, theo thứ tự
 * byte. Duyệt bằng `scandir()` — xem đầu tệp vì sao không dùng `RecursiveDirectoryIterator`.
 *
 * @return list<string>
 */
function pushSubscriptionScannedFiles(): array
{
    $files = [];

    // `scandir()` hỏng thì phát E_WARNING, mà Laravel đổi thành ngoại lệ trong test — không mù im lặng.
    $walk = function (string $relative) use (&$walk, &$files): void {
        foreach (scandir(base_path($relative)) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $relative.'/'.$entry;

            if (is_dir(base_path($path))) {
                $walk($path);
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    };

    foreach (pushSubscriptionScannedRoots() as $root) {
        $walk($root);
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * Mã PHP để đưa vào máy quét: tệp `.blade.php` qua `Blade::compileString()` (để thô thì cả tệp là
 * một token `T_INLINE_HTML`), tệp khác giữ nguyên.
 */
function pushSubscriptionScannableCode(string $relativePath, string $contents): string
{
    return str_ends_with($relativePath, '.blade.php') ? Blade::compileString($contents) : $contents;
}

/**
 * Các vị trí (`dòng: mô tả`) trong một đoạn mã PHP chạm thẳng bảng đăng ký.
 *
 * @return list<string>
 */
function directPushSubscriptionAccesses(string $code): array
{
    $tokens = token_get_all($code);
    $count = count($tokens);
    $names = ['PushSubscription' => true];
    $hits = [];

    // Bí danh: `use …\PushSubscription as X;` và dạng nhóm `use …\WebPush\{PushSubscription as X}`.
    if (preg_match_all('/(?<!\w)PushSubscription\s+as\s+(\w+)/', $code, $aliases)) {
        foreach ($aliases[1] as $alias) {
            $names[$alias] = true;
        }
    }

    $skippable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    $next = function (int $from) use ($tokens, $count, $skippable): ?int {
        for ($k = $from; $k < $count; $k++) {
            if (! (is_array($tokens[$k]) && in_array($tokens[$k][0], $skippable, true))) {
                return $k;
            }
        }

        return null;
    };

    $previous = function (int $from) use ($tokens, $skippable): ?int {
        for ($k = $from; $k >= 0; $k--) {
            if (! (is_array($tokens[$k]) && in_array($tokens[$k][0], $skippable, true))) {
                return $k;
            }
        }

        return null;
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            continue;
        }

        [$kind, $text, $line] = $token;

        // Tên trần hay bí danh khớp nguyên; tên có namespace (một phần, đầy đủ, `namespace\…`) khớp
        // theo đoạn cuối.
        $isClass = match ($kind) {
            T_STRING => isset($names[$text]),
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => str_ends_with($text, '\\PushSubscription'),
            default => false,
        };

        if ($isClass) {
            $after = $next($i + 1);
            $before = $previous($i - 1);

            if ($after !== null && is_array($tokens[$after]) && $tokens[$after][0] === T_DOUBLE_COLON) {
                $member = $next($after + 1);
                $hits[] = $line.': '.$text.'::'.($member === null ? '' : (is_array($tokens[$member]) ? $tokens[$member][1] : $tokens[$member]));
            } elseif ($before !== null && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_NEW, T_EXTENDS], true)) {
                $hits[] = $line.': '.$tokens[$before][1].' '.$text;
            }
        }

        if ($kind === T_CONSTANT_ENCAPSED_STRING || $kind === T_ENCAPSED_AND_WHITESPACE) {
            $literal = $kind === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;

            if (preg_match('/(?<!\w)(push_subscriptions|PushSubscription)(?!\w)|^(pushSubscriptions|webpush|webpush\.model|webpush\.table_name)$/', $literal) === 1) {
                $hits[] = $line.': '.$text;
            }
        }
    }

    return $hits;
}

/**
 * Chỗ route model binding sẽ nạp một `PushSubscription` (hay lớp con) theo id lấy từ URL, bất kể
 * chủ. Đọc đúng những nơi Laravel và Livewire tự bind, cùng cách chúng đọc kiểu
 * (`Reflector::getParameterClassName()`: chỉ kiểu có tên, kiểu hợp không bao giờ được bind):
 *  - tham số của hành động route — closure hay controller (`Illuminate\Routing\ImplicitRouteBinding`);
 *  - với route phục vụ một component Livewire — `Route::get($path, Page::class)` của Filament
 *    (component là controller) hay `Route::livewire()` (component ở `action['livewire_component']`):
 *    tham số của `mount()`, và thuộc tính public có kiểu trùng TÊN một tham số route
 *    (`Livewire\Drawer\ImplicitRouteBinding::resolveComponentProps()`).
 *
 * @param  iterable<RouteDefinition>  $routes
 * @return list<string>
 */
function pushSubscriptionBoundRouteParameters(iterable $routes): array
{
    $hits = [];

    foreach ($routes as $route) {
        $candidates = $route->signatureParameters();
        $component = $route->action['livewire_component'] ?? $route->getControllerClass();

        if (is_subclass_of($component, Component::class)) {
            if (method_exists($component, 'mount')) {
                $candidates = [...$candidates, ...(new ReflectionMethod($component, 'mount'))->getParameters()];
            }

            foreach ($route->parameterNames() as $name) {
                if (property_exists($component, $name) && (new ReflectionProperty($component, $name))->isPublic()) {
                    $candidates[] = new ReflectionProperty($component, $name);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_a(Reflector::getParameterClassName($candidate), PushSubscription::class, true)) {
                $hits[] = $route->uri().' — $'.$candidate->getName();
            }
        }
    }

    return $hits;
}

/** Trang Livewire mẫu: bind qua `mount()`. Chỉ test máy dò dùng; route của nó chỉ có trong test đó. */
class PushSubscriptionBindingProbePage extends Component
{
    public function mount(PushSubscription $device): void {}

    public function render(): string
    {
        return '<div></div>';
    }
}

/**
 * Trang Livewire mẫu: bind qua thuộc tính public có kiểu, KHÔNG có `mount()`. Thuộc tính protected
 * thì Livewire không bind.
 */
class PushSubscriptionBindingProbeProperty extends Component
{
    public ?PushSubscription $device = null;

    protected ?PushSubscription $kept = null;

    public function render(): string
    {
        return '<div></div>';
    }
}

/** Lớp con mẫu của model gói — binding theo lớp con cũng là binding vào bảng đăng ký. */
class PushSubscriptionBindingProbeDevice extends PushSubscription {}

it('máy quét bắt dạng đi vòng trong mã PHP', function (string $code) {
    expect(directPushSubscriptionAccesses("<?php\n".$code))->not->toBe([]);
})->with([
    'truy cập tĩnh qua tên trần' => [<<<'CODE'
        use NotificationChannels\WebPush\PushSubscription;
        PushSubscription::query()->get();
        CODE],
    'bí danh use … as' => [<<<'CODE'
        use NotificationChannels\WebPush\PushSubscription as Device;
        Device::where('endpoint', $x)->first();
        CODE],
    'bí danh trong use nhóm' => [<<<'CODE'
        use NotificationChannels\WebPush\{HasPushSubscriptions, PushSubscription as Device};
        Device::all();
        CODE],
    'tên đầy đủ' => [<<<'CODE'
        \NotificationChannels\WebPush\PushSubscription::all();
        CODE],
    'tên có namespace một phần' => [<<<'CODE'
        use NotificationChannels\WebPush;
        WebPush\PushSubscription::count();
        CODE],
    'new' => [<<<'CODE'
        $row = new PushSubscription;
        CODE],
    'new có chú thích xen giữa' => [<<<'CODE'
        $row = new /* thiết bị */ PushSubscription();
        CODE],
    'extends' => [<<<'CODE'
        class Device extends PushSubscription {}
        CODE],
    '$model của Filament Resource (::class)' => [<<<'CODE'
        use NotificationChannels\WebPush\PushSubscription;
        class DevicesResource { protected static ?string $model = PushSubscription::class; }
        CODE],
    'Rule::exists(::class)' => [<<<'CODE'
        $rules = ['endpoint' => Rule::exists(PushSubscription::class, 'endpoint')];
        CODE],
    'app(::class)->newQuery()' => [<<<'CODE'
        $all = app(PushSubscription::class)->newQuery()->get();
        CODE],
    '::class qua bí danh' => [<<<'CODE'
        use NotificationChannels\WebPush\PushSubscription as Device;
        $class = Device::class;
        CODE],
    '::class tên đầy đủ' => [<<<'CODE'
        $class = \NotificationChannels\WebPush\PushSubscription::class;
        CODE],
    'DB::table' => [<<<'CODE'
        DB::table('push_subscriptions')->get();
        CODE],
    'DB::table có bí danh bảng' => [<<<'CODE'
        DB::table('push_subscriptions as ps')->get();
        CODE],
    'quy tắc unique:' => [<<<'CODE'
        $rules = ['endpoint' => 'unique:push_subscriptions,endpoint'];
        CODE],
    'SQL thô' => [<<<'CODE'
        DB::select('select * from push_subscriptions');
        CODE],
    'cột kèm tên bảng' => [<<<'CODE'
        $query->where('push_subscriptions.endpoint', $endpoint);
        CODE],
    'chuỗi có biến' => [<<<'CODE'
        DB::select("select * from push_subscriptions where id = $id");
        CODE],
    'heredoc' => [<<<'CODE'
        DB::statement(<<<SQL
            delete from push_subscriptions
            SQL);
        CODE],
    'tên lớp viết thành chuỗi' => [<<<'CODE'
        $all = app('NotificationChannels\WebPush\PushSubscription')->newQuery()->get();
        CODE],
    'Blade nội tuyến của component Livewire' => [<<<'CODE'
        return <<<'BLADE'
            <div>{{ \NotificationChannels\WebPush\PushSubscription::count() }}</div>
            BLADE;
        CODE],
    'cấu hình webpush.model' => [<<<'CODE'
        $model = config('webpush.model');
        CODE],
    'cấu hình webpush.table_name' => [<<<'CODE'
        $table = config('webpush.table_name');
        CODE],
    'cả mảng cấu hình webpush' => [<<<'CODE'
        $model = config('webpush')['model'];
        CODE],
    'tên quan hệ dạng chuỗi trên truy vấn nhiều người' => [<<<'CODE'
        User::query()->with('pushSubscriptions')->get();
        CODE],
    '$relationship của relation manager' => [<<<'CODE'
        class DevicesRelationManager { protected static string $relationship = 'pushSubscriptions'; }
        CODE],
]);

it('máy quét bắt dạng đi vòng trong Blade đã biên dịch', function (string $blade) {
    expect(directPushSubscriptionAccesses(pushSubscriptionScannableCode('resources/views/x.blade.php', $blade)))->not->toBe([]);
})->with([
    '@php' => ['@php $all = \NotificationChannels\WebPush\PushSubscription::all(); @endphp'],
    '{{ }}' => ['<p>{{ \NotificationChannels\WebPush\PushSubscription::query()->count() }}</p>'],
    '@foreach' => ["@foreach (DB::table('push_subscriptions')->get() as \$row) <li>{{ \$row->device_label }}</li> @endforeach"],
    '@use bí danh' => ["@use('NotificationChannels\\WebPush\\PushSubscription', 'Device') <p>{{ Device::count() }}</p>"],
    'thuộc tính của component' => ['<x-filament::section :heading="\NotificationChannels\WebPush\PushSubscription::query()->count()">x</x-filament::section>'],
]);

it('máy quét tha các dạng hợp lệ', function () {
    $php = <<<'CODE'
        <?php
        use NotificationChannels\WebPush\PushSubscription;
        use NotificationChannels\WebPush\{HasPushSubscriptions, PushSubscription as Device};
        class Account { use HasPushSubscriptions; }
        $user->pushSubscriptions()->where('endpoint', $endpoint)->delete();
        $user->updatePushSubscription($endpoint, $key, $token);
        $user->deletePushSubscription($endpoint);
        function label(PushSubscription $subscription): string { return (string) $subscription->device_label; }
        $isDevice = $row instanceof PushSubscription;
        /** @var PushSubscription $row */
        // PushSubscription::query() trong chú thích không phải lời gọi
        Audit::record('push_subscriptions_reset', null, ['count' => 1]);
        $key = config('webpush.vapid.public_key');
        CODE;

    $blade = <<<'BLADE'
        {{-- PushSubscription::query() trong chú thích Blade --}}
        @foreach (auth()->user()->pushSubscriptions as $device)
            <li>{{ $device->device_label }}</li>
        @endforeach
        BLADE;

    expect(directPushSubscriptionAccesses($php))->toBe([])
        ->and(directPushSubscriptionAccesses(pushSubscriptionScannableCode('resources/views/x.blade.php', $blade)))->toBe([]);
});

it('lưới duyệt đủ mọi tệp .php của app/, routes/ và resources/views — đối chiếu với find', function () {
    $result = Process::path(base_path())->run(['find', ...pushSubscriptionScannedRoots(), '-type', 'f', '-name', '*.php']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $found = array_values(array_filter(explode("\n", str_replace("\r", '', $result->output()))));
    sort($found, SORT_STRING);

    expect(pushSubscriptionScannedFiles())->toBe($found)
        ->toContain('routes/web.php', 'routes/pwa.php', 'routes/console.php', 'resources/views/pwa/head.blade.php');
});

it('mã trong app/, routes/ và resources/views chỉ chạm thẳng bảng push_subscriptions ở các Action được liệt kê', function () {
    $offenders = [];
    $touching = [];

    foreach (pushSubscriptionScannedFiles() as $relative) {
        $hits = directPushSubscriptionAccesses(pushSubscriptionScannableCode($relative, (string) file_get_contents(base_path($relative))));

        if ($hits === []) {
            continue;
        }

        $touching[] = $relative;

        if (! array_key_exists($relative, pushSubscriptionDirectAccessAllowed())) {
            $offenders[] = $relative.' — '.implode('; ', $hits);
        }
    }

    expect($offenders)->toBe([], "Chạm thẳng bảng đăng ký ngoài danh sách cho phép:\n".implode("\n", $offenders))
        // Đối chứng dương: lưới bắt được lời gọi THẬT của Action push-reset (nếu nó không còn
        // xuất hiện ở đây thì máy quét đã mù, không phải mã đã sạch).
        ->and($touching)->toContain('app/Actions/Push/ResetPushSubscriptions.php');
});

it('mọi đường dẫn trong danh sách cho phép là một Action', function () {
    foreach (array_keys(pushSubscriptionDirectAccessAllowed()) as $path) {
        expect($path)->toStartWith('app/Actions/');
    }
});

it('máy dò route model binding thấy closure, lớp con, mount() và thuộc tính của trang Livewire', function () {
    // Đăng ký đúng như mã thật đăng ký (app của test được dựng lại cho mỗi test).
    $closure = Route::get('probe/{device}', fn (?PushSubscription $device) => '');
    $subclass = Route::get('probe-subclass/{device}', fn (PushSubscriptionBindingProbeDevice $device) => '');
    $page = Route::get('probe-page/{device}', PushSubscriptionBindingProbePage::class);
    $livewire = Route::livewire('probe-livewire/{device}', PushSubscriptionBindingProbePage::class);
    $property = Route::get('probe-property/{device}', PushSubscriptionBindingProbeProperty::class);
    // Không bind: tham số kiểu dựng sẵn và không kiểu; thuộc tính public không trùng tên tham số route
    // nào (`$device`), thuộc tính protected dù trùng tên (`$kept`).
    $plain = Route::get('probe-plain/{id}/{slug}', fn (int $id, $slug) => '');
    $unbound = Route::get('probe-unbound/{id}/{kept}', PushSubscriptionBindingProbeProperty::class);

    expect(pushSubscriptionBoundRouteParameters([$closure]))->toBe(['probe/{device} — $device'])
        ->and(pushSubscriptionBoundRouteParameters([$subclass]))->toBe(['probe-subclass/{device} — $device'])
        ->and(pushSubscriptionBoundRouteParameters([$page]))->toBe(['probe-page/{device} — $device'])
        ->and(pushSubscriptionBoundRouteParameters([$livewire]))->toBe(['probe-livewire/{device} — $device'])
        ->and(pushSubscriptionBoundRouteParameters([$property]))->toBe(['probe-property/{device} — $device'])
        ->and(pushSubscriptionBoundRouteParameters([$plain, $unbound]))->toBe([]);
});

it('không route nào bind một tham số kiểu PushSubscription (route model binding lấy dòng của bất kỳ ai)', function () {
    $routes = Route::getRoutes()->getRoutes();

    expect($routes)->not->toBeEmpty()
        ->and(pushSubscriptionBoundRouteParameters($routes))->toBe([]);
});
