<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the admin login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active staff user open the admin dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/admin')->assertOk();
});

/**
 * Câu trả lời tử tế cho một tài khoản bị vô hiệu là màn hình đăng nhập, và nó thuộc về việc cài
 * SPEC §10.9 (huỷ phiên ngay) — lượt quét §10 trước bản 1.0 thêm `EndDisabledStaffSessions`: phiên
 * bị đăng xuất ở chính request này, nên panel chuyển về trang đăng nhập thay vì trả 404 cho một
 * phiên vẫn còn đăng nhập. Ma trận đầy đủ: `tests/Feature/Security/SessionCutSpec109Test.php`.
 */
it('signs an inactive staff user out and sends them to the admin login', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user, 'web')->get('/admin')->assertRedirect('/admin/login');

    expect(auth('web')->check())->toBeFalse();
});

it('does not accept a client session on the admin panel', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/admin')->assertRedirect('/admin/login');
});
