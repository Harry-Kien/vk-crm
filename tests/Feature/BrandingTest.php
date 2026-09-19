<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Nhận diện thương hiệu của văn phòng phải có mặt ở CẢ hai panel, không chỉ ở trang đăng nhập
 * mà tôi tình cờ mở ra xem. Đây là thứ rất dễ mất im lặng: một lần nâng cấp Filament đổi tên
 * render hook, một lần đổi `config/vkcrm.php`, hay một `brandLogo()` bị xoá khi sửa panel — và
 * hệ thống lặng lẽ quay về giao diện mặc định trắng trơn của framework.
 */
it('shows the firm identity on the internal login screen', function () {
    $response = $this->get('/admin/login');

    $response->assertOk()
        ->assertSee(config('vkcrm.brand.lockup.name'))
        ->assertSee(config('vkcrm.brand.tagline'), escape: false)
        ->assertSee(config('vkcrm.brand.legal_name'))
        ->assertSee(config('vkcrm.brand.hotline'))
        ->assertSee('brand/vk-mark-64.png', escape: false);
});

it('shows the firm identity on the client portal login screen', function () {
    $response = $this->get('/portal/login');

    $response->assertOk()
        ->assertSee(config('vkcrm.brand.lockup.name'))
        ->assertSee(config('vkcrm.brand.tagline'), escape: false)
        ->assertSee(config('vkcrm.brand.legal_name'));
});

it('carries the logo into the internal panel once signed in', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web')
        ->get('/admin')
        ->assertOk()
        ->assertSee(config('vkcrm.brand.lockup.suffix'))
        ->assertSee(config('vkcrm.brand.lockup.entity'));
});

it('keeps the portal primary colour at the firm navy rather than a generated ramp', function () {
    // Sắc độ 950 phải đúng bằng --navy của luatvukhang.com; sắc độ 600 (nút bấm chính) phải TỐI
    // hơn đường cong mặc định của Filament (L 0.598), nếu không nút sẽ là xanh sáng chứ không
    // phải navy trầm của văn phòng.
    $ramp = config('vkcrm.brand.primary_ramp');

    expect($ramp[950])->toBe('oklch(0.233 0.050 261.7)');

    [$lightness] = sscanf($ramp[600], 'oklch(%f');
    expect($lightness)->toBeLessThan(0.5);
});

/**
 * I-4 (fix round 4). `->font(config('vkcrm.brand.font'))` không chỉ định provider nên Filament rơi
 * về `BunnyFontProvider`: mỗi lượt tải trang của CẢ HAI panel — kể cả trang đăng nhập cổng khách
 * hàng, tức trước khi ai đăng nhập — phát một request tới một bên thứ ba. Quyết định giữ Bunny đã
 * được ghi vào `docs/SPEC.md` §3 kèm cái giá của nó và phương án tự host.
 *
 * Test này khoá mặt còn lại của quyết định: nếu ai đó gỡ `->font()`, đổi provider, hay CDN bị chặn
 * bởi cấu hình CSP sau này, thì phải ĐỎ MỘT TEST — chứ không phải âm thầm hạ cấp chữ nghĩa của cả
 * sản phẩm xuống phông hệ thống mà không ai nhận ra trong nhiều tháng.
 */
it('loads the brand webfont stylesheet on both panels', function () {
    $family = Str::slug(config('vkcrm.brand.font'));

    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web')
        ->get('/admin')
        ->assertOk()
        ->assertSee('fonts.bunny.net/css?family='.$family, escape: false);

    // Trang đăng nhập cổng khách hàng: KHÔNG đăng nhập, vì đây chính là lượt tải mà quyết định ở
    // SPEC §3 nói tới — request ra bên thứ ba xảy ra trước khi khách hàng là ai đó xác định.
    $this->get('/portal/login')
        ->assertOk()
        ->assertSee('fonts.bunny.net/css?family='.$family, escape: false);
});

/**
 * Lớp nhận diện phủ lên giao diện dựng sẵn của Filament được tiêm qua render hook, nên nó là thứ
 * rất dễ mất im lặng: một lần nâng cấp Filament đổi tên token, hay ai đó dọn bớt render hook, là
 * hệ thống lặng lẽ quay về bo tròn mặc định — trông như một phần mềm SaaS bất kỳ dán tên văn
 * phòng. Ghim cả hai nửa: token bán kính vuông (chữ ký thị giác lấy từ CSS của luatvukhang.com,
 * nơi chỉ dùng 0 và 3px) và bộ chữ có chân cho tiêu đề.
 */
it('overrides the framework radius tokens and loads the serif on both panels', function () {
    foreach (['/admin/login', '/portal/login'] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('--radius-lg: 3px', escape: false)
            ->assertSee('fonts.bunny.net/css?family=noto-serif', escape: false)
            ->assertSee('.fi-badge { border-radius: 999px; }', escape: false);
    }
});
