<?php

use App\Actions\User\ResetStaffTwoFactor;
use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\Login as LoginPage;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * "Đặt lại 2FA" phải giết phiên cũ của người bị đặt lại — đo bằng HÀNH VI THẬT: phiên nằm trong
 * bảng `sessions` (`session.driver=database`, khác `array` của phpunit.xml), mỗi request đi qua
 * đường ống HTTP thật với cookie phiên thật. Lý do phải đo ở đây chứ không phải chỉ đếm dòng
 * `sessions.user_id`: `DatabaseSessionHandler` điền `user_id` từ guard MẶC ĐỊNH lúc ghi, và
 * `Filament\Http\Middleware\Authenticate` đổi guard mặc định sang `client` trên route `/portal` —
 * một trình duyệt nhân sự mà request cuối cùng chạm `/portal` để lại dòng `user_id = NULL` (hay id
 * của một khách) trong khi payload vẫn giữ `login_web_*`. Xoá theo `user_id` bỏ sót đúng dòng đó.
 *
 * Một test dùng chung MỘT app cho nhiều request (khác production: mỗi request một tiến trình), nên
 * giữa hai request phải `Auth::forgetGuards()` để guard không nhớ người dùng của request trước.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['session.driver' => 'database']);
});

/**
 * Một `Store` MỚI trên một handler MỚI cho mỗi dòng `sessions` dựng tay: `DatabaseSessionHandler`
 * nhớ cờ `exists` của lần đọc/ghi trước và sẽ `UPDATE` (không dòng nào) thay vì `INSERT` cho id kế.
 */
function newSessionRow(): Store
{
    $store = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app()), null, config('session.serialization', 'php'));
    $store->setId(Str::random(40));

    return $store;
}

/**
 * Dựng một dòng `sessions` như một trình duyệt của `$user` đã đăng nhập guard `web` ở epoch
 * `$epoch` (null = phiên cũ không có khoá epoch), trả về id phiên để gắn cookie. `$alsoClient`:
 * cùng trình duyệt đó CŨNG đang đăng nhập cổng khách bằng một tài khoản khách (hai panel dùng chung
 * một cookie phiên).
 */
function staffBrowserSession(User $user, ?int $epoch = 0, ?ClientUser $alsoClient = null): string
{
    $store = newSessionRow();
    $store->put('login_web_'.sha1(SessionGuard::class), $user->getKey());

    if ($alsoClient !== null) {
        $store->put('login_client_'.sha1(SessionGuard::class), $alsoClient->getKey());
    }

    if ($epoch !== null) {
        $store->put('staff_session_epoch', $epoch);
    }

    $store->save();

    return $store->getId();
}

function withSessionCookie(string $sessionId)
{
    return test()->withCookie(config('session.cookie'), $sessionId);
}

/** Mỗi request production là một tiến trình mới: guard không nhớ người dùng, guard mặc định là `web`. */
function newRequestProcess(): void
{
    Auth::forgetGuards();
    Auth::shouldUse('web');
    // `auth.driver` là singleton mà `DatabaseSessionHandler` dùng để điền `sessions.user_id`: nó chốt
    // guard mặc định ở lần dựng ĐẦU TIÊN của tiến trình.
    app()->forgetInstance('auth.driver');
}

function adminHome(): string
{
    return Dashboard::getUrl(panel: 'admin');
}

it('§10.7 phiên nhân sự mà request cuối chạm /portal (user_id = khách) vẫn chết sau khi bị đặt lại 2FA', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => Client::factory()->create()->id]);

    $sessionId = staffBrowserSession($staff, alsoClient: $clientUser);

    // Đang đăng nhập bình thường: vào được /admin.
    withSessionCookie($sessionId)->get(adminHome())->assertOk();
    newRequestProcess();

    // Cùng trình duyệt cũng đang đăng nhập cổng khách (luật sư đồng thời là khách của văn phòng, hay
    // kẻ cầm trình duyệt chạm /portal): `Filament\Http\Middleware\Authenticate` đổi guard mặc định
    // sang `client`, và dòng phiên được ghi lại với user_id của KHÁCH — không còn là nhân sự nữa.
    withSessionCookie($sessionId)->get('/portal');
    newRequestProcess();

    expect(DB::table('sessions')->where('id', $sessionId)->value('user_id'))->toBe($clientUser->id)
        ->and($clientUser->id)->not->toBe($staff->id);

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);
    newRequestProcess();

    // Phiên cũ phải bị đăng xuất — KHÔNG được sang trang cài 2FA (nơi nó tự cài TOTP của kẻ tấn công).
    $response = withSessionCookie($sessionId)->get(adminHome());

    $response->assertRedirect(route('filament.admin.auth.login'));
    expect(auth('web')->check())->toBeFalse();
});

it('§10.7 phiên cũ chết cả trên request cập nhật Livewire, không chỉ trên lần tải trang', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Admin)->create();

    $sessionId = staffBrowserSession($staff);

    preg_match_all('/wire:snapshot="([^"]*)"/', withSessionCookie($sessionId)
        ->get(UserResource::getUrl('edit', ['record' => $admin], panel: 'admin'))->assertOk()->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded) => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $json) => (json_decode($json, true)['memo']['name'] ?? null) === EditUser::class);
    expect($snapshot)->not->toBeNull();

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);
    newRequestProcess();

    $response = withSessionCookie($sessionId)
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ]);

    expect(auth('web')->check())->toBeFalse();
    expect((string) $response->headers->get('Location'))->not->toContain('multi-factor-authentication')
        ->and($response->getStatusCode())->not->toBe(200);
});

it('§10.7 phiên đăng nhập SAU khi đặt lại (epoch mới) không bị giết — chỉ phiên cũ chết', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);

    // Người đó đã tự cài lại 2FA ở một trình duyệt mới: phiên mới mang epoch hiện hành.
    $staff->refresh()->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $fresh = staffBrowserSession($staff, $staff->session_epoch);
    $old = staffBrowserSession($staff, 0);
    newRequestProcess();

    withSessionCookie($fresh)->get(adminHome())->assertOk();
    newRequestProcess();

    withSessionCookie($old)->get(adminHome())->assertRedirect(route('filament.admin.auth.login'));
});

it('§10.7 phiên cũ chết trên MỌI route nhóm web, không chỉ trong panel admin (documents.download…)', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    Route::middleware('web')->get('zz-epoch-probe', fn () => auth('web')->check() ? 'in' : 'out');

    $sessionId = staffBrowserSession($staff);

    withSessionCookie($sessionId)->get('zz-epoch-probe')->assertSee('in');
    newRequestProcess();

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);
    $staff->refresh()->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    newRequestProcess();

    withSessionCookie($sessionId)->get('zz-epoch-probe')->assertSee('out');
});

it('đặt lại 2FA không đăng xuất một khách portal có id trùng số với nhân sự bị đặt lại', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $clientUser = ClientUser::factory()->activated()->create(['id' => $staff->id, 'client_id' => $client->id]);

    // Phiên của khách: user_id = id của khách, trùng số với `users.id` của nhân sự.
    $store = newSessionRow();
    $store->put('login_client_'.sha1(SessionGuard::class), $clientUser->getKey());
    $store->save();

    // Ghi user_id thật của khách như DatabaseSessionHandler làm khi guard mặc định là `client`.
    DB::table('sessions')->where('id', $store->getId())->update(['user_id' => $clientUser->getKey()]);

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);

    expect(DB::table('sessions')->where('id', $store->getId())->exists())->toBeTrue();
});

it('gắn epoch hiện hành của nhân sự vào phiên ngay lúc đăng nhập guard web', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $staff->forceFill(['session_epoch' => 3])->save();

    event(new Login('web', $staff->fresh(), false));

    expect(session('staff_session_epoch'))->toBe(3);
});

it('không gắn epoch khi đăng nhập guard khác (client)', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    event(new Login('client', $staff, false));

    expect(session()->has('staff_session_epoch'))->toBeFalse();
});

it('mỗi lần đặt lại tăng epoch của đúng người bị đặt lại, không đụng người khác', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $bystander = User::factory()->withRole(Role::Lawyer)->create();

    app(ResetStaffTwoFactor::class)->handle($admin, $staff);
    app(ResetStaffTwoFactor::class)->handle(null, $staff);

    expect($staff->fresh()->session_epoch)->toBe(2)
        ->and($bystander->fresh()->session_epoch)->toBe(0);
});

/** Đăng nhập THẬT (mật khẩu, rồi mã TOTP) qua trang đăng nhập của panel — không chỉ bắn sự kiện `Login` bằng tay. */
it('đăng nhập thật qua trang đăng nhập panel (mật khẩu + mã TOTP) gắn epoch hiện hành vào phiên', function () {
    Filament::setCurrentPanel('admin');

    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);
    $staff->forceFill(['session_epoch' => 2])->save();

    $component = Livewire::test(LoginPage::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $component
        ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($staff->two_factor_secret))
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('web')->id())->toBe($staff->id)
        ->and(session('staff_session_epoch'))->toBe(2);
});
