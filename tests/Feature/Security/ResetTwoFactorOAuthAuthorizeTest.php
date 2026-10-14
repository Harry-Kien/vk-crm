<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Support\Mcp\McpEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| Làn fc (việc còn mở từ lần gộp bản 1.0, mục C): phiên cũ sau "Đặt lại 2FA" trên /oauth/authorize
|--------------------------------------------------------------------------
|
| `RejectStaffSessionsFromBeforeReset` nối vào CUỐI nhóm `web`, không nằm trong danh sách ưu tiên. Route
| của Passport khai `auth:web` trong chính danh sách middleware của route, nên khi Laravel sắp theo độ
| ưu tiên, `Authenticate` chạy TRƯỚC nó: phiên có từ trước lần đặt lại 2FA qua được `Authenticate`, rồi
| mới bị đăng xuất, và nhận một lỗi thay cho trang đăng nhập — đúng lỗi `EndDisabledStaffSessions` đã
| có (lượt quét §10.9, `SessionCutSpec109Test`) và đã sửa bằng `prependToPriorityList`. Ở đây cùng cách
| sửa cho middleware thứ hai, cùng kiểu kiểm: đặt lại 2FA qua nút thật trên EditUser, rồi request kế
| tiếp của phiên cũ đi qua HTTP thật.
|
| Hàm toàn cục mang tiền tố `rtfoa…`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::useTestKeys();
    McpOAuth::openServer();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/** URL `/oauth/authorize` hợp lệ (PKCE S256) cho một client MCP vừa đăng ký. */
function rtfoaAuthorizeUrl(): string
{
    $client = McpOAuth::client();

    return '/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'scope' => 'mcp:use',
        'state' => 'rtfoa-'.Str::random(12),
        'code_challenge' => McpOAuth::pkce()['challenge'],
        'code_challenge_method' => 'S256',
    ]);
}

/** @return array{authorize: string, auth_token: ?string} */
function rtfoaOpenScreen(User $staff): array
{
    test()->actingAs($staff, 'web');

    $authorize = rtfoaAuthorizeUrl();
    $screen = test()->get($authorize)->assertOk();
    preg_match('/name="auth_token" value="([^"]+)"/', (string) $screen->getContent(), $match);

    return ['authorize' => $authorize, 'auth_token' => $match[1] ?? null];
}

function rtfoaResetTwoFactorOnScreen(User $staff): void
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs(test()->admin, 'web')
        ->test(EditUser::class, ['record' => $staff->getKey()])
        ->callAction('resetTwoFactor');

    expect((int) $staff->fresh()->session_epoch)->toBeGreaterThan((int) $staff->session_epoch);
}

it('sends a session from before a 2FA reset to the login page when it opens the OAuth consent screen', function () {
    $manager = User::factory()->withRole(Role::Manager)->withAiAccess()->create();
    $before = rtfoaOpenScreen($manager);

    rtfoaResetTwoFactorOnScreen($manager);

    $this->actingAs($manager->fresh(), 'web');
    $response = $this->get($before['authorize']);

    $response->assertRedirect(McpEndpoint::staffLoginUrl());
    expect((string) $response->getContent())->not->toContain('name="auth_token"')
        ->and(auth('web')->check())->toBeFalse();
});

it('sends a session from before a 2FA reset to the login page when it presses Approve, and issues no code', function () {
    $manager = User::factory()->withRole(Role::Manager)->withAiAccess()->create();
    $before = rtfoaOpenScreen($manager);
    expect($before['auth_token'])->toBeString()->not->toBe('');

    rtfoaResetTwoFactorOnScreen($manager);

    $this->actingAs($manager->fresh(), 'web');

    $this->post('/oauth/authorize', ['auth_token' => $before['auth_token']])
        ->assertRedirect(McpEndpoint::staffLoginUrl());

    expect(Passport::authCode()->newQuery()->count())->toBe(0)
        ->and(auth('web')->check())->toBeFalse();
});

/** Cặp dương: không ai đặt lại 2FA, cùng phiên mở lại màn hình đồng ý và vẫn đăng nhập. */
it('keeps a session that is newer than every 2FA reset on the consent screen', function () {
    $manager = User::factory()->withRole(Role::Manager)->withAiAccess()->create();
    $before = rtfoaOpenScreen($manager);

    $this->actingAs($manager->fresh(), 'web');
    $this->get($before['authorize'])->assertOk()->assertSee('name="auth_token"', false);

    expect(auth('web')->check())->toBeTrue();
});
