<?php

use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
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

it('§10.10/R7 đã bị chặn cũng bị chặn ở request cập nhật Livewire (isPersistent)', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.1']);
    $user = User::factory()->create();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->actingAs($user, 'web')
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), ['components' => []]);

    $response->assertNotFound();
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
