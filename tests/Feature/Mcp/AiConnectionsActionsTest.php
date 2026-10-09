<?php

use App\Actions\Mcp\DisconnectAiConnections;
use App\Actions\Mcp\ListAiConnections;
use App\Actions\Mcp\ListMcpAuditEntries;
use App\Actions\Mcp\UpdateAiSettings;
use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 15 — cổng quyền của bốn Action sau hai trang "Kết nối AI"
|--------------------------------------------------------------------------
| Hành vi của màn hình đo ở `tests/Feature/Filament/AiConnectionsTest.php` và
| `MyAiConnectionsTest.php` (Livewire/HTTP). Tệp này chỉ đo lớp phòng thủ THỨ HAI: chính Action hỏi
| `Gate::forUser($actor)`, không tin trang đã gác — một nơi gọi khác (lệnh, tool MCP sau này) không đi
| qua `boot()` của trang nào.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer();
});

function aiaLawyer(): User
{
    return User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::Read)->create();
}

it('UpdateAiSettings: người không có settings.manage nhận AuthorizationException, không ghi gì', function (Role $role) {
    $actor = User::factory()->withRole($role)->create();

    expect(fn () => app(UpdateAiSettings::class)->handle($actor, ['enabled' => false, 'transfer_assessment_filed_on' => '2026-10-01']))
        ->toThrow(AuthorizationException::class);

    expect(Setting::query()->where('key', 'mcp.enabled')->value('value'))->toBe('1')
        ->and(Setting::query()->where('key', 'mcp.transfer_assessment_filed_on')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'ai_settings_updated')->count())->toBe(0);
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('UpdateAiSettings: cặp dương — quản trị ghi được, khoá vắng mặt giữ nguyên, khoá lạ bị bỏ qua', function () {
    $admin = User::factory()->admin()->create();

    $changed = app(UpdateAiSettings::class)->handle($admin, ['write_enabled' => true, 'mcp.enabled' => '0', 'khac' => 'x']);

    expect($changed)->toBe(['write_enabled'])
        ->and(Setting::query()->where('key', 'mcp.enabled')->value('value'))->toBe('1')
        ->and(Setting::query()->where('key', 'mcp.write_enabled')->value('value'))->toBe('1');
});

it('ListAiConnections::forUser: nhân sự chỉ xem được kết nối của chính mình; quản trị xem được của mọi người', function () {
    $alice = aiaLawyer();
    $bob = aiaLawyer();
    McpOAuth::accessToken($this, $bob);

    expect(fn () => app(ListAiConnections::class)->forUser($alice, $bob))->toThrow(AuthorizationException::class)
        ->and(app(ListAiConnections::class)->forUser($bob, $bob))->toHaveCount(1)
        ->and(app(ListAiConnections::class)->forUser(User::factory()->admin()->create(), $bob))->toHaveCount(1);
});

it('ListAiConnections::overview và ListMcpAuditEntries: chỉ settings.manage', function () {
    $lawyer = aiaLawyer();

    expect(fn () => app(ListAiConnections::class)->overview($lawyer, [$lawyer]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ListMcpAuditEntries::class)->handle($lawyer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ListMcpAuditEntries::class)->toolNames($lawyer))->toThrow(AuthorizationException::class);

    $admin = User::factory()->admin()->create();

    expect(app(ListAiConnections::class)->overview($admin, [$lawyer]))->toHaveKey($lawyer->getKey())
        ->and(app(ListMcpAuditEntries::class)->handle($admin))->toBeArray()
        ->and(app(ListMcpAuditEntries::class)->toolNames($admin))->toBeArray();
});

it('DisconnectAiConnections: người A không thu hồi được kết nối của người B (AuthorizationException, token của B còn sống)', function () {
    $alice = aiaLawyer();
    $bob = aiaLawyer();
    McpOAuth::accessToken($this, $bob);

    expect(fn () => app(DisconnectAiConnections::class)->handle($alice, $bob))->toThrow(AuthorizationException::class)
        ->and(Passport::token()->newQuery()->where('user_id', $bob->getKey())->where('revoked', false)->count())->toBe(1);

    expect(app(DisconnectAiConnections::class)->handle($bob, $bob))->toBeGreaterThan(0)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('revoked_by_self');
});

it('DisconnectAiConnections một kết nối: chỉ token và mã uỷ quyền của client đó; mã uỷ quyền chưa đổi của client khác còn sống', function () {
    $lawyer = aiaLawyer();
    $target = McpOAuth::client();
    $other = McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']);
    McpOAuth::authorizationCode($lawyer, $target);
    McpOAuth::authorizationCode($lawyer, $other);

    app(DisconnectAiConnections::class)->handle(User::factory()->admin()->create(), $lawyer, (string) $target->getKey());

    $live = fn (string $clientId): int => Passport::authCode()->newQuery()
        ->where('user_id', $lawyer->getKey())->where('client_id', $clientId)->where('revoked', false)->count();

    expect($live((string) $target->getKey()))->toBe(0)
        ->and($live((string) $other->getKey()))->toBe(1);
});
