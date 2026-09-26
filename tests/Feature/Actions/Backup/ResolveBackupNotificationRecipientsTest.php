<?php

use App\Actions\Backup\ResolveBackupNotificationRecipients;
use App\Enums\Role;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| §10.8 — người nhận thư báo lỗi sao lưu
|--------------------------------------------------------------------------
|
| `BACKUP_NOTIFY_EMAIL` ưu tiên tuyệt đối khi có khai báo; trống thì rơi về mọi nhân sự đang
| hoạt động (`is_active = true`) mang vai trò Admin — nhân sự đã nghỉ việc hoặc không phải Admin
| không nhận được thư này (họ không phải người vận hành hạ tầng).
*/

it('§10.8 dùng BACKUP_NOTIFY_EMAIL khi đã cấu hình, bỏ qua danh sách admin', function () {
    config(['vkcrm.backup.notify_email' => 'ops@luatvukhang.com']);
    User::factory()->withRole(Role::Admin)->create(['is_active' => true]);

    $recipients = app(ResolveBackupNotificationRecipients::class)->handle();

    expect($recipients)->toBe(['ops@luatvukhang.com']);
});

it('§10.8 rơi về mọi admin đang hoạt động khi BACKUP_NOTIFY_EMAIL trống', function () {
    config(['vkcrm.backup.notify_email' => null]);

    $activeAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => true, 'email' => 'admin-active@vidu.test']);
    User::factory()->withRole(Role::Admin)->create(['is_active' => false, 'email' => 'admin-inactive@vidu.test']);
    User::factory()->withRole(Role::Lawyer)->create(['is_active' => true, 'email' => 'lawyer@vidu.test']);

    $recipients = app(ResolveBackupNotificationRecipients::class)->handle();

    expect($recipients)->toBe([$activeAdmin->email]);
});

it('§10.8 trả về danh sách rỗng khi không còn admin nào đang hoạt động và chưa cấu hình email', function () {
    config(['vkcrm.backup.notify_email' => null]);
    User::factory()->withRole(Role::Admin)->create(['is_active' => false]);

    expect(app(ResolveBackupNotificationRecipients::class)->handle())->toBe([]);
});

it('§10.8 trả về danh sách rỗng thay vì ném lỗi khi vai trò Admin chưa từng được tạo', function () {
    config(['vkcrm.backup.notify_email' => null]);

    // Không tạo user/role nào — máy chủ mới cài, trước khi có tài khoản admin đầu tiên.
    expect(app(ResolveBackupNotificationRecipients::class)->handle())->toBe([]);
});
