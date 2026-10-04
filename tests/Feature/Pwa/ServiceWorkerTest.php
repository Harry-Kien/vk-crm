<?php

use App\Actions\Pwa\BuildServiceWorker;
use App\Actions\Pwa\RenderOfflinePage;
use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;
use App\Support\Security\ContentSecurityPolicy;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| M12 Task 3 — service worker và trang ngoại tuyến (kế hoạch M12, phán quyết R4)
|--------------------------------------------------------------------------
|
| Máy dev không có Node, nên JavaScript của service worker không có test tự động chạy nó. Bù lại
| (kế hoạch, "Ràng buộc toàn cục"): JS nhỏ, mọi hằng số quyết định hành vi (danh sách tiền tố được
| cache, phiên bản, scope, trang ngoại tuyến) được RENDER TỪ PHP và test ở đây trên văn bản phục vụ
| ra; hành vi thật đo bằng một lượt Playwright (`tools/pwa/survey-sw.cjs`, báo cáo Task 3).
|
| Luật nặng ký nhất (R4): không bao giờ lưu cái gì riêng tư. Văn bản `sw.js` chỉ có MỘT chỗ ghi
| cache (`cache.put`), nằm trong nhánh tài nguyên tĩnh; nhánh điều hướng chỉ đi mạng.
*/

/** Văn bản `sw.js` của một panel, kèm khẳng định 200. */
function pwaServiceWorker(string $panel): string
{
    return test()->get("/{$panel}/sw.js")->assertOk()->getContent();
}

/** Giá trị JSON của một hằng `const NAME = …;` trong văn bản `sw.js` (một dòng). */
function pwaSwConstant(string $script, string $name): mixed
{
    expect(preg_match('/^const '.$name.' = (.+);$/m', $script, $match))->toBe(1, "thiếu hằng {$name}");

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Thân của một hàm `function name(...) { ... }` hoặc `async function name(...)` trong văn bản
 * `sw.js` — tới dấu `}` đứng đầu dòng đầu tiên sau nó (hàm cấp cao nhất đóng ở cột 0).
 */
function pwaSwFunction(string $script, string $name): string
{
    expect(preg_match('/^(?:async )?function '.$name.'\(.*?^\}$/ms', $script, $match))->toBe(1, "thiếu hàm {$name}");

    return $match[0];
}

/** Thân trình nghe `self.addEventListener('<event>', …)` tới dấu `});` đứng đầu dòng. */
function pwaSwListener(string $script, string $event): string
{
    expect(preg_match("/^self\\.addEventListener\\('{$event}'.*?^\\}\\);$/ms", $script, $match))->toBe(1, "thiếu trình nghe {$event}");

    return $match[0];
}

/** Văn bản đã bỏ chú thích `//` và `/* … *\/` — test đọc mã không được đếm chữ trong chú thích. */
function pwaJsCode(string $script): string
{
    $script = (string) preg_replace('#/\*.*?\*/#s', '', $script);

    return (string) preg_replace('#^\s*//.*$#m', '', $script);
}

// ---------------------------------------------------------------------------------------------
// Header (R4, R2)
// ---------------------------------------------------------------------------------------------

/**
 * `SendSecurityHeaders` (M8 R4) là middleware TOÀN CỤC và đặt chính sách của TRANG sau
 * `$next()`; ở `local`/`testing` còn đặt dưới tên `…-Report-Only`. R4 đòi CSP riêng cho worker
 * (`default-src 'self'`), thi hành ở MỌI chế độ — kể cả `off`, công tắc khẩn của CSP trang: worker
 * không cần gì ngoài cùng origin, nên không có gì để công tắc đó cứu.
 */
it('serves each panel service worker as JavaScript with the R4 headers', function (string $panel, string $mode) {
    config(['vkcrm.security.csp_mode' => $mode]);

    $response = $this->get("/{$panel}/sw.js");

    $response->assertOk();
    $cacheControl = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

    expect($response->headers->get('Content-Type'))->toBe('application/javascript; charset=utf-8')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe(PwaPanels::path($panel))
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe("/{$panel}")
        ->and($cacheControl)->toContain('no-cache')
        ->and(implode(',', $cacheControl))->not->toContain('max-age')->not->toContain('immutable')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toBe(ContentSecurityPolicy::WORKER_POLICY)
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        // Ba header luôn bật của SPEC §10.2 vẫn có.
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with(['admin', 'portal'])->with(['report', 'enforce', 'off']);

/**
 * Cặp đối chứng của test trên: CÙNG middleware, trang thường vẫn nhận chính sách của trang — lối
 * thoát dành cho `sw.js` không nới CSP của trang nào khác.
 */
it('still gives the page policy to the panel pages next to the worker', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    $policy = $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($policy)->not->toBe(ContentSecurityPolicy::WORKER_POLICY)
        ->toContain("script-src 'self' 'nonce-");
});

/**
 * Lối thoát của middleware giữ nguyên MỌI response mang đúng chuỗi worker — và chuỗi đó thiếu ba
 * chỉ thị của trang không rơi về `default-src` (`frame-ancestors`, `form-action`, `base-uri`).
 * Vô hại với JavaScript của worker, không vô hại với một trang HTML. Lưới: ngoài định nghĩa và
 * middleware, chỉ `ServiceWorkerController` nhắc tới hằng đó trong mã (không tính chú thích).
 */
it('lets only the service worker controller send the worker policy', function () {
    $users = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains(pwaJsCode((string) file_get_contents($file->getPathname())), 'WORKER_POLICY')) {
            $users[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
        }
    }

    sort($users);

    expect($users)->toBe([
        'app/Http/Controllers/Pwa/ServiceWorkerController.php',
        'app/Http/Middleware/SendSecurityHeaders.php',
        'app/Support/Security/ContentSecurityPolicy.php',
    ]);
});

it('sets no cookie and starts no session row when the browser fetches the worker or the offline page', function (string $url) {
    config(['session.driver' => 'database']);

    $before = DB::table('sessions')->count();

    foreach (range(1, 3) as $attempt) {
        $response = $this->get($url)->assertOk();

        expect($response->headers->getCookies())->toBe([])
            ->and($response->headers->has('Set-Cookie'))->toBeFalse();
    }

    expect(DB::table('sessions')->count())->toBe($before);

    // Đối chứng: phép đếm thấy được một route có phiên.
    $this->get('/portal/login')->assertOk();
    expect(DB::table('sessions')->count())->toBeGreaterThan($before);
})->with(['/admin/sw.js', '/portal/sw.js', '/admin/offline', '/portal/offline']);

/**
 * M8 R7 + Task 2 vòng sửa 1: nhóm route PWA của `/admin` mang giới hạn IP như manifest. Máy lạ
 * không được biết app nội bộ có service worker ở `/admin` — và trang ngoại tuyến nội bộ mang tên
 * app nội bộ. Cổng khách mở cho mọi IP.
 */
it('answers 404 outside the admin allowlist for the internal worker and offline page, 200 inside, the portal ones stay open', function (string $file) {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);

    $outside = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get("/admin/{$file}");
    $outside->assertNotFound();
    expect($outside->headers->has('Service-Worker-Allowed'))->toBeFalse()
        ->and($outside->getContent())->not->toContain('vk-static-')
        // Cùng thứ tự với manifest (Task 2 vòng sửa 1): allowlist đứng trước mọi thứ có phiên —
        // 404 không đẻ cookie, giống một URL `/admin` không tồn tại.
        ->and($outside->headers->getCookies())->toBe([]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get("/admin/{$file}")->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get("/portal/{$file}")->assertOk();
})->with(['sw.js', 'offline']);

/**
 * Mẫu nginx (`tools/deploy/nginx.conf.example`) có location regex cho mọi đuôi `.js` trả thẳng tệp
 * tĩnh hoặc 404 — route PHP `/{panel}/sw.js` chỉ tới được PHP nhờ một khối `location =` riêng cho
 * ĐÚNG path của nó (đo thật bằng `tools/deploy/verify-pwa-routes.sh`, cả đối chứng 404). Đổi path
 * của một panel mà quên mẫu thì app không cài được trên máy chủ thật — lưới ở đây, vì Pest không
 * chạy nginx.
 */
it('keeps an exact nginx location for the worker route of each panel', function (string $panel) {
    $template = (string) file_get_contents(base_path('tools/deploy/nginx.conf.example'));
    $path = (string) parse_url(route("pwa.{$panel}.sw"), PHP_URL_PATH);

    expect($path)->toBe(PwaPanels::path($panel).'/sw.js')
        ->and(preg_match('#^\s*location = '.preg_quote($path, '#').' \{\s*\n\s*try_files \$uri /index\.php\?\$query_string;\s*\n\s*\}#m', $template))->toBe(1);
})->with(['admin', 'portal']);

// ---------------------------------------------------------------------------------------------
// Hằng số render từ PHP (R4)
// ---------------------------------------------------------------------------------------------

it('renders the static allow-list exactly as config says, and nothing else', function (string $panel) {
    expect(config('vkcrm.pwa.static_prefixes'))->toBe(['/css/filament/', '/js/filament/', '/fonts/filament/', '/brand/', '/pwa/'])
        ->and(pwaSwConstant(pwaServiceWorker($panel), 'STATIC_PREFIXES'))->toBe(config('vkcrm.pwa.static_prefixes'));
})->with(['admin', 'portal']);

/** Thêm một tiền tố là một thay đổi config có test, không phải một dòng JS (R4). */
it('follows a change of the config allow-list instead of a copy written in the script', function () {
    config(['vkcrm.pwa.static_prefixes' => ['/brand/', '/khac-thu/']]);

    expect(pwaSwConstant(pwaServiceWorker('portal'), 'STATIC_PREFIXES'))->toBe(['/brand/', '/khac-thu/']);
});

it('scopes each worker and its cache to its own panel', function (string $panel) {
    $script = pwaServiceWorker($panel);
    $version = pwaSwConstant($script, 'VERSION');

    expect(pwaSwConstant($script, 'SCOPE'))->toBe(PwaPanels::path($panel))
        ->and(pwaSwConstant($script, 'CACHE_PREFIX'))->toBe("vk-static-{$panel}-")
        ->and($version)->toMatch('/^[0-9a-f]{16}$/')
        ->and(pwaSwConstant($script, 'OFFLINE_URL'))->toBe("/{$panel}/offline");
})->with(['admin', 'portal']);

/**
 * R4: trang ngoại tuyến "được cài vào cache lúc install cùng biểu tượng và logo". Danh sách cài
 * sẵn là tài nguyên CÔNG KHAI — không trang đã đăng nhập nào — và mỗi mục là một tệp có thật (một
 * 404 làm `cache.addAll()` hỏng cả lượt cài).
 */
it('precaches its own offline page, the logo and the icon, all public', function (string $panel) {
    $precache = pwaSwConstant(pwaServiceWorker($panel), 'PRECACHE');

    expect($precache)->toBe([
        "/{$panel}/offline",
        '/'.RenderOfflinePage::LOGO,
        '/'.AppIcons::ANY[192],
    ]);

    $this->get($precache[0])->assertOk();
    foreach (array_slice($precache, 1) as $file) {
        expect(public_path(ltrim($file, '/')))->toBeFile();
    }
})->with(['admin', 'portal']);

// ---------------------------------------------------------------------------------------------
// VERSION (R4)
// ---------------------------------------------------------------------------------------------

/**
 * Mutation probe của kế hoạch: "gỡ phần băm của view thì test đỏ". Bản sao của view đặt ở một thư
 * mục đứng TRƯỚC `resources/views` trong bộ tìm view — trình duyệt nhận đúng view mới, và VERSION
 * phải đổi theo: không đổi thì một lần sửa `sw.js` không xoá bộ đệm cũ.
 */
it('changes VERSION when the service worker view changes', function () {
    $before = pwaSwConstant(pwaServiceWorker('portal'), 'VERSION');

    $dir = storage_path('framework/testing/pwa-view-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($dir.'/pwa');
    File::put($dir.'/pwa/sw-js.blade.php', File::get(resource_path('views/pwa/sw-js.blade.php'))."\n// sửa đổi thử\n");

    try {
        View::getFinder()->prependLocation($dir);
        View::getFinder()->flush();

        $script = pwaServiceWorker('portal');

        expect($script)->toContain('// sửa đổi thử')
            ->and(pwaSwConstant($script, 'VERSION'))->not->toBe($before);
    } finally {
        File::deleteDirectory($dir);
    }
});

it('changes VERSION when the allow-list changes', function () {
    $before = pwaSwConstant(pwaServiceWorker('admin'), 'VERSION');

    config(['vkcrm.pwa.static_prefixes' => [...config('vkcrm.pwa.static_prefixes'), '/khac-thu/']]);

    expect(pwaSwConstant(pwaServiceWorker('admin'), 'VERSION'))->not->toBe($before);
});

/**
 * Trang ngoại tuyến nằm trong cache từ lúc `install` và chỉ được cài lại khi VERSION đổi. Văn
 * phòng đổi số hotline (`BRAND_HOTLINE`) mà VERSION giữ nguyên thì app đã cài hiện số cũ khi mất
 * mạng — mãi mãi, tới lần nâng cấp Filament kế tiếp.
 */
it('changes VERSION when the offline page changes, so the precached copy is replaced', function () {
    $before = pwaSwConstant(pwaServiceWorker('portal'), 'VERSION');

    config(['vkcrm.brand.hotline' => '0900000001']);

    expect($this->get('/portal/offline')->getContent())->toContain('0900000001')
        ->and(pwaSwConstant(pwaServiceWorker('portal'), 'VERSION'))->not->toBe($before);
});

/**
 * Phiên bản Filament nằm trong VERSION (R4, "Những chỗ đã biết trước là sẽ cắn": tài nguyên tĩnh
 * của Filament mang `?v=` — nâng cấp Filament mà VERSION giữ nguyên để lại một bộ bản sao cũ).
 * Không giả được `InstalledVersions` qua HTTP (Composer đọc lại `installed.php` của vendor trước
 * mọi dữ liệu `reload()`), nên test gọi Action với hai số phiên bản; test HTTP ngay dưới khẳng
 * định controller đưa đúng phiên bản đã cài.
 */
it('changes VERSION with the Filament version', function () {
    $build = app(BuildServiceWorker::class);

    expect($build->handle('portal', '/portal', 'v5.0.0')['version'])
        ->not->toBe($build->handle('portal', '/portal', 'v5.0.1')['version']);
});

it('builds the served worker from the installed Filament version', function () {
    $installed = (string) InstalledVersions::getPrettyVersion('filament/filament');

    expect($installed)->not->toBe('')
        ->and(pwaSwConstant(pwaServiceWorker('admin'), 'VERSION'))
        ->toBe(app(BuildServiceWorker::class)->handle('admin', '/admin', $installed)['version']);
});

/** `sw.js` là tệp công khai: VERSION không được là một hàm của bí mật nào (R4). */
it('keeps VERSION independent of APP_KEY', function () {
    $before = pwaSwConstant(pwaServiceWorker('admin'), 'VERSION');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(pwaSwConstant(pwaServiceWorker('admin'), 'VERSION'))->toBe($before);
});

// ---------------------------------------------------------------------------------------------
// Đọc mã (R4) — tiền lệ M5 và M6.5 Task 11
// ---------------------------------------------------------------------------------------------

/**
 * Chỗ DUY NHẤT ghi cache lúc chạy là `cache.put` trong nhánh tài nguyên tĩnh; lượt cài
 * (`install`) dùng `addAll(PRECACHE)` với danh sách công khai ở trên. Không `cache.add(` nào khác,
 * không `put` nào ở nhánh điều hướng.
 */
it('writes the cache in exactly one place, inside the static branch', function (string $panel) {
    $code = pwaJsCode(pwaServiceWorker($panel));

    expect(substr_count($code, '.put('))->toBe(1)
        ->and(substr_count($code, 'cache.put('))->toBe(1)
        ->and(pwaJsCode(pwaSwFunction($code, 'fromStaticCache')))->toContain('cache.put(')
        ->and(substr_count($code, '.add('))->toBe(0)
        ->and(substr_count($code, '.addAll('))->toBe(1)
        ->and(pwaSwListener($code, 'install'))->toContain('.addAll(PRECACHE')
        ->and(pwaSwFunction($code, 'fromNetwork'))->not->toContain('.put(')->not->toContain('caches.open');
})->with(['admin', 'portal']);

/** Chỉ lưu response `ok` và `type === 'basic'` (cùng origin, không mờ). */
it('stores only ok same-origin responses in the static branch', function () {
    $static = pwaJsCode(pwaSwFunction(pwaServiceWorker('portal'), 'fromStaticCache'));

    expect($static)->toMatch("/if \\(response\\.ok && response\\.type === 'basic'\\) \\{\\s*\\n\\s*.*cache\\.put\\(/");
});

/**
 * "`respondWith` bắt nhầm một request `POST` là Livewire hỏng theo cách khó thấy" (kế hoạch): luật
 * "chỉ GET cùng origin" là hai câu lệnh ĐẦU của trình xử lý `fetch`, trước mọi `respondWith`.
 */
it('opens the fetch handler with the same-origin GET rule, before any respondWith', function () {
    $listener = pwaJsCode(pwaSwListener(pwaServiceWorker('admin'), 'fetch'));
    $statements = array_values(array_filter(array_map('trim', explode("\n", $listener)), fn (string $line): bool => $line !== ''));

    expect($statements[1])->toBe('const request = event.request;')
        ->and($statements[2])->toBe("if (request.method !== 'GET') return;")
        ->and($statements[3])->toBe('const url = new URL(request.url);')
        ->and($statements[4])->toBe('if (url.origin !== self.location.origin) return;')
        ->and(strpos($listener, 'respondWith'))->toBeGreaterThan(strpos($listener, 'url.origin !== self.location.origin'));
});

/**
 * Điều hướng: chỉ mạng (kể cả `preloadResponse`), lỗi mạng thì trang ngoại tuyến. Một response
 * đã tới — kể cả tệp `attachment` của route tải bí danh trong scope — đi nguyên vẹn; trang ngoại
 * tuyến chỉ thay một LỖI MẠNG (`fetch` từ chối), không thay một response lỗi.
 */
it('answers navigations from the network only and falls back to the offline page on a network error', function () {
    $code = pwaJsCode(pwaServiceWorker('portal'));
    $network = pwaSwFunction($code, 'fromNetwork');

    expect(pwaSwListener($code, 'fetch'))->toContain("request.mode === 'navigate'")
        ->and($network)->toContain('await event.preloadResponse')
        ->and($network)->toContain('await fetch(event.request)')
        ->and($network)->toContain('catch')
        ->and($network)->toContain('OFFLINE_URL')
        ->and($network)->not->toContain('response.ok')
        ->and($network)->not->toContain('status');
});

it('activates by deleting only its own older caches, then claims its clients', function () {
    $activate = pwaJsCode(pwaSwListener(pwaServiceWorker('admin'), 'activate'));

    expect($activate)->toContain('name.startsWith(CACHE_PREFIX) && name !== CACHE')
        ->toContain('caches.delete(name)')
        ->toContain('navigationPreload.enable()')
        ->toContain('self.clients.claim()')
        ->and(pwaJsCode(pwaSwListener(pwaServiceWorker('admin'), 'install')))->toContain('self.skipWaiting()');
});

/** Kế hoạch: service worker dưới 150 dòng (JS không có test chạy — phải đọc được trong một lần). */
it('keeps the served worker under 150 lines', function (string $panel) {
    expect(substr_count(pwaServiceWorker($panel), "\n"))->toBeLessThan(150);
})->with(['admin', 'portal']);

// ---------------------------------------------------------------------------------------------
// Trang ngoại tuyến (R4)
// ---------------------------------------------------------------------------------------------

/**
 * `flushState()` trước request: Livewire chèn script của nó vào MỌI response HTML 200 khi cờ TĨNH
 * `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` đang bật, và cờ đó chỉ được hạ ở
 * `Livewire::flushState()` — không ở đầu một request HTTP của test. Một test trước đó trong cùng
 * tiến trình đã vẽ một component (trang đăng nhập) để lại cờ bật, và trang ngoại tuyến nhận
 * `<script src="…/livewire.js">` mà nó không hề vẽ (đo được khi chạy cả tệp). Trên máy chủ thật
 * mỗi request bắt đầu với trạng thái tĩnh sạch (PHP-FPM không chia sẻ biến giữa các request), và
 * route ngoại tuyến không vẽ component nào; lượt Playwright của Task 3 đọc lại trang thật.
 */
it('serves a static Vietnamese offline page with the hotline and a retry link to start_url', function (string $panel) {
    config(['vkcrm.brand.hotline' => '0832 270 898']);
    app('livewire')->flushState();

    $response = $this->get("/{$panel}/offline");
    $html = $response->assertOk()->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($html)->toContain('<html lang="vi">')
        ->toContain(e(__('pwa.offline.heading')))
        ->toContain('Chưa có kết nối mạng')
        ->toContain('href="tel:0832270898"')
        ->toContain('0832 270 898')
        ->toContain('href="'.PwaPanels::path($panel).'"')
        ->toContain(e(__('pwa.offline.retry')))
        ->toContain(asset(RenderOfflinePage::LOGO))
        ->not->toContain('<script')
        ->not->toContain('csrf-token')
        ->not->toContain('livewire');
})->with(['admin', 'portal']);

// ---------------------------------------------------------------------------------------------
// Thông báo đẩy (M12 Task 7, R11)
// ---------------------------------------------------------------------------------------------

/**
 * R11 + R5: trình nghe `push` hiện ĐÚNG nội dung máy chủ đã dựng (`App\Enums\PushTopic`), chỉ trong
 * `event.waitUntil(showNotification(…))` — không ghi bộ đệm nào. Tiêu đề, câu dự phòng và biểu tượng
 * là hằng render từ PHP (tên văn phòng, câu tiếng Việt, cùng hai tệp mà payload thường mang): trình
 * duyệt BẮT BUỘC hiện một thông báo cho mỗi lần đẩy, và câu mặc định của Chrome là tiếng Anh. URL đích
 * qua `inScope()`, không thì về trang chính của app (`SCOPE`).
 *
 * Mutation probe: bỏ `inScope(…) ||` (dùng thẳng `payload.data.url`) → ĐỎ.
 */
it('shows the server-built notification on push, with Vietnamese fallbacks rendered from PHP', function (string $panel) {
    $script = pwaServiceWorker($panel);
    $push = pwaJsCode(pwaSwListener($script, 'push'));

    expect(pwaSwConstant($script, 'PUSH_TITLE'))->toBe(config('vkcrm.brand.short_name'))
        ->and(pwaSwConstant($script, 'PUSH_BODY'))->toBe(__('push.service_worker.fallback'))
        ->and(pwaSwConstant($script, 'PUSH_ICON'))->toBe('/'.AppIcons::ANY[192])
        ->and(pwaSwConstant($script, 'PUSH_BADGE'))->toBe('/'.AppIcons::BADGE)
        ->and($push)->toContain('event.waitUntil(self.registration.showNotification(payload.title || PUSH_TITLE, {')
        ->toContain('body: payload.body || PUSH_BODY')
        ->toContain('const url = inScope(payload.data && payload.data.url) || SCOPE;')
        ->toContain('data: { url }')
        ->not->toContain('caches')
        ->not->toContain('fetch(');
})->with(['admin', 'portal']);

/**
 * Task 9 vòng sửa 1 (I1): `tag` = chủ đề + id bản ghi (R11, `App\Enums\PushTopic::message()`), nên CÙNG
 * một bản ghi được đẩy nhiều lần dưới một `tag` — bốn bậc của một mốc hạn (d7 → d3 → d1 → quá hạn),
 * câu hỏi tiếp của khách (`REQ-2`) thay tin yêu cầu mới của cùng luồng, đợt thu quá hạn 7 ngày một
 * lần. Theo Notifications API (Chrome làm đúng vậy), thông báo thay một thông báo cùng `tag` CÒN ĐANG
 * HIỆN thì hiện IM LẶNG — không chuông, không rung — trừ khi `renotify: true`; `urgency = high` chỉ là
 * gợi ý giao nhận cho máy chủ push, không làm máy báo. `renotify` đi đúng theo điều kiện có `tag`:
 * `renotify: true` mà không có `tag` thì `showNotification` ném `TypeError`, và thông báo của văn phòng
 * cho lần đẩy đó không hiện.
 *
 * Mutation probe (báo cáo Task 9, vòng sửa 1): bỏ dòng `renotify` → ĐỎ; `renotify: true` → ĐỎ.
 */
it('alerts again when a push replaces a notification of the same tag still on screen', function (string $panel) {
    $push = pwaJsCode(pwaSwListener(pwaServiceWorker($panel), 'push'));

    expect($push)->toContain('tag: payload.tag || undefined,')
        ->toContain('renotify: Boolean(payload.tag),')
        ->and(substr_count($push, 'renotify'))->toBe(1);
})->with(['admin', 'portal']);

/**
 * R11: "`notificationclick` chỉ mở URL cùng origin và nằm trong scope của chính nó; URL khác bị bỏ
 * qua" — khớp scope theo ĐOẠN (`/portal` hay `/portal/…`; `/portalx` không), cùng luật
 * `PwaPanels::startUrlFor()`. "Nếu đã có cửa sổ app thì `focus()` rồi `navigate()`, không mở cửa sổ
 * thứ hai"; `navigate()` hỏng (cửa sổ không do worker này điều khiển) thì mới mở cửa sổ mới.
 *
 * Mutation probe: bỏ vế `url.origin === self.location.origin` (hay vế khớp đoạn `SCOPE + '/'`) khỏi
 * `inScope()`; bỏ `if (!url) return;` → ĐỎ.
 */
it('opens only same-origin URLs inside its own scope on a tap, reusing an open app window', function () {
    $script = pwaServiceWorker('portal');
    $click = pwaJsCode(pwaSwListener($script, 'notificationclick'));
    $inScope = pwaJsCode(pwaSwFunction($script, 'inScope'));
    $open = pwaJsCode(pwaSwFunction($script, 'openInApp'));

    expect($click)->toContain('event.notification.close();')
        ->toContain('const url = inScope(event.notification.data && event.notification.data.url);')
        ->toContain('if (!url) return;')
        ->toContain('event.waitUntil(openInApp(url));')
        ->and($inScope)->toContain('const url = new URL(raw, self.location.origin);')
        ->toContain("const inside = url.pathname === SCOPE || url.pathname.startsWith(SCOPE + '/');")
        ->toContain('return url.origin === self.location.origin && inside ? url.href : null;')
        ->and($open)->toContain("const windows = await self.clients.matchAll({ type: 'window' });")
        ->toContain('const open = windows.find((client) => inScope(client.url));')
        ->toContain('if (!open) return self.clients.openWindow(url);')
        ->toContain('await open.focus();')
        ->toContain('return open.navigate(url).catch(() => self.clients.openWindow(url));');
});
