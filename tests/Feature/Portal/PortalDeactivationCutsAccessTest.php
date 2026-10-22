<?php

use App\Actions\Push\ForgetPushDevice;
use App\Enums\Role;
use App\Filament\Admin\Resources\ClientUsers\Pages\EditClientUser;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
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
| Rà soát toàn hệ thống 2026-10-22 — làn portal (việc chuyển từ làn security)
|--------------------------------------------------------------------------
| Tắt "Đang hoạt động" của một tài khoản cổng và xoá tài khoản cổng phải cắt quyền vào NGAY, như nhân sự
| (`EndStaffSessions` của làn security). Trước bản sửa, `UpdatePortalAccount` chỉ gỡ máy push khi đổi email,
| và `EnsurePortalAccountIsActive` chỉ đăng xuất ở request KẾ TIẾP khi tài khoản CÒN tắt: văn phòng tắt
| (khách báo mất điện thoại) rồi bật lại (khách xác minh xong) trước khi máy cũ gửi request thì phiên cũ
| và cookie "ghi nhớ" vào lại không cần mật khẩu, và máy cũ vẫn nhận thông báo đẩy về hồ sơ.
|
| Đo bằng phiên THẬT trong bảng `sessions` và cookie thật. Hàm toàn cục mang tiền tố `pdca`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['session.driver' => 'database']);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->account = ClientUser::factory()->activated()->create([
        'must_change_password' => false,
        'remember_token' => Str::random(60),
    ]);
});

afterEach(function () {
    Livewire::flushState();
});

/** Dòng `sessions` của một trình duyệt mà `$account` đã đăng nhập cổng, ở epoch hiện hành của họ. */
function pdcaBrowserSession(ClientUser $account): string
{
    $store = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app()), null, config('session.serialization', 'php'));
    $store->setId(Str::random(40));
    $store->put('login_client_'.sha1(SessionGuard::class), $account->getKey());
    // Panel có `AuthenticateSession`: phiên thật mang dấu băm mật khẩu của lần đăng nhập.
    $store->put('password_hash_client', $account->getAuthPassword());
    $store->put('portal_session_epoch', (int) ($account->session_epoch ?? 0));
    $store->save();

    return $store->getId();
}

/** Cookie "ghi nhớ đăng nhập" thật của guard `client`: `id|remember_token|băm mật khẩu`. */
function pdcaRememberCookie(ClientUser $account): array
{
    return ['remember_client_'.sha1(SessionGuard::class), $account->getKey().'|'.$account->getRememberToken().'|'.$account->getAuthPassword()];
}

/** Mỗi request production là một tiến trình mới: guard không nhớ người dùng của request trước. */
function pdcaNewRequest(): void
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
    app()->forgetInstance('auth.driver');
}

function pdcaToggleActive(User $admin, ClientUser $account, bool $active): void
{
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($admin, 'web')
        ->test(EditClientUser::class, ['record' => $account->getKey()])
        ->fillForm(['is_active' => $active])
        ->call('save')
        ->assertHasNoFormErrors();

    pdcaNewRequest();
}

function pdcaPortalHome(): string
{
    return MyMatters::getUrl(panel: 'portal');
}

it('tắt rồi bật lại tài khoản cổng: phiên cũ không quay lại', function () {
    $session = pdcaBrowserSession($this->account);

    $this->withCookie(config('session.cookie'), $session)->get(pdcaPortalHome())->assertOk();
    pdcaNewRequest();

    pdcaToggleActive($this->admin, $this->account, false);
    pdcaToggleActive($this->admin, $this->account->fresh(), true);

    expect($this->account->fresh()->is_active)->toBeTrue();

    $this->withCookie(config('session.cookie'), $session)
        ->get(pdcaPortalHome())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse();
});

it('tắt rồi bật lại tài khoản cổng: cookie "ghi nhớ đăng nhập" cũ không vào lại được', function () {
    [$name, $value] = pdcaRememberCookie($this->account);

    // Vế dương: cookie ghi nhớ thật vào được khi chưa ai tắt gì.
    $this->withCookie($name, $value)->get(pdcaPortalHome())->assertOk();
    pdcaNewRequest();
    $this->flushSession();

    pdcaToggleActive($this->admin, $this->account, false);
    pdcaToggleActive($this->admin, $this->account->fresh(), true);

    $this->withCookie($name, $value)
        ->get(pdcaPortalHome())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());
});

it('tắt tài khoản cổng: gỡ mọi máy nhận thông báo đẩy, ghi đúng lý do', function () {
    FakePushServer::device($this->account, 'dien-thoai-cu');

    pdcaToggleActive($this->admin, $this->account, false);

    expect($this->account->fresh()->pushSubscriptions()->count())->toBe(0);

    $removed = Activity::query()->where('event', 'push_device_removed')->sole();

    expect($removed->properties['reason'])->toBe(ForgetPushDevice::REASON_PORTAL_DEACTIVATED)
        ->and($removed->causer_id)->toBe($this->admin->id);
});

it('lưu tài khoản cổng đang hoạt động mà không tắt: không cắt phiên, không gỡ máy', function () {
    FakePushServer::device($this->account, 'dien-thoai-dang-dung');
    $session = pdcaBrowserSession($this->account);

    Filament::setCurrentPanel('admin');
    Livewire::actingAs($this->admin, 'web')
        ->test(EditClientUser::class, ['record' => $this->account->getKey()])
        ->fillForm(['phone' => '0901 234 567'])
        ->call('save')
        ->assertHasNoFormErrors();
    pdcaNewRequest();

    $this->withCookie(config('session.cookie'), $session)->get(pdcaPortalHome())->assertOk();

    expect($this->account->fresh()->pushSubscriptions()->count())->toBe(1);
});

it('xoá rồi khôi phục tài khoản cổng: phiên cũ không quay lại, máy cũ không còn nhận thông báo', function () {
    FakePushServer::device($this->account, 'dien-thoai-cu');
    $session = pdcaBrowserSession($this->account);

    Filament::setCurrentPanel('admin');
    Livewire::actingAs($this->admin, 'web')
        ->test(EditClientUser::class, ['record' => $this->account->getKey()])
        ->callAction(DeleteAction::class);
    pdcaNewRequest();

    expect(ClientUser::withTrashed()->find($this->account->id)->trashed())->toBeTrue();

    // "Khôi phục" của bảng tài khoản cổng (`RestoreBulkAction`) gọi đúng `restore()` của model.
    ClientUser::withTrashed()->findOrFail($this->account->id)->restore();
    pdcaNewRequest();

    $this->withCookie(config('session.cookie'), $session)
        ->get(pdcaPortalHome())
        ->assertRedirect(Filament::getPanel('portal')->getLoginUrl());

    expect(auth('client')->check())->toBeFalse()
        ->and($this->account->fresh()->pushSubscriptions()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'push_device_removed')->sole()->properties['reason'])
        ->toBe(ForgetPushDevice::REASON_PORTAL_DELETED);
});
