<?php

use App\Actions\Push\ForgetPushDevice;
use App\Actions\User\DeleteStaffMember;
use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePushServer;

/*
|--------------------------------------------------------------------------
| Rà soát toàn hệ thống 2026-10-22 — làn security
|--------------------------------------------------------------------------
| Tắt "Đang hoạt động" trên trang sửa nhân sự (đường nghỉ việc thường) và xoá nhân sự phải cắt quyền vào
| ĐÚNG như "Khoá truy cập ngay" (`SuspendStaffAccess`): tăng `session_epoch`, đổi `remember_token`, gỡ mọi
| máy nhận thông báo đẩy. Trước bản sửa, hai đường này chỉ dựa vào `EndDisabledStaffSessions` (đăng xuất ở
| request KẾ TIẾP khi tài khoản còn tắt): bật lại tài khoản (bấm nhầm, người đó quay lại làm, hay
| "Khôi phục" một người đã xoá) TRƯỚC request đó trả lại nguyên phiên cũ trên một trình duyệt có thể đã
| không còn trong tay họ, và điện thoại cũ — đã bán, đã mất — lại nhận thông báo đẩy về hồ sơ.
|
| Đo bằng phiên THẬT trong bảng `sessions` và cookie thật (cùng cách `StaffTwoFactorResetSessionTest`).
| Hàm toàn cục mang tiền tố `sdca`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    config(['session.driver' => 'database']);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->staff = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Nghỉ Việc']);
});

afterEach(function () {
    Livewire::flushState();
});

/** Dòng `sessions` của một trình duyệt `$user` đã đăng nhập guard `web` ở epoch hiện hành của họ. */
function sdcaBrowserSession(User $user): string
{
    $store = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app()), null, config('session.serialization', 'php'));
    $store->setId(Str::random(40));
    $store->put('login_web_'.sha1(SessionGuard::class), $user->getKey());
    $store->put('staff_session_epoch', (int) $user->session_epoch);
    $store->save();

    return $store->getId();
}

/** Mỗi request production là một tiến trình mới: guard không nhớ người dùng của request trước. */
function sdcaNewRequest(): void
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
    app()->forgetInstance('auth.driver');
}

function sdcaToggleActive(User $admin, User $staff, bool $active): void
{
    Livewire::actingAs($admin, 'web')
        ->test(EditUser::class, ['record' => $staff->getKey()])
        ->fillForm(['is_active' => $active])
        ->call('save')
        ->assertHasNoFormErrors();

    sdcaNewRequest();
}

it('tắt rồi bật lại "Đang hoạt động": phiên cũ của nhân sự không quay lại', function () {
    $session = sdcaBrowserSession($this->staff);

    $this->withCookie(config('session.cookie'), $session)->get(Dashboard::getUrl(panel: 'admin'))->assertOk();
    sdcaNewRequest();

    // Không request nào của trình duyệt cũ chen vào giữa hai lần bấm (máy đóng nắp, máy đã mất).
    sdcaToggleActive($this->admin, $this->staff, false);
    sdcaToggleActive($this->admin, $this->staff->fresh(), true);

    expect($this->staff->fresh()->is_active)->toBeTrue();

    $this->withCookie(config('session.cookie'), $session)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(auth('web')->check())->toBeFalse();
});

it('tắt "Đang hoạt động": gỡ mọi máy nhận thông báo đẩy, như "Khoá truy cập ngay"', function () {
    FakePushServer::device($this->staff, 'dien-thoai-cu');

    sdcaToggleActive($this->admin, $this->staff, false);

    expect($this->staff->fresh()->pushSubscriptions()->count())->toBe(0);

    $removed = Activity::query()->where('event', 'push_device_removed')->sole();

    expect($removed->properties['reason'])->toBe(ForgetPushDevice::REASON_STAFF_DEACTIVATED)
        ->and($removed->causer_id)->toBe($this->admin->id);
});

it('lưu form của một nhân sự đang hoạt động (không tắt) không cắt phiên, không gỡ máy', function () {
    FakePushServer::device($this->staff, 'dien-thoai-dang-dung');
    $epoch = (int) $this->staff->session_epoch;

    Livewire::actingAs($this->admin, 'web')
        ->test(EditUser::class, ['record' => $this->staff->getKey()])
        ->fillForm(['phone' => '0901 234 567'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) $this->staff->fresh()->session_epoch)->toBe($epoch)
        ->and($this->staff->fresh()->pushSubscriptions()->count())->toBe(1);
});

it('xoá rồi khôi phục một nhân sự: phiên cũ không quay lại, máy cũ không còn nhận thông báo', function () {
    FakePushServer::device($this->staff, 'dien-thoai-cu');
    $session = sdcaBrowserSession($this->staff);

    app(DeleteStaffMember::class)->handle($this->admin, $this->staff);
    sdcaNewRequest();

    // "Khôi phục" của bảng nhân sự (`RestoreBulkAction`) gọi đúng `restore()` của model.
    User::withTrashed()->findOrFail($this->staff->id)->restore();
    sdcaNewRequest();

    $this->withCookie(config('session.cookie'), $session)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(auth('web')->check())->toBeFalse()
        ->and($this->staff->fresh()->pushSubscriptions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'push_device_removed')->sole()->properties['reason'])
        ->toBe(ForgetPushDevice::REASON_STAFF_DELETED);
});
