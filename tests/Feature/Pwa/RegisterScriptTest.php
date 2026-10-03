<?php

use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Pwa\PwaPanels;
use App\Support\Pwa\RegisterScript;
use Illuminate\Support\Facades\File;

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
 * `undefined` và im lặng không làm gì), và thẻ không in khoá nào mà tệp không đọc.
 */
it('reads exactly the data-* attributes the head tag prints', function () {
    preg_match_all('/\bdata\.([a-zA-Z]+)\b/', pwaRegisterCode(pwaRegisterSource()), $reads);
    $read = collect($reads[1])->map(fn (string $key): string => strtolower((string) preg_replace('/([A-Z])/', '-$1', $key)))
        ->unique()->sort()->values()->all();

    $printed = collect(array_keys(pwaRegisterData(pwaRegisterTag($this->get('/admin/login')->getContent()))))
        ->sort()->values()->all();

    expect($read)->not->toBe([])
        ->and($read)->toBe($printed);
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
