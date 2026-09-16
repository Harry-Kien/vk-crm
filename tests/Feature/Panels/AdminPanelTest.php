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
 * Panel từ chối bằng một mã duy nhất, 404 (SPEC §10.10) — xem
 * `AnswerDeniedPanelRequestsWithNotFound`. Một tài khoản bị vô hiệu vì thế cũng nhận 404
 * thay cho 403; cái giá đã cân nhắc và ghi ở DenialCodeTest. Câu trả lời tử tế cho họ là
 * màn hình đăng nhập, và nó thuộc về việc cài SPEC §10.9 (huỷ phiên ngay), không phải
 * mã trạng thái ở đây.
 */
it('blocks an inactive staff user', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user, 'web')->get('/admin')->assertNotFound();
});

it('does not accept a client session on the admin panel', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/admin')->assertRedirect('/admin/login');
});
