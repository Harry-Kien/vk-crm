<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Http\Controllers\DocumentDownloadController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| R2, §10.7 — quét đường tắt của 2FA bắt buộc, cùng lối M5 đã dùng cho
| ClientUser::toggleEmailAuthentication() (Route::has, không cột nào tắt được).
|--------------------------------------------------------------------------
| (a)/(b) — trang hồ sơ không có nút tắt: tests/Feature/Filament/StaffEditProfileTest.php.
| (c) — không lời gọi saveAppAuthenticationSecret(null) nào ngoài ResetStaffTwoFactor: dưới đây.
| (d) — request cập nhật Livewire của người vừa mất 2FA: dưới đây.
| (e) — route NGOÀI hai panel mà guard `web` dùng được: dưới đây.
*/

/**
 * Quét `app/` bằng chuỗi ĐÚNG DẠNG LỜI GỌI (`->saveAppAuthenticationSecret(`), không phải tên
 * phương thức trần — tên trần khớp luôn cả chữ ký hàm định nghĩa nó trên `App\Models\User`
 * (`public function saveAppAuthenticationSecret(...)`), thứ không phải một "lời gọi".
 *
 * @return list<string> đường dẫn tương đối các tệp có gọi (không phải định nghĩa)
 */
function filesCallingSaveAppAuthenticationSecret(): array
{
    $matches = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());

        if (str_contains($content, '->saveAppAuthenticationSecret(')) {
            $matches[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $matches;
}

/** Cặp đôi cho cột mã khôi phục — cùng lý do, cùng cách quét. */
function filesCallingSaveAppAuthenticationRecoveryCodes(): array
{
    $matches = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());

        if (str_contains($content, '->saveAppAuthenticationRecoveryCodes(')) {
            $matches[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    return $matches;
}

it('§10.7 tiền đề của phép quét: tìm được ít nhất một lời gọi thật (không rỗng vô nghĩa)', function () {
    expect(filesCallingSaveAppAuthenticationSecret())->not->toBeEmpty()
        ->and(filesCallingSaveAppAuthenticationRecoveryCodes())->not->toBeEmpty();
});

it('§10.7 không lời gọi saveAppAuthenticationSecret(null)/…RecoveryCodes(null) nào ngoài ResetStaffTwoFactor', function () {
    expect(filesCallingSaveAppAuthenticationSecret())
        ->toBe(['Actions'.DIRECTORY_SEPARATOR.'User'.DIRECTORY_SEPARATOR.'ResetStaffTwoFactor.php']);

    expect(filesCallingSaveAppAuthenticationRecoveryCodes())
        ->toBe(['Actions'.DIRECTORY_SEPARATOR.'User'.DIRECTORY_SEPARATOR.'ResetStaffTwoFactor.php']);
});

/*
|--------------------------------------------------------------------------
| (d) — /livewire/update của một người vừa mất 2FA (đặt lại, hay bị đổi CSDL trực tiếp)
|--------------------------------------------------------------------------
| Cùng khuôn `tests/Feature/Panels/AdminIpAllowlistTest.php` (đọc docblock dài ở đó cho lý do đầy
| đủ về "vacuous test" nếu POST payload rỗng): lấy `wire:snapshot` THẬT từ một trang admin đã
| render, rồi POST snapshot đó tới `Livewire::getUpdateUri()`.
*/

function twoFactorGapSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Không có snapshot Livewire của [{$component}] trong HTML đã render.");
}

function postTwoFactorGapLivewireUpdate(string $snapshot): TestResponse
{
    return test()
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);
}

/**
 * `EnsureMultiFactorAuthenticationIsEnabled` (Filament, đăng ký per-trang qua `isRequired: true`)
 * chỉ chặn LẦN TẢI TRANG ĐẦY ĐỦ theo mặc định — nó KHÔNG có trong danh sách middleware BỀN mặc
 * định của Filament (`FilamentServiceProvider::packageBooted()`). `AdminPanelProvider` đăng ký nó
 * BỀN riêng (`->persistentMiddleware([...])`) để lấp đúng khoảng trống này — xem docblock ở đó
 * cho lý lẽ đầy đủ. Test này đo HÀNH VI, không đo cấu hình: snapshot lấy TRƯỚC khi 2FA bị xoá
 * (trang tải được bình thường lúc đó), xoá secret sau, rồi POST snapshot CŨ — mô phỏng đúng tình
 * huống "tab trình duyệt đang mở sẵn khi admin khác bấm Đặt lại 2FA" mà
 * `App\Actions\User\ResetStaffTwoFactor` docblock nói tới.
 */
it('§10.7 chặn request cập nhật Livewire của người vừa mất secret 2FA (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $snapshot = twoFactorGapSnapshot(
        $this->get(UserResource::getUrl('edit', ['record' => $other], panel: 'admin'))->assertOk()->getContent(),
        EditUser::class,
    );

    $admin->forceFill(['two_factor_secret' => null])->save();
    $this->actingAs($admin->fresh(), 'web');

    $response = postTwoFactorGapLivewireUpdate($snapshot);

    Filament::setCurrentPanel('admin');
    $response->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());

    // Vẫn còn đăng nhập — bị dồn về trang cài đặt, không bị đăng xuất (khác luật §10.9 của
    // client bị vô hiệu hoá, nơi đăng xuất mới là hành vi đúng).
    expect(auth('web')->check())->toBeTrue();
});

/**
 * Cặp dương: CÙNG snapshot thật, CÙNG tài khoản, KHÔNG xoá secret — chứng minh middleware không
 * chỉ luôn luôn chuyển hướng (một cách khác một test có thể xanh giả), mà thật sự phân biệt theo
 * trạng thái 2FA ở đúng request cập nhật Livewire.
 */
it('§10.7 người còn 2FA vẫn cập nhật Livewire bình thường (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $other = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $snapshot = twoFactorGapSnapshot(
        $this->get(UserResource::getUrl('edit', ['record' => $other], panel: 'admin'))->assertOk()->getContent(),
        EditUser::class,
    );

    postTwoFactorGapLivewireUpdate($snapshot)->assertOk();
});

/*
|--------------------------------------------------------------------------
| (e) — route NGOÀI panel admin mà một phiên `web` chưa cài 2FA dùng được
|--------------------------------------------------------------------------
| Bản đầu của phép quét này lọc bỏ mọi tên `filament.*` và mọi route không tên — nên nó không
| thấy `filament.exports.download`, `filament.imports.failed-rows.download` (cả hai chỉ có
| middleware `filament.actions`, tức `web`, và KHÔNG có cổng 2FA) và các route Livewire không tên.
| Bản này duyệt TOÀN BỘ router, không lọc theo tên, và đòi mỗi route thuộc đúng một nhóm:
|
|   1. route của panel `admin`  → mang `EnsureMultiFactorAuthenticationIsEnabled`, trừ ba route
|                                   không thể mang nó (đăng nhập, đăng xuất, chính trang cài đặt);
|   2. route của panel `portal` → guard `client`, không phải `web` — một phiên nhân sự không
|                                   đứng được ở đó;
|   3. route còn lại            → PHẢI có mặt trong danh sách dưới đây kèm lý lẽ, và mỗi lý lẽ có
|                                   một test hành vi/cấu hình riêng phía sau.
|
| Thêm một route mới ngoài panel làm test này đỏ và buộc người thêm tới đây viết lý lẽ.
*/

/** Đưa tiền tố băm ngẫu nhiên của Livewire (`livewire-ba5adf96`) về một dạng cố định. */
function normalizedRouteKey(Illuminate\Routing\Route $route): string
{
    $uri = (string) preg_replace('/^livewire-[0-9a-f]+/', 'livewire-{hash}', $route->uri());

    return implode('|', array_diff($route->methods(), ['HEAD'])).' '.$uri;
}

/**
 * Mọi route ngoài hai panel, theo khoá `METHOD uri`, kèm lý do vì sao một phiên `web` chưa cài
 * 2FA không lấy được gì ở đó.
 *
 * @return array<string, string>
 */
function outsidePanelRouteReasons(): array
{
    $static = 'tài nguyên tĩnh của Livewire — không có dữ liệu người dùng nào để lấy';

    return [
        'GET|POST|PUT|PATCH|DELETE|OPTIONS /' => 'chuyển hướng cố định sang /portal, không dữ liệu',
        'GET up' => 'kiểm tra sống của máy chủ, không dữ liệu',
        'GET documents/{document}/download' => 'chữ ký gắn người nhận + `DocumentDownloadController::actor()` đòi 2FA (DocumentDownloadTest §10.7)',
        'GET filament/exports/{export}/download' => 'không có Exporter trong `app/` và không có bảng `exports` nên không có gì để tải (test bên dưới)',
        'GET filament/imports/{import}/failed-rows/download' => 'không có Importer trong `app/` và không có bảng `imports` nên không có gì để tải (test bên dưới)',
        'GET livewire-{hash}/preview-file/{filename}' => 'đòi chữ ký tương đối hợp lệ, không sinh được từ trang panel bị chặn (test bên dưới)',
        'POST livewire-{hash}/upload-file' => 'không đòi xác thực nào: khách vãng lai gọi được y hệt, chỉ ghi một tệp tạm chưa gắn vào bản ghi nào (test bên dưới)',
        'POST livewire-{hash}/update' => 'cổng 2FA bền riêng (`persistentMiddleware`) — hai test §10.7 ở trên',
        'GET livewire-{hash}/livewire.js' => $static,
        'GET livewire-{hash}/livewire.min.js.map' => $static,
        'GET livewire-{hash}/livewire.csp.min.js.map' => $static,
        'GET livewire-{hash}/js/{component}.js' => $static,
        'GET livewire-{hash}/css/{component}.css' => $static,
        'GET livewire-{hash}/css/{component}.global.css' => $static,

        // M11 Task 1 — máy chủ MCP (`routes/ai.php`, ngoài nhóm `web`). Người sở hữu token đã qua
        // 2FA lúc đồng ý (Task 4); bản thân `/mcp` không đọc phiên.
        'POST mcp' => 'chỉ bearer Passport, không phiên, không cookie: `RequireBearerToken` xoá cookie laravel_token trước `auth:mcp` và chặn bearer trống, nên phiên /admin, phiên cổng khách, và cookie laravel_token kèm CSRF đúng (không bearer, hoặc `Bearer 0`, `Bearer ,`, bearer chỉ khoảng trắng) đều 401 (TransportTest, OAuthRoutesStaffSessionTest)',
        'GET mcp' => '405 cố định, `Allow: POST`, không dữ liệu (TransportTest)',
        'DELETE mcp' => '405 cố định, `Allow: POST`, không dữ liệu (TransportTest)',
        // M11 Task 1 — route của Passport. Device code và route JSON quản lý client/token TẮT
        // (OAuthServerHardeningTest), nên không có ở đây.
        'POST oauth/token' => 'không đọc phiên: chỉ đổi mã uỷ quyền/refresh token (`RestrictOAuthGrantTypes`), cả hai không sinh được từ một phiên chưa cài 2FA (OAuthRoutesStaffSessionTest, OAuthServerHardeningTest)',
        'GET oauth/authorize' => 'Task 1: chưa có màn hình đồng ý nên không cấp được mã nào, kể cả khi đã có token còn hạn (OAuthRoutesStaffSessionTest). Task 4 thay lý lẽ này bằng cổng 2FA của màn hình đồng ý',
        'POST oauth/authorize' => 'Task 1: chỉ duyệt yêu cầu mà GET oauth/authorize đã lưu vào phiên, thứ chưa lưu được gì (OAuthRoutesStaffSessionTest). Task 4 thay lý lẽ này',
        'DELETE oauth/authorize' => 'từ chối một yêu cầu uỷ quyền: không bao giờ cấp mã (OAuthRoutesStaffSessionTest)',
        'POST oauth/token/refresh' => 'phát cookie laravel_token, và không route nào nhận cookie đó: guard passport duy nhất là `mcp`, chỉ đứng sau /mcp, nơi `RequireBearerToken` xoá cookie trước `auth:mcp`; cookie này kèm CSRF của chính phiên, có hay không kèm `Bearer 0` / `Bearer ,` / bearer chỉ khoảng trắng, vẫn 401 (OAuthRoutesStaffSessionTest)',
    ];
}

it('§10.7 mọi route của router thuộc đúng một nhóm: panel admin có cổng 2FA, panel portal (guard `client`), hoặc có lý lẽ', function () {
    $exemptAdmin = [
        'filament.admin.auth.login',
        'filament.admin.auth.logout',
        'filament.admin.auth.multi-factor-authentication.set-up-required',
    ];

    $unaccounted = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $key = normalizedRouteKey($route);

        if (str_starts_with($name, 'filament.admin.')) {
            $hasGate = in_array(EnsureMultiFactorAuthenticationIsEnabled::class, $route->gatherMiddleware(), true);

            if (! $hasGate && ! in_array($name, $exemptAdmin, true)) {
                $unaccounted[] = "{$name}: route panel admin thiếu EnsureMultiFactorAuthenticationIsEnabled";
            }

            continue;
        }

        if (str_starts_with($name, 'filament.portal.')) {
            continue;
        }

        if (! array_key_exists($key, outsidePanelRouteReasons())) {
            $unaccounted[] = "{$key} ({$name}): route ngoài panel chưa có lý lẽ 2FA";
        }
    }

    expect($unaccounted)->toBe([]);

    // Chiều ngược: danh sách lý lẽ không được chứa route đã biến mất (một dòng chết là một lý lẽ
    // không còn ai đọc lại).
    $present = collect(Route::getRoutes())->map(fn ($route) => normalizedRouteKey($route))->all();

    expect(array_diff(array_keys(outsidePanelRouteReasons()), $present))->toBe([]);
});

it('§10.7 route panel portal chạy bằng guard `client`, một phiên nhân sự không đứng được ở đó', function () {
    expect(Filament::getPanel('portal')->getAuthGuard())->toBe('client')
        ->and(Filament::getPanel('admin')->getAuthGuard())->toBe('web');

    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->withRole(Role::Admin)->create();

    // Một phiên `web` (nhân sự) không mở được cổng khách: bị chuyển tới trang đăng nhập của nó.
    $this->actingAs($staff, 'web')->get('/portal')->assertRedirect();

    expect(auth('client')->check())->toBeFalse();
});

it('§10.7 route tải bản xuất/nhập của Filament không phục vụ được gì: app/ không có Exporter/Importer và CSDL không có bảng exports/imports', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $code = (string) file_get_contents($file->getPathname());

            $definesExporterOrImporter = preg_match('/extends\s+(Exporter|Importer)\b/', $code) === 1
                || str_contains($code, 'Filament\Actions\Exports\Exporter')
                || str_contains($code, 'Filament\Actions\Imports\Importer');

            expect($definesExporterOrImporter)->toBeFalse($file->getPathname().' định nghĩa một Exporter/Importer');
        }
    }

    // Hai route đó đọc `exports`/`imports` theo id; không có migration nào tạo hai bảng ấy nên
    // không có dòng nào để một phiên chưa cài 2FA tải. Thêm tính năng xuất/nhập sau này (kèm
    // migration) làm test này đỏ và buộc phải thêm cổng 2FA cho hai route.
    expect(Schema::hasTable('exports'))->toBeFalse()
        ->and(Schema::hasTable('imports'))->toBeFalse()
        ->and(Schema::hasTable('failed_import_rows'))->toBeFalse();
});

it('§10.7 livewire.preview-file đòi chữ ký: người chưa cài 2FA không có chữ ký nào để dùng', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->withRole(Role::Admin)->withoutTwoFactor()->create();

    $this->actingAs($staff, 'web')
        ->get(route('livewire.preview-file', ['filename' => 'bat-ky.pdf']))
        ->assertUnauthorized();
});

/**
 * `documents.download` — route này nằm NGOÀI panel, nên cổng 2FA của Filament không đứng trước
 * nó (`RestrictAdminIpAllowlist` cũng không phủ nó — cùng lý lẽ, Task 1). URL sống 5 phút, ký bởi
 * `Document::downloadUrlFor()` và khoá cứng người nhận. Bản đầu của test này viết rằng một admin
 * chưa cài 2FA "không tự sinh được chữ ký" — đúng cho một chữ ký MỚI, nhưng bỏ sót đường thật:
 * chữ ký sinh TRƯỚC khi bị "Đặt lại 2FA" còn hạn tới 5 phút, và đăng nhập lại bằng mật khẩu cho
 * một phiên `web` mới. Đường đó được đóng bởi `DocumentDownloadController::actor()` (nhân sự
 * không có secret 2FA → 404) và ghim bằng hành vi ở `tests/Feature/Http/DocumentDownloadTest.php`
 * ("§10.7 nhân sự chưa cài 2FA … không tải được"). Test dưới đây chỉ ghim phần CẤU HÌNH của route
 * (đòi chữ ký), cùng cách `AdminIpAllowlistTest` đã làm.
 */
it('§10.7 documents.download đòi chữ ký ở middleware của route (cổng 2FA nằm trong controller, không ở đây)', function () {
    $middleware = Route::getRoutes()->getByName('documents.download')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('signed')
        ->and(class_exists(DocumentDownloadController::class))->toBeTrue();
});

/**
 * `livewire.upload-file`/`livewire.preview-file` — middleware của Livewire, KHÔNG có `auth` nào
 * (đọc `route:list -v`: chỉ `web` + `throttle`). Một người 2FA-chưa-cài không có gì MỚI để lấy ở
 * đây so với một khách vãng lai chưa đăng nhập — cả hai đều gọi được, cả hai đều chỉ chạm tới một
 * tệp TẠM chưa gắn vào bất cứ bản ghi nào (phải qua một component Livewire cụ thể mới gắn được,
 * và mọi trang panel admin đã bị chặn trước đó). Không có 2FA nào để mà thiếu ở đây — route này
 * không phân biệt được ai đang gọi.
 */
it('§10.7 livewire.upload-file/preview-file không đòi xác thực nào — 2FA không phải thứ thiếu ở đây', function () {
    $uploadMiddleware = Route::getRoutes()->getByName('livewire.upload-file')?->gatherMiddleware() ?? [];
    $previewMiddleware = Route::getRoutes()->getByName('livewire.preview-file')?->gatherMiddleware() ?? [];

    foreach ([$uploadMiddleware, $previewMiddleware] as $middleware) {
        expect($middleware)->not->toContain('auth')
            ->and(collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'auth:')))->toBeFalse();
    }
});
