<?php

use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use Filament\Facades\Filament;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M12 Task 2 — manifest của hai app (kế hoạch M12, phán quyết R2)
|--------------------------------------------------------------------------
|
| Hai app trên một máy chủ: `/admin/manifest.webmanifest` và `/portal/manifest.webmanifest`.
| Ba điều dễ sai một cách im lặng, nên mỗi điều có test riêng:
|
|  - `scope` có dấu `/` cuối thì Chromium BỎ scope và rơi về cả origin — app nội bộ "nuốt" cả
|    `/portal` (đo ở `docs/research/2026-10-01-pwa-khao-sat.md` mục 2.3). Hai `id` và hai `scope`
|    phải khác nhau (phán quyết tạm 3 của Task 1).
|  - Manifest được trình duyệt tải KHÔNG kèm cookie. Đi qua `StartSession` với
|    `SESSION_DRIVER=database` thì mỗi lần tải đẻ một dòng `sessions` mồ côi.
|  - Màu và đường dẫn phải đọc từ config và từ panel, không viết cứng lần thứ hai.
*/

/** Path của URL panel — đúng cách `PwaPanels::path()` rút nó, để test không viết cứng `/admin`. */
function pwaPanelPath(string $panel): string
{
    return parse_url(Filament::getPanel($panel)->getUrl(), PHP_URL_PATH);
}

/** @return array<string, mixed> */
function pwaManifest(string $panel): array
{
    return json_decode(test()->get("/{$panel}/manifest.webmanifest")->assertOk()->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

it('serves each panel manifest as application/manifest+json with the R2 values', function (string $panel, string $name, string $shortName) {
    $response = $this->get("/{$panel}/manifest.webmanifest");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    $manifest = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $path = pwaPanelPath($panel);

    expect($path)->toBe("/{$panel}")
        ->and($manifest['id'])->toBe($path)
        ->and($manifest['scope'])->toBe($path)
        ->and($manifest['start_url'])->toBe($path)
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['orientation'])->toBe('portrait')
        ->and($manifest['lang'])->toBe('vi')
        ->and($manifest['name'])->toBe($name)
        ->and($manifest['short_name'])->toBe($shortName)
        ->and($manifest['name'])->toBe(__("pwa.{$panel}.name", ['firm' => config('vkcrm.brand.short_name')]))
        ->and($manifest['short_name'])->toBe(__("pwa.{$panel}.short_name", ['firm' => config('vkcrm.brand.short_name')]))
        ->and($manifest['theme_color'])->toBe(config('vkcrm.brand.colors.navy'))
        ->and($manifest['background_color'])->toBe(config('vkcrm.brand.colors.paper'));
})->with([
    'admin' => ['admin', 'Luật Vũ Khang — Nội bộ', 'VK Nội bộ'],
    'portal' => ['portal', 'Luật Vũ Khang — Khách hàng', 'Luật Vũ Khang'],
]);

it('gives the two apps different ids and scopes, none with a trailing slash (Task 1 interim ruling 3)', function () {
    $admin = pwaManifest('admin');
    $portal = pwaManifest('portal');

    expect($admin['id'])->not->toBe($portal['id'])
        ->and($admin['scope'])->not->toBe($portal['scope']);

    foreach ([$admin, $portal] as $manifest) {
        foreach (['id', 'scope', 'start_url'] as $key) {
            expect($manifest[$key])->toStartWith('/')->not->toEndWith('/');
        }

        // Chromium bỏ scope khi start_url nằm ngoài nó (khảo sát 2.3) — so tiền tố chuỗi như trình duyệt.
        expect(str_starts_with($manifest['start_url'], $manifest['scope']))->toBeTrue();
    }
});

it('lists a 192 and a 512 icon for any purpose plus the panel own maskable 512, each a real file of that size', function (string $panel) {
    $icons = collect(pwaManifest($panel)['icons']);

    $any = $icons->where('purpose', 'any');
    $maskable = $icons->where('purpose', 'maskable');

    expect($any->pluck('sizes')->sort()->values()->all())->toBe(['192x192', '512x512'])
        ->and($maskable)->toHaveCount(1)
        ->and($maskable->first()['sizes'])->toBe('512x512')
        ->and($maskable->first()['src'])->toBe(asset(AppIcons::maskable($panel)));

    foreach ($icons as $icon) {
        expect($icon['type'])->toBe('image/png');

        $file = public_path(ltrim(parse_url($icon['src'], PHP_URL_PATH), '/'));
        expect($file)->toBeFile();

        [$width, $height] = getimagesize($file);
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
})->with(['admin', 'portal']);

it('keeps the two maskable icons apart so a staff member with both apps can tell them by eye', function () {
    $maskableOf = fn (string $panel) => collect(pwaManifest($panel)['icons'])->firstWhere('purpose', 'maskable')['src'];

    expect($maskableOf('admin'))->not->toBe($maskableOf('portal'));
});

/**
 * Mutation probe của kế hoạch: "đổi `theme_color` sang một mã viết cứng thì test đọc config phải
 * đỏ". So với một màu KHÁC `#101d35` đặt vào config, không so với `#101d35` viết cứng — nếu không,
 * một manifest viết cứng đúng màu navy hôm nay vẫn xanh.
 */
it('reads theme and background colours from config, not from a second hard-coded copy', function (string $panel) {
    config([
        'vkcrm.brand.colors.navy' => '#123456',
        'vkcrm.brand.colors.paper' => '#fedcba',
    ]);

    $manifest = pwaManifest($panel);

    expect($manifest['theme_color'])->toBe('#123456')
        ->and($manifest['background_color'])->toBe('#fedcba');
})->with(['admin', 'portal']);

it('names the apps from the firm short name in config', function () {
    config(['vkcrm.brand.short_name' => 'Văn phòng Thử']);

    expect(pwaManifest('portal')['name'])->toBe('Văn phòng Thử — Khách hàng')
        ->and(pwaManifest('portal')['short_name'])->toBe('Văn phòng Thử')
        ->and(pwaManifest('admin')['name'])->toBe('Văn phòng Thử — Nội bộ');
});

/**
 * `phpunit.xml` đặt `SESSION_DRIVER=array` — với `array` không có dòng nào để đếm và test xanh vô
 * nghĩa. Test bật `database` (driver của `.env.example`) cho riêng mình. Tự kiểm ở cuối: cùng ba
 * lần tải vào một trang ĐI QUA `StartSession` (trang đăng nhập) phải đẻ dòng — không thì phép đếm
 * không đo được gì.
 */
it('sets no cookie and starts no session row when the browser fetches the manifest', function (string $panel) {
    config(['session.driver' => 'database']);

    $before = DB::table('sessions')->count();

    foreach (range(1, 3) as $attempt) {
        $response = $this->get("/{$panel}/manifest.webmanifest")->assertOk();

        expect($response->headers->getCookies())->toBe([])
            ->and($response->headers->has('Set-Cookie'))->toBeFalse();
    }

    expect(DB::table('sessions')->count())->toBe($before);

    // Đối chứng: phép đếm này thấy được một route có phiên.
    $this->get("/{$panel}/login")->assertOk();
    expect(DB::table('sessions')->count())->toBeGreaterThan($before);
})->with(['admin', 'portal']);

/**
 * Docblock `routes/pwa.php`: manifest nằm NGOÀI middleware của panel, nên giới hạn IP của admin
 * (M8 R7) không chặn nó — có chủ đích, manifest không chứa gì riêng tư. Còn các TRANG của panel thì
 * vẫn 404 với máy ngoài danh sách: manifest không mở cửa sau nào vào app nội bộ.
 */
it('leaves the public internal-app manifest outside the admin IP allowlist, while the panel pages stay closed', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);

    $outsider = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);

    $outsider->get('/admin/manifest.webmanifest')->assertOk()->assertJsonPath('scope', '/admin');
    $outsider->get('/admin/login')->assertNotFound();
});

it('refuses a panel that has no app on the phone instead of building a path for it', function () {
    expect(PwaPanels::IDS)->toBe(['admin', 'portal'])
        ->and(PwaPanels::path('admin'))->toBe(pwaPanelPath('admin'));

    PwaPanels::path('khach');
})->throws(InvalidArgumentException::class, 'Panel [khach] không có app trên điện thoại.');

/**
 * Tên miền riêng (SPEC §3, `ADMIN_DOMAIN`/`PORTAL_DOMAIN`). Route đăng ký lúc khởi động theo
 * `Panel::getDomains()`, nên test dựng lại CẢ ứng dụng với biến môi trường của tiến trình — đổi
 * `config()` trong thân test thì panel và route đã dựng xong, không kiểm được gì (cùng cách
 * `tests/Feature/Backup/BackupConfigTest.php` làm).
 */
it('serves each manifest only on its own panel domain when the domains are split', function () {
    $vars = ['ADMIN_DOMAIN' => 'quantri.luatvukhang.test', 'PORTAL_DOMAIN' => 'khachhang.luatvukhang.test'];
    $saved = [];

    foreach ($vars as $key => $value) {
        $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    Env::enablePutenv();

    try {
        $this->refreshApplication();

        // Tự kiểm: biến môi trường đã tới ứng dụng mới dựng, không thì test xanh giả.
        expect(Filament::getPanel('admin')->getDomains())->toBe(['quantri.luatvukhang.test']);

        $this->get('http://quantri.luatvukhang.test/admin/manifest.webmanifest')->assertOk()
            ->assertJsonPath('scope', '/admin');
        $this->get('http://khachhang.luatvukhang.test/portal/manifest.webmanifest')->assertOk()
            ->assertJsonPath('scope', '/portal');

        $this->get('http://khachhang.luatvukhang.test/admin/manifest.webmanifest')->assertNotFound();
        $this->get('http://quantri.luatvukhang.test/portal/manifest.webmanifest')->assertNotFound();
        $this->get('http://localhost/admin/manifest.webmanifest')->assertNotFound();

        // Thẻ `<link rel="manifest">` trỏ đúng tên miền của panel đang xem.
        $this->get('http://quantri.luatvukhang.test/admin/login')->assertOk()
            ->assertSee('<link rel="manifest" href="http://quantri.luatvukhang.test/admin/manifest.webmanifest">', escape: false);
    } finally {
        foreach ($saved as $key => [$env, $envConst, $server]) {
            $env === false ? putenv($key) : putenv("{$key}={$env}");

            if ($envConst === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envConst;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
        Env::enablePutenv();
    }
});

/**
 * Danh sách kiểm tra máy thật của chủ văn phòng (`docs/research/2026-10-01-pwa-kiem-tra-may-that.md`)
 * nói tên app mà hộp thoại cài sẽ hiện. Chrome hiện `name`, dưới biểu tượng là `short_name`, iOS
 * đề xuất `apple-mobile-web-app-title` (= `short_name`). Tên lệch thì chủ văn phòng ghi "KHÔNG ĐẠT"
 * oan — so với manifest thật, không với chữ viết lại.
 */
it('names the apps in the owner checklist exactly as the manifests do', function () {
    $checklist = str_replace("\r\n", "\n", (string) file_get_contents(base_path('docs/research/2026-10-01-pwa-kiem-tra-may-that.md')));
    $row = function (string $id) use ($checklist): string {
        expect(preg_match('/^\| '.$id.' \|.*$/m', $checklist, $match))->toBe(1, "thiếu dòng {$id}");

        return $match[0];
    };

    $admin = pwaManifest('admin');
    $portal = pwaManifest('portal');

    expect($row('A2'))->toContain('"'.$portal['short_name'].'"')
        ->and($row('A7'))->toContain('"'.$admin['short_name'].'"')
        ->and($row('C2'))->toContain('"'.$portal['name'].'"')
        ->and($row('C3'))->toContain('"'.$admin['name'].'"')
        ->and($row('C4'))->toContain('"'.$portal['short_name'].'"', '"'.$admin['short_name'].'"');
});
