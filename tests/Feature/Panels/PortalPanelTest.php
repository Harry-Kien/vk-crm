<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the portal login', function () {
    $this->get('/portal')->assertRedirect('/portal/login');
});

/**
 * `activated()` chứ không phải mặc định của factory: tài khoản mới có `must_change_password`
 * bật, và từ M5 thì `RequirePortalPasswordChange` chặn mọi trang cổng cho tới khi khách tự đặt
 * mật khẩu (SPEC §8.1). Cánh cổng đó có test riêng ở `tests/Feature/Portal/LoginTest.php`; ở đây
 * câu cần đo là "tài khoản còn hoạt động thì vào được cổng", nên nó phải ở phía sau cánh cổng.
 */
it('lets an active client user open the portal', function () {
    $clientUser = ClientUser::factory()->activated()->create();

    $this->actingAs($clientUser, 'client')->get('/portal')->assertOk();
});

/**
 * Panel từ chối bằng một mã duy nhất, 404 (SPEC §10.10) — xem
 * `AnswerDeniedPanelRequestsWithNotFound`. Một tài khoản KHÁCH bị vô hiệu là ngoại lệ đã
 * được ghi ra: M5 đặt `EnsurePortalAccountIsActive` chạy TRƯỚC `Authenticate` để họ thấy màn
 * hình đăng nhập thay vì một lời từ chối (SPEC §10.9, và "câu trả lời nhân đạo cho họ là màn
 * hình đăng nhập" là nguyên văn phán quyết M4 giao lại). Không có mâu thuẫn với SPEC §10.10:
 * câu kèm theo không nói gì về tài khoản nào cả — xem `tests/Feature/Portal/LoginTest.php`.
 */
it('sends an inactive client user back to the login screen instead of refusing them', function () {
    $clientUser = ClientUser::factory()->create(['is_active' => false]);

    $this->actingAs($clientUser, 'client')->get('/portal')->assertRedirect('/portal/login');
});

it('does not accept a staff session on the portal', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/portal')->assertRedirect('/portal/login');
});
