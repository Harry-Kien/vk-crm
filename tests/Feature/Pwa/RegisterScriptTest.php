<?php

use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Pwa\PwaPanels;
use App\Support\Pwa\RegisterScript;
use Illuminate\Support\Facades\File;
use Tests\Support\WebPushTestKeys;

/*
|--------------------------------------------------------------------------
| M12 Task 3 — script đăng ký `public/pwa/register.js` (kế hoạch M12, phán quyết R5)
|--------------------------------------------------------------------------
|
| R5: không thêm script nội tuyến nào — việc đăng ký nằm trong một tệp TĨNH, tham số đi qua
| `data-*` của chính thẻ `<script>` mà `resources/views/pwa/head.blade.php` in ra. Hai điều dễ sai
| một cách im lặng:
|
|  - mẫu nginx/Apache gửi `Cache-Control: public, max-age=31536000, immutable` cho mọi `.js` tĩnh
|    (`tools/deploy/`): không có `?v=<băm nội dung>` thì bản sửa của `register.js` không bao giờ
|    tới điện thoại đã cài;
|  - tệp đọc một `data-*` mà thẻ không in (hoặc ngược lại) thì không gì báo lỗi — test so hai bên.
*/

/** Phần HTML do hook M12 in ra (giữa hai dấu chú thích `vk-pwa:head`), trong `<head>`. */
function pwaRegisterHeadBlock(string $html): string
{
    $start = strpos($html, '<!-- vk-pwa:head -->');
    $end = strpos($html, '<!-- /vk-pwa:head -->');

    expect($start)->not->toBeFalse()
        ->and($end)->toBeGreaterThan($start)
        ->and($end)->toBeLessThan(strpos($html, '</head>'));

    return substr($html, $start, $end - $start);
}

/** Văn bản đã bỏ chú thích `//` và `/* … *\/`. */
function pwaRegisterCode(string $source): string
{
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    return (string) preg_replace('#^\s*//.*$#m', '', $source);
}

function pwaRegisterTag(string $html): string
{
    expect(preg_match_all('/<script\b[^>]*\bsrc="[^"]*\/pwa\/register\.js[^"]*"[^>]*>/', $html, $tags))->toBe(1);

    return $tags[0][0];
}

/** @return array<string, string> thuộc tính `data-*` của thẻ, khoá là phần sau `data-`. */
function pwaRegisterData(string $tag): array
{
    preg_match_all('/\sdata-([a-z-]+)="([^"]*)"/', $tag, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m): array => [$m[1] => html_entity_decode($m[2])])->all();
}

function pwaRegisterSource(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(public_path('pwa/register.js')));
}

it('loads register.js from a static file, deferred, versioned by its content, inside the M12 head block', function (string $panel, string $url) {
    $html = $this->get($url)->assertOk()->getContent();
    $tag = pwaRegisterTag(pwaRegisterHeadBlock($html));

    expect(public_path('pwa/register.js'))->toBeFile()
        ->and($tag)->toContain(' defer')
        ->and($tag)->toContain('src="'.e(asset('pwa/register.js').'?v='.substr(hash_file('sha256', public_path('pwa/register.js')), 0, 12)).'"')
        ->and($tag)->toContain('src="'.e(RegisterScript::url()).'"');
})->with([
    'admin login' => ['admin', '/admin/login'],
    'portal login' => ['portal', '/portal/login'],
]);

it('hands each panel its own worker and scope through data-*', function (string $panel) {
    $data = pwaRegisterData(pwaRegisterTag($this->get("/{$panel}/login")->getContent()));

    expect($data)->toBe([
        'sw' => route("pwa.{$panel}.sw"),
        'scope' => PwaPanels::path($panel),
    ]);
})->with(['admin', 'portal']);

it('prints the same tag on a signed-in page of each panel', function () {
    $staff = User::factory()->withRole(Role::Admin)->create();
    $client = ClientUser::factory()->activated()->create();

    expect(pwaRegisterData(pwaRegisterTag($this->actingAs($staff, 'web')->get('/admin')->assertOk()->getContent()))['scope'])->toBe('/admin');
    expect(pwaRegisterData(pwaRegisterTag($this->actingAs($client, 'client')->get('/portal')->assertOk()->getContent()))['scope'])->toBe('/portal');
});

/**
 * Brief Task 3, sự thật 4: "Có test ghim `?v=` đổi khi tệp đổi". Bản sửa của tệp nằm ở một thư
 * mục `public` thay thế (`app()->usePublicPath()`), nên tệp thật không bị đụng.
 */
it('changes the ?v= of register.js when the file changes', function () {
    $before = RegisterScript::url();

    $public = storage_path('framework/testing/pwa-public-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($public.'/pwa');
    File::put($public.'/pwa/register.js', pwaRegisterSource()."\n// sửa đổi thử\n");

    try {
        app()->usePublicPath($public);

        $after = RegisterScript::url();

        expect($after)->not->toBe($before)
            ->and($after)->toEndWith('?v='.substr(hash_file('sha256', $public.'/pwa/register.js'), 0, 12))
            ->and(pwaRegisterTag($this->get('/portal/login')->getContent()))->toContain('src="'.e($after).'"');
    } finally {
        File::deleteDirectory($public);
    }
});

/**
 * Hợp đồng `data-*`: mọi khoá `dataset.x` mà tệp đọc phải được thẻ in ra (thiếu thì tệp nhận
 * `undefined` và im lặng không làm gì), và thẻ không in khoá nào mà tệp không đọc. Đo trên trang
 * ĐÃ ĐĂNG NHẬP của máy chủ có khoá VAPID — nơi thẻ in đủ, kể cả ba thuộc tính push (Task 5).
 */
it('reads exactly the data-* attributes the head tag prints', function () {
    config(WebPushTestKeys::config());
    preg_match_all('/\bdata\.([a-zA-Z]+)\b/', pwaRegisterCode(pwaRegisterSource()), $reads);
    $read = collect($reads[1])->map(fn (string $key): string => strtolower((string) preg_replace('/([A-Z])/', '-$1', $key)))
        ->unique()->sort()->values()->all();

    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $printed = collect(array_keys(pwaRegisterData(pwaRegisterTag($this->actingAs($staff, 'web')->get('/admin')->getContent()))))
        ->sort()->values()->all();

    expect($read)->not->toBe([])
        ->and($read)->toBe($printed)
        ->and($read)->toBe(['push-check', 'push-key', 'push-url', 'scope', 'sw']);
});

/**
 * M12 Task 5 (R8, R7) — ba thuộc tính push chỉ trên trang đã đăng nhập của máy chủ có khoá VAPID:
 * trang đăng nhập và máy chủ chưa bật push không có chúng, nên script không gửi lượt kiểm nào, không
 * hiện nút nào ở đó.
 */
it('hands the push data-* only to a signed-in page of a server with VAPID keys', function (string $panel, Closure $viewer) {
    $user = $viewer();
    $guard = $panel === 'admin' ? 'web' : 'client';

    // Thiếu khoá (phpunit.xml để trống): chỉ hai thuộc tính của Task 3, kể cả khi đã đăng nhập.
    expect(array_keys(pwaRegisterData(pwaRegisterTag($this->actingAs($user, $guard)->get("/{$panel}")->getContent()))))
        ->toBe(['sw', 'scope']);

    config(WebPushTestKeys::config());
    $data = pwaRegisterData(pwaRegisterTag($this->actingAs($user, $guard)->get("/{$panel}")->getContent()));

    expect($data)->toBe([
        'sw' => route("pwa.{$panel}.sw"),
        'scope' => PwaPanels::path($panel),
        'push-key' => WebPushTestKeys::vapid()['public'],
        'push-url' => url("/{$panel}/push/subscriptions"),
        'push-check' => '1',
    ]);

    // Trang đăng nhập của panel đó: không push dù có khoá.
    auth($guard)->logout();
    expect(array_keys(pwaRegisterData(pwaRegisterTag($this->get("/{$panel}/login")->getContent()))))
        ->toBe(['sw', 'scope']);
})->with([
    'admin' => ['admin', fn () => User::factory()->withRole(Role::Lawyer)->create()],
    'portal' => ['portal', fn () => ClientUser::factory()->activated()->create()],
]);

/**
 * Lượt kiểm `sync=1` chạy MỘT lần mỗi phiên máy chủ: sau lượt kiểm, trang in `push-check="0"`.
 * Theo guard: lượt kiểm của panel này không tính cho panel kia.
 */
it('flips push-check to 0 once this session has been checked, per guard', function () {
    config(WebPushTestKeys::config());
    $client = ClientUser::factory()->activated()->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($client, 'client')->actingAs($staff, 'web');

    $this->postJson('/portal/push/subscriptions', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/kiem-mot-lan',
        'keys' => WebPushTestKeys::subscription(),
        'sync' => 1,
    ])->assertOk();

    expect(pwaRegisterData(pwaRegisterTag($this->get('/portal')->getContent()))['push-check'])->toBe('0')
        ->and(pwaRegisterData(pwaRegisterTag($this->get('/admin')->getContent()))['push-check'])->toBe('1');
});

/**
 * Kế hoạch: script đăng ký dưới 200 dòng; "chuỗi mà JavaScript cần được render từ PHP vào thuộc
 * tính `data-*`, không viết cứng trong tệp `.js`" — mã (đã bỏ chú thích) không có ký tự tiếng Việt.
 */
it('keeps register.js small and free of hard-coded Vietnamese strings', function () {
    $source = pwaRegisterSource();

    expect(substr_count($source, "\n"))->toBeLessThan(200)
        ->and(preg_match('/[^\x00-\x7F]/', pwaRegisterCode($source)))->toBe(0);
});

/**
 * Đăng ký đúng scope không dấu `/` cuối, sau khi trang tải xong, và không làm gì khi trình duyệt
 * không có service worker (trang vẫn chạy y như trước M12).
 */
it('registers the worker with the scope from data-* only where the browser supports it', function () {
    $code = pwaRegisterCode(pwaRegisterSource());

    expect($code)->toContain("'serviceWorker' in navigator")
        ->toContain('navigator.serviceWorker.register(data.sw, { scope: data.scope })')
        ->toContain('document.currentScript');
});

/**
 * M12 Task 5 (R8) — các điều mà không test tự động nào khác chạm được (máy dev không có Node; hành vi
 * kiểm bằng `tools/pwa/survey-push.cjs`), ghim trên văn bản:
 *  - xin quyền thông báo ĐÚNG MỘT chỗ, trong `enable()`, và `enable()` chỉ được gọi từ trình xử lý
 *    cú bấm — không bao giờ lúc tải trang;
 *  - lượt kiểm gửi `sync: 1`; request mang CSRF, cùng origin, không đi theo chuyển hướng.
 */
it('asks for notification permission only from the click handler and sends a guarded request', function () {
    $code = pwaRegisterCode(pwaRegisterSource());

    expect(substr_count($code, 'requestPermission('))->toBe(1)
        ->and(substr_count($code, 'enable()'))->toBe(2)
        ->and(preg_match('/function enable\(\) \{.*?requestPermission\(.*?\n  \}\n/s', $code))->toBe(1)
        ->and(preg_match("/document\.addEventListener\('click', function \(event\) \{[^}]*?\{[^}]*?\}[^}]*?if \(canPush\) enable\(\);/s", $code))->toBe(1);

    $onLoad = substr($code, strrpos($code, "window.addEventListener('load'"));
    expect($onLoad)->not->toContain('enable(')
        ->not->toContain('requestPermission')
        ->not->toContain('subscribe(')
        ->toContain('send(subscription, { sync: 1 })');

    expect($code)->toContain("redirect: 'manual'")
        ->toContain("credentials: 'same-origin'")
        ->toContain("'X-CSRF-TOKEN'")
        ->toContain('userVisibleOnly: true')
        ->toContain('applicationServerKey: keyBytes()');
});

/**
 * M12 Task 5, vòng sửa 1 (I1) — chiều Livewire → script. Trang thiết bị phát `vk-push-device-removed`
 * khi máy của trình duyệt này bị gỡ (`App\Filament\Concerns\ManagesOwnPushDevices`, ca Livewire ở
 * `tests/Feature/Push/PushDevicesPageTest.php`); sự kiện Livewire nổi bọt từ phần tử component lên
 * `window`. Script nghe ở đó và chỉ đổi câu "đang nhận" thành khối có nút Bật: mọi câu khác (chặn,
 * chưa hỗ trợ, chưa cài, chưa bật được) vẫn đúng sau khi gỡ. Không hỏi quyền, không gửi request.
 */
it('turns "this device is receiving" back into the enable button when the page removes this device', function () {
    $code = pwaRegisterCode(pwaRegisterSource());

    expect(substr_count($code, "'vk-push-device-removed'"))->toBe(1)
        ->and(preg_match(
            "/\n  window\.addEventListener\('vk-push-device-removed', function \(\) \{\n"
            ."    var receiving = document\.querySelector\('\[data-vk-push-state=\"enabled\"\]'\);\n"
            ."    if \(receiving && !receiving\.hidden\) show\('ready'\);\n"
            ."  \}\);\n/",
            $code,
        ))->toBe(1);
});
