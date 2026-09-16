<?php

use App\Enums\Role;
use App\Models\User;

/**
 * Nhận diện thương hiệu của văn phòng phải có mặt ở CẢ hai panel, không chỉ ở trang đăng nhập
 * mà tôi tình cờ mở ra xem. Đây là thứ rất dễ mất im lặng: một lần nâng cấp Filament đổi tên
 * render hook, một lần đổi `config/vkcrm.php`, hay một `brandLogo()` bị xoá khi sửa panel — và
 * hệ thống lặng lẽ quay về giao diện mặc định trắng trơn của framework.
 */
it('shows the firm identity on the internal login screen', function () {
    $response = $this->get('/admin/login');

    $response->assertOk()
        ->assertSee('VŨ KHANG', escape: false)
        ->assertSee(config('vkcrm.brand.tagline'), escape: false)
        ->assertSee(config('vkcrm.brand.legal_name'))
        ->assertSee(config('vkcrm.brand.hotline'))
        ->assertSee('brand/favicon.svg', escape: false);
});

it('shows the firm identity on the client portal login screen', function () {
    $response = $this->get('/portal/login');

    $response->assertOk()
        ->assertSee('VŨ KHANG', escape: false)
        ->assertSee(config('vkcrm.brand.tagline'), escape: false)
        ->assertSee(config('vkcrm.brand.legal_name'));
});

it('carries the logo into the internal panel once signed in', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web')
        ->get('/admin')
        ->assertOk()
        ->assertSee('SOLUTIONS &amp; PARTNERS', escape: false);
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
