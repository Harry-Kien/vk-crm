<?php

use App\Actions\Push\ForgetPushDevice;
use App\Actions\Schedule\PrunePushSubscriptions;
use App\Actions\User\DeleteStaffMember;
use App\Actions\User\ResetStaffTwoFactor;
use App\Actions\User\SuspendStaffAccess;
use App\Enums\Role;
use App\Models\User;
use App\Support\Push\PushSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\WebPushTestKeys;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/*
|--------------------------------------------------------------------------
| M15 Task 2 — máy iPhone thôi nhận thông báo khi người đó đăng xuất, bị khoá, bị xoá, đặt lại 2FA
|--------------------------------------------------------------------------
| `ForgetPushDevice::onLogout()` và `::all()` là MỘT chỗ duy nhất gỡ thiết bị: mọi người gọi `all()`
| (khoá truy cập, xoá nhân sự, đặt lại 2FA…) tự gỡ luôn sổ máy iPhone.
*/

function forgetToken(string $seed): string
{
    return hash('sha256', $seed);
}

function forgetDevice(User $owner, string $seed, int $seenDaysAgo = 0): string
{
    $token = forgetToken($seed);
    DB::table('native_push_devices')->insert([
        'user_id' => $owner->id,
        'token' => $token,
        'environment' => 'sandbox',
        'device_label' => 'iPhone',
        'app_version' => '1.0',
        'last_seen_at' => now()->subDays($seenDaysAgo),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $token;
}

function forgetCount(User $owner): int
{
    return DB::table('native_push_devices')->where('user_id', $owner->id)->count();
}

function forgetRegister(string $seed): array
{
    return ['token' => forgetToken($seed), 'environment' => 'sandbox', 'app_version' => '1.0'];
}

it('removes only the session token on panel logout and keeps the same person\'s other phone', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $other = forgetDevice($staff, 'may-khac');

    $this->actingAs($staff, 'web')->postJson('/admin/native/devices', forgetRegister('may-nay'))->assertNoContent();
    expect(forgetCount($staff))->toBe(2);

    $this->post(Filament::getPanel('admin')->getLogoutUrl());

    expect(auth('web')->check())->toBeFalse()
        ->and(DB::table('native_push_devices')->pluck('token')->all())->toBe([$other])
        ->and(Activity::query()->where('event', 'push_device_removed')->count())->toBe(1);
});

it('does not remove a session token that now belongs to someone else', function () {
    $a = User::factory()->withRole(Role::Lawyer)->create();
    $b = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($a, 'web')->postJson('/admin/native/devices', forgetRegister('dung-chung'))->assertNoContent();
    // Người khác lấy token (từ trình duyệt khác) trước khi A đăng xuất.
    DB::table('native_push_devices')->where('token', forgetToken('dung-chung'))->update(['user_id' => $b->id]);

    $this->post(Filament::getPanel('admin')->getLogoutUrl());

    expect(forgetCount($b))->toBe(1);
});

it('DELETE /admin/native/devices removes only the token recorded in the session', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $other = forgetDevice($staff, 'may-khac');

    $this->actingAs($staff, 'web')->postJson('/admin/native/devices', forgetRegister('may-nay'))->assertNoContent();

    $this->deleteJson('/admin/native/devices')->assertNoContent();

    expect(DB::table('native_push_devices')->pluck('token')->all())->toBe([$other])
        ->and(session()->has(PushSession::nativeTokenKey()))->toBeFalse();

    // Không còn token trong phiên: không xoá thêm gì, vẫn 204.
    $this->deleteJson('/admin/native/devices')->assertNoContent();
    expect(forgetCount($staff))->toBe(1);
});

it('DELETE never touches another person\'s device', function () {
    $a = User::factory()->withRole(Role::Lawyer)->create();
    $b = User::factory()->withRole(Role::Accountant)->create();
    forgetDevice($b, 'cua-b');

    $this->actingAs($a, 'web')->deleteJson('/admin/native/devices')->assertNoContent();

    expect(forgetCount($b))->toBe(1);
});

it('ForgetPushDevice::all counts web and native devices together and audits each', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    forgetDevice($staff, 'm1');
    forgetDevice($staff, 'm2');
    $keys = WebPushTestKeys::subscription();
    $staff->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc:APA91bNative', $keys['p256dh'], $keys['auth'], 'aes128gcm');

    $count = app(ForgetPushDevice::class)->all($staff, null, ForgetPushDevice::REASON_OFFICE);

    expect($count)->toBe(3)
        ->and(forgetCount($staff))->toBe(0)
        ->and($staff->pushSubscriptions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'push_device_removed')->count())->toBe(3);
});

it('SuspendStaffAccess leaves no native device of that person (and keeps others\')', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $bystander = User::factory()->withRole(Role::Accountant)->create();
    forgetDevice($staff, 'm1');
    forgetDevice($staff, 'm2');
    forgetDevice($bystander, 'm3');

    app(SuspendStaffAccess::class)->handle($admin, $staff, 'Nghỉ việc đột xuất, nghi chép dữ liệu khách ra ngoài.');

    expect(forgetCount($staff))->toBe(0)
        ->and(forgetCount($bystander))->toBe(1);
});

it('DeleteStaffMember leaves no native device of the deleted person', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    forgetDevice($staff, 'm1');

    app(DeleteStaffMember::class)->handle($admin, $staff);

    expect(forgetCount($staff))->toBe(0);
});

it('ResetStaffTwoFactor leaves no native device of that person', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    forgetDevice($staff, 'm1');

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);

    expect(forgetCount($staff))->toBe(0);
});

it('the daily prune removes native rows unseen for over 180 days and keeps fresher ones', function () {
    $this->travelTo(Carbon::parse('2026-10-16 03:30:00'));
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    forgetDevice($staff, 'cu', seenDaysAgo: 181);
    $fresh = forgetDevice($staff, 'moi', seenDaysAgo: 179);

    app(PrunePushSubscriptions::class)->handle();
    expect(DB::table('native_push_devices')->pluck('token')->all())->toBe([$fresh]);

    // Chạy lần hai không đổi gì (M6 R4).
    app(PrunePushSubscriptions::class)->handle();
    expect(forgetCount($staff))->toBe(1);
});

it('the daily prune also drops native rows of an inactive staff member', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    forgetDevice($staff, 'm1');

    app(PrunePushSubscriptions::class)->handle();

    expect(forgetCount($staff))->toBe(0);
});
