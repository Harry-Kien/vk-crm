<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| R7, SPEC §3 và §10 mục 10 — giới hạn IP admin (kế hoạch M8 Task 1)
|--------------------------------------------------------------------------
*/

it('§10.10/R7 rỗng (mặc định) là TẮT — mọi IP vào được /admin', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/admin/login')
        ->assertOk();
});

it('§10.10/R7 có danh sách, IP không khớp nhận 404 (không phải 403)', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1,10.0.0.2']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/admin/login')
        ->assertNotFound();
});

it('§10.10/R7 có danh sách, IP khớp CHÍNH XÁC vào được', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1,203.0.113.50']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->get('/admin/login')
        ->assertOk();
});

it('§10.10/R7 khớp một dải CIDR', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '203.0.113.0/24']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
        ->get('/admin/login')
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
        ->get('/admin/login')
        ->assertNotFound();
});

it('§10.10/R7 bỏ khoảng trắng quanh mỗi phần tử và phần tử rỗng của danh sách', function () {
    config(['vkcrm.security.admin_ip_allowlist' => ' 10.0.0.1 , , 203.0.113.50 ']);

    expect(RestrictAdminIpAllowlist::entries())->toBe(['10.0.0.1', '203.0.113.50']);
});

/**
 * Fix round 1, finding 2 — bản cũ POST `['components' => []]` (rỗng): `Livewire\Mechanisms\
 * HandleRequests\HandleRequests::handleUpdate()` tự `abort(404)` trên payload rỗng đó ở bước phân
 * giải component, TRƯỚC khi tới sự kiện `snapshot-verified` — nơi middleware bền của
 * `PersistentMiddleware` mới thật sự chạy. Test cũ vì vậy xanh bất kể allowlist bật hay tắt, bất
 * kể `isPersistent` có mặt hay không (đo lại bằng probe review, xem ledger m8b) — không đo được
 * gì cả.
 *
 * Sửa bằng đúng khuôn `tests/Feature/Authorization/DenialCodeTest.php` (đọc docblock dài ở đó cho
 * lý do đầy đủ): render một trang admin THẬT bằng một tài khoản qua được allowlist, nhấc
 * `wire:snapshot` thật ra khỏi HTML, rồi POST snapshot đó tới `Livewire::getUpdateUri()` — một
 * request cập nhật KHÔNG rỗng, đi hết được tới bước middleware bền.
 *
 * MỖI TRƯỜNG HỢP MỘT `it()` RIÊNG (cùng lý do `DenialCodeTest`): `PersistentMiddleware::
 * applyPersistentMiddleware()` nhớ đã áp dụng cho `"{method}|{path}"` nào trong
 * `middlewareAppliedFor`, chỉ xoá ở sự kiện `flush-state` cuối một request thật — gộp hai POST
 * cùng đường dẫn vào một test thì POST thứ hai không chạy middleware bền nào và trả 200, một
 * xanh giả.
 *
 * Không dùng lại tên hàm của `DenialCodeTest.php` (`panelPageSnapshot`/`postPanelLivewireUpdate`/
 * `editClientSnapshot`) — cả hai tệp cùng nạp vào MỘT tiến trình PHP khi chạy cả bộ, và PHP không
 * cho khai báo lại hàm toàn cục trùng tên (fatal "Cannot redeclare").
 */
function adminIpAllowlistSnapshot(string $html, string $component): string
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

/** `Livewire::getUpdateUri()` vì đường dẫn thật mang tiền tố băm, không phải `/livewire/update`. */
function postAdminIpAllowlistLivewireUpdate(string $snapshot, string $remoteAddr): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);
}

/**
 * Snapshot THẬT của trang sửa khách hàng, lấy dưới một tài khoản admin, TỪ MỘT IP QUA ĐƯỢC
 * allowlist — nếu lấy snapshot cũng bị chặn thì test dưới đây đo nhầm thứ khác.
 */
function adminIpAllowlistEditClientSnapshot(Client $client, string $allowedRemoteAddr): string
{
    return adminIpAllowlistSnapshot(
        test()->withServerVariables(['REMOTE_ADDR' => $allowedRemoteAddr])
            ->get(ClientResource::getUrl('edit', ['record' => $client], panel: 'admin'))
            ->assertOk()
            ->getContent(),
        EditClient::class,
    );
}

it('§10.10/R7 đã bị chặn cũng bị chặn ở request cập nhật Livewire (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    Matter::factory()->for($client)->create();

    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);
    $this->actingAs($admin, 'web');

    $snapshot = adminIpAllowlistEditClientSnapshot($client, '10.0.0.1');

    $response = postAdminIpAllowlistLivewireUpdate($snapshot, '203.0.113.50');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain($client->name);
});

/**
 * Cặp dương của test trên: CÙNG snapshot thật, CÙNG tài khoản, đổi mỗi IP gọi — chứng minh
 * middleware không chỉ luôn luôn 404 (một cách khác một test có thể xanh giả), mà thật sự PHÂN
 * BIỆT theo IP ở đúng request cập nhật Livewire.
 */
it('§10.10/R7 IP nằm trong allowlist vẫn cập nhật Livewire bình thường (isPersistent)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    Matter::factory()->for($client)->create();

    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);
    $this->actingAs($admin, 'web');

    $snapshot = adminIpAllowlistEditClientSnapshot($client, '10.0.0.1');

    $response = postAdminIpAllowlistLivewireUpdate($snapshot, '10.0.0.1');

    $response->assertOk();
});

/**
 * IP đọc qua `$request->ip()` — phụ thuộc `TRUSTED_PROXIES`, cùng luật với mọi chỗ khác hỏi IP
 * thật của ai đang gọi (R1). Không tin proxy: allowlist thấy địa chỉ của proxy, không phải của
 * khách đứng sau nó.
 */
it('§10.10/R7 IP đọc qua proxy được tin, không đọc X-Forwarded-For khi proxy chưa được khai báo', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '203.0.113.9', 'trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/admin/login')
        ->assertNotFound();

    config(['trustedproxy.proxies' => '10.0.0.5']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('/admin/login')
        ->assertOk();
});

/**
 * Phạm vi CỐ Ý hẹp (phán quyết controller, brief Task 1): route tải tệp có chữ ký sống 5 phút
 * và chỉ sinh được BÊN TRONG panel — phủ luôn nó nghĩa là một link vừa mở trong văn phòng
 * không mở được ở nơi khác trong 5 phút còn lại. Ghim CẤU HÌNH thay vì dựng cả một tài liệu tải
 * xuống thật (cùng kiểu ghim với `DenialCodeTest`, test cuối tệp).
 */
it('§10.10/R7 KHÔNG phủ route tải tệp có chữ ký (documents.download)', function () {
    $middleware = Route::getRoutes()->getByName('documents.download')?->gatherMiddleware() ?? [];

    expect($middleware)->not->toContain(RestrictAdminIpAllowlist::class);
});

it('§10.10/R7 đăng ký trên panel admin, không đăng ký trên panel portal', function () {
    // "Bền" (isPersistent, tức phủ cả request cập nhật Livewire) đã được CHỨNG MINH bằng hành vi
    // thật ở test "đã bị chặn cũng bị chặn ở request cập nhật Livewire" ngay trên — `Panel` không
    // có phương thức đọc lại danh sách persistent để ghim cấu hình riêng như `getMiddleware()`.
    expect(Filament::getPanel('admin')->getMiddleware())->toContain(RestrictAdminIpAllowlist::class)
        ->and(Filament::getPanel('portal')->getMiddleware())->not->toContain(RestrictAdminIpAllowlist::class);
});
