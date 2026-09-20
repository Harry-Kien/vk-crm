<?php

use App\Models\ClientUser;
use App\Models\User;

it('redirects guests to the portal login', function () {
    $this->get('/portal')->assertRedirect('/portal/login');
});

it('lets an active client user open the portal', function () {
    $clientUser = ClientUser::factory()->create();

    $this->actingAs($clientUser, 'client')->get('/portal')->assertOk();
});

/**
 * Panel từ chối bằng một mã duy nhất, 404 (SPEC §10.10) — xem
 * `AnswerDeniedPanelRequestsWithNotFound`. Một tài khoản bị vô hiệu vì thế cũng nhận 404
 * thay cho 403; cái giá đã cân nhắc và ghi ở DenialCodeTest. Câu trả lời tử tế cho họ là
 * màn hình đăng nhập, và nó thuộc về việc cài SPEC §10.9 (huỷ phiên ngay), không phải
 * mã trạng thái ở đây.
 */
it('blocks an inactive client user', function () {
    $clientUser = ClientUser::factory()->create(['is_active' => false]);

    $this->actingAs($clientUser, 'client')->get('/portal')->assertNotFound();
});

it('does not accept a staff session on the portal', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->get('/portal')->assertRedirect('/portal/login');
});
