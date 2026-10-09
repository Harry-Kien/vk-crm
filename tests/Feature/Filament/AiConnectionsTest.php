<?php

use App\Actions\Mcp\RecordMcpConnectionDecision;
use App\Actions\Mcp\RegisterMcpClient;
use App\Actions\Settings\WriteSettings;
use App\Enums\AiAccessMode;
use App\Enums\McpPlatform;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\AiConnections;
use App\Models\AiAcknowledgement;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpSwitches;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 15 — trang "Kết nối AI" của quản trị (R2, R8, R12 mục 3)
|--------------------------------------------------------------------------
| Mọi hành vi của màn hình đo qua Livewire hoặc HTTP, không gọi thẳng Action. Hiệu lực của một lần
| bật/tắt hay thu hồi đo bằng một request `/mcp` THẬT với token Passport THẬT ở request kế tiếp
| (`Tests\Support\McpOAuth`), mỗi request như một tiến trình PHP-FPM mới ({@see aicFresh()}).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    McpOAuth::openServer();

    $this->admin = User::factory()->admin()->create(['name' => 'Quản Trị Viên']);
});

afterEach(function () {
    Livewire::flushState();
});

/** Ứng dụng sống qua mọi request của một test; máy chủ thật thì không. */
function aicFresh(): void
{
    Auth::forgetGuards();
    Once::flush();
}

function aicInitialize(string $token): TestResponse
{
    aicFresh();

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'client-thu', 'version' => '1.0'],
        ],
    ], ['Authorization' => 'Bearer '.$token]);
}

function aicExpectRefused(TestResponse $response): void
{
    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))->toContain('error="invalid_token"');
}

function aicRefresh(string $clientId, string $refreshToken): TestResponse
{
    aicFresh();

    return test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $refreshToken,
    ]);
}

function aicLawyer(AiAccessMode $mode = AiAccessMode::Read, array $attributes = []): User
{
    return User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->withAiAccess($mode)->create($attributes);
}

function aicPage(User $actor): mixed
{
    test()->actingAs($actor, 'web');

    return Livewire::test(AiConnections::class);
}

function aicSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === AiConnections::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Kết nối AI trong HTML.');
}

/** Một request cập nhật Livewire THẬT (đường `/livewire-…/update`), không qua `Livewire::test()`. */
function aicPostUpdate(string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
    ]);
}

/**
 * Chữ của bốn ô đầu (nền tảng, host, ngày tạo, lần dùng cuối) của dòng kết nối `$clientId` trong HTML
 * đã render — `[]` khi không có dòng đó.
 *
 * @return list<string>
 */
function aicCells(string $html, string $clientId): array
{
    if (preg_match('/<tr[^>]*data-connection="'.preg_quote($clientId, '/').'"[^>]*>(.*?)<\/tr>/s', $html, $row) !== 1) {
        return [];
    }

    preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row[1], $cells);

    return array_slice(array_map(fn (string $cell): string => trim(html_entity_decode(strip_tags($cell))), $cells[1]), 0, 4);
}

/** Một dòng `mcp_tool_called` như `RecordMcpToolCall` ghi (fixture của khối "Nhật ký MCP"). */
function aicToolRow(User $user, string $tool, string $ip, array $extra = []): void
{
    Audit::record('mcp_tool_called', null, [
        'channel' => 'mcp',
        'tool' => $tool,
        'outcome' => 'ok',
        'arguments' => ['query' => ['length' => 12]],
        'returned_count' => 3,
        'oauth_client_id' => 'client-thu',
        'platform' => 'claude',
        'ip' => $ip,
        ...$extra,
    ], causer: $user);
}

// ---------------------------------------------------------------------------------------------
// Cổng: `settings.manage`; mọi người khác nhận 404, gồm cả đường Livewire update.
// ---------------------------------------------------------------------------------------------

it('mở được cho quản trị, khai đúng slug ket-noi-ai và hiện trên thanh điều hướng', function () {
    $this->actingAs($this->admin, 'web')
        ->get(AiConnections::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('ai_connections.admin.title'));

    expect(AiConnections::getUrl(panel: 'admin'))->toEndWith('/admin/ket-noi-ai')
        ->and(AiConnections::shouldRegisterNavigation())->toBeTrue();
});

it('trả 404 cho mọi người không có settings.manage, và không hiện trên thanh điều hướng của họ', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(AiConnections::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(AiConnections::shouldRegisterNavigation())->toBeFalse();
})->with([Role::Manager, Role::Lawyer, Role::Assistant, Role::Accountant]);

it('trả 404 cho một request cập nhật Livewire THẬT từ người vừa mất settings.manage, và không đổi gì', function () {
    $lawyer = aicLawyer();
    $snapshot = aicSnapshot($this->actingAs($this->admin, 'web')->get(AiConnections::getUrl(panel: 'admin'))->assertOk()->getContent());

    $this->admin->syncRoles([Role::Manager->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    aicPostUpdate($snapshot, ['switches.enabled' => false])
        ->assertNotFound();

    expect(McpSwitches::enabled())->toBeTrue()
        ->and($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Read);
});

it('cặp dương: cùng snapshot, quản trị còn quyền thì request cập nhật Livewire chạy', function () {
    $snapshot = aicSnapshot($this->actingAs($this->admin, 'web')->get(AiConnections::getUrl(panel: 'admin'))->assertOk()->getContent());

    aicPostUpdate($snapshot)->assertOk();
});

it('mọi hành động của trang tự hỏi lại cổng: người mất quyền giữa chừng nhận 404 ở lần bấm kế tiếp, không ghi gì', function (string $method) {
    $lawyer = aicLawyer();
    McpOAuth::accessToken($this, $lawyer);

    $page = aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->set('switches.enabled', false)
        ->set('assessment.filed_on', '2026-10-01');

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    match ($method) {
        'saveSwitches' => $page->call('saveSwitches')->assertNotFound(),
        'recordTransferAssessment' => $page->call('recordTransferAssessment')->assertNotFound(),
        'revokeAllConnections' => $page->call('mountAction', 'revokeAllConnections')->assertNotFound(),
    };

    expect(McpSwitches::enabled())->toBeTrue()
        ->and(Setting::query()->where('key', McpSwitches::TRANSFER_ASSESSMENT_FILED_ON)->value('value'))->toBeNull()
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->count())->toBe(0);
})->with(['saveSwitches', 'recordTransferAssessment', 'revokeAllConnections']);

// ---------------------------------------------------------------------------------------------
// Bảng nhân sự: chế độ, ngày cam kết và phiên bản, số kết nối, lần dùng cuối.
// ---------------------------------------------------------------------------------------------

it('bảng nhân sự hiện chế độ, ngày cam kết và phiên bản, số kết nối đang sống, lần dùng cuối', function () {
    $this->travelTo(now()->setDateTime(2026, 10, 5, 9, 30));

    $lawyer = aicLawyer(AiAccessMode::ReadWrite, ['name' => 'Luật Sư Một']);
    McpOAuth::accessToken($this, $lawyer, McpOAuth::client());
    McpOAuth::accessToken($this, $lawyer, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));

    $this->travelTo(now()->setDateTime(2026, 10, 6, 14, 15));
    aicToolRow($lawyer, 'search', '34.0.0.1');

    $idle = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Chưa Bật']);

    aicPage($this->admin)
        ->assertCanSeeTableRecords([$lawyer, $idle, $this->admin])
        ->assertTableColumnStateSet('ai_access', AiAccessMode::ReadWrite->label(), $lawyer)
        ->assertTableColumnStateSet('ai_access', AiAccessMode::Off->label(), $idle)
        ->assertTableColumnStateSet('connections', 2, $lawyer)
        ->assertTableColumnStateSet('connections', 0, $idle)
        ->assertTableColumnStateSet('last_used_at', '06/10/2026 14:15', $lawyer)
        ->assertTableColumnStateSet('last_used_at', __('ai_connections.admin.never_used'), $idle)
        ->assertTableColumnStateSet('acknowledged', __('ai_connections.admin.acknowledged_on', [
            'date' => '05/10/2026',
            'version' => config('vkcrm.mcp.policy_version'),
        ]), $lawyer)
        ->assertTableColumnStateSet('acknowledged', __('ai_connections.admin.not_acknowledged'), $idle);
});

it('bảng nhân sự đánh dấu lời cam kết của phiên bản cũ (chính sách vừa đổi), và không đếm kết nối đã thu hồi hay hết hạn', function () {
    $lawyer = aicLawyer();
    $token = McpOAuth::issueTokens($this, $lawyer);
    McpOAuth::accessToken($this, $lawyer);

    // Một kết nối đã thu hồi (access + refresh) không còn là "kết nối".
    $revokedId = McpOAuth::tokenId($token['access_token']);
    Passport::token()->newQuery()->whereKey($revokedId)->update(['revoked' => true]);
    Passport::refreshToken()->newQuery()->where('access_token_id', $revokedId)->update(['revoked' => true]);

    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    aicPage($this->admin)
        ->assertTableColumnStateSet('connections', 1, $lawyer)
        ->assertTableColumnStateSet('acknowledged', __('ai_connections.admin.acknowledged_outdated', [
            'date' => now()->format('d/m/Y'),
            'version' => (string) AiAcknowledgement::query()->where('user_id', $lawyer->getKey())->value('policy_version'),
        ]), $lawyer);
});

it('một kết nối có access token đã hết hạn nhưng refresh token còn sống vẫn được đếm (client sẽ làm mới)', function () {
    $lawyer = aicLawyer();
    McpOAuth::accessToken($this, $lawyer);

    Passport::token()->newQuery()->where('user_id', $lawyer->getKey())->update(['expires_at' => now()->subMinute()]);

    aicPage($this->admin)->assertTableColumnStateSet('connections', 1, $lawyer);

    Passport::refreshToken()->newQuery()->update(['expires_at' => now()->subMinute()]);

    aicPage($this->admin)->assertTableColumnStateSet('connections', 0, $lawyer);
});

// ---------------------------------------------------------------------------------------------
// Đổi chế độ (R2): audit, kế toán không có lựa chọn bật.
// ---------------------------------------------------------------------------------------------

it('đổi chế độ qua trang ghi ai_access_changed với causer là quản trị, from và to', function () {
    $lawyer = aicLawyer();

    aicPage($this->admin)
        ->callAction(TestAction::make('setAiAccess')->table($lawyer), data: ['ai_access' => AiAccessMode::ReadWrite->value])
        ->assertHasNoFormErrors();

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::ReadWrite);

    $row = Activity::query()->where('event', 'ai_access_changed')->sole();

    expect($row->causer_id)->toBe($this->admin->getKey())
        ->and($row->subject_id)->toBe($lawyer->getKey())
        ->and($row->properties->only(['from', 'to'])->all())->toBe(['from' => 'read', 'to' => 'read_write']);
});

it('hạ chế độ về Tắt qua trang: mọi token của người đó 401 ở request kế tiếp', function () {
    $lawyer = aicLawyer();
    $token = McpOAuth::accessToken($this, $lawyer);
    aicInitialize($token)->assertOk();

    aicPage($this->admin)
        ->callAction(TestAction::make('setAiAccess')->table($lawyer), data: ['ai_access' => AiAccessMode::Off->value])
        ->assertHasNoFormErrors();

    aicExpectRefused(aicInitialize($token));
});

it('kế toán không có lựa chọn bật: ô chế độ chỉ có Tắt, và ép giá trị Chỉ đọc bị từ chối, không ghi gì', function () {
    $accountant = User::factory()->position(UserPosition::Accountant)->withRole(Role::Accountant)->create();

    aicPage($this->admin)
        ->mountAction(TestAction::make('setAiAccess')->table($accountant))
        ->assertFormFieldExists('ai_access', fn (Select $field): bool => array_keys($field->getOptions()) === [AiAccessMode::Off->value])
        ->fillForm(['ai_access' => AiAccessMode::Read->value])
        ->callMountedAction()
        ->assertHasFormErrors(['ai_access']);

    expect($accountant->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(Activity::query()->where('event', 'ai_access_changed')->count())->toBe(0);
});

it('cặp dương: luật sư có đủ ba lựa chọn', function () {
    $lawyer = aicLawyer();

    aicPage($this->admin)
        ->mountAction(TestAction::make('setAiAccess')->table($lawyer))
        ->assertFormFieldExists('ai_access', fn (Select $field): bool => array_keys($field->getOptions()) === ['off', 'read', 'read_write']);
});

it('tài khoản đang bị vô hiệu hoá cũng chỉ có lựa chọn Tắt', function () {
    $lawyer = aicLawyer(attributes: ['is_active' => false]);

    aicPage($this->admin)
        ->mountAction(TestAction::make('setAiAccess')->table($lawyer))
        ->assertFormFieldExists('ai_access', fn (Select $field): bool => array_keys($field->getOptions()) === [AiAccessMode::Off->value]);
});

// ---------------------------------------------------------------------------------------------
// Chi tiết một người: danh sách kết nối, "Thu hồi" từng dòng, "Thu hồi tất cả" (R8).
// ---------------------------------------------------------------------------------------------

it('chi tiết một người liệt kê từng kết nối: nền tảng suy từ host redirect, chính host đó, ngày tạo, lần dùng cuối', function () {
    $this->travelTo(now()->setDateTime(2026, 10, 2, 8, 0));

    $lawyer = aicLawyer(attributes: ['name' => 'Luật Sư Chi Tiết']);
    $claude = McpOAuth::client();
    $chatgpt = McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']);
    McpOAuth::accessToken($this, $lawyer, $claude);
    McpOAuth::accessToken($this, $lawyer, $chatgpt);

    $this->travelTo(now()->setDateTime(2026, 10, 3, 16, 45));
    aicToolRow($lawyer, 'search', '34.0.0.9', ['oauth_client_id' => (string) $claude->getKey()]);

    $html = aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->assertSee('Luật Sư Chi Tiết')
        ->html();

    expect(aicCells($html, (string) $claude->getKey()))->toBe(['Claude', 'claude.ai', '02/10/2026 08:00', '03/10/2026 16:45'])
        ->and(aicCells($html, (string) $chatgpt->getKey()))->toBe(['ChatGPT', 'chatgpt.com', '02/10/2026 08:00', __('ai_connections.connections.never_used')]);
});

it('ngày tạo của một kết nối là lần đồng ý gần nhất (màn hình đồng ý OAuth), không phải token cũ nhất còn trong bảng', function () {
    $this->travelTo(now()->setDateTime(2026, 9, 1, 9, 0));

    $lawyer = aicLawyer();
    $client = McpOAuth::client();
    McpOAuth::accessToken($this, $lawyer, $client);

    // Kết nối lại cùng client (client CIMD dùng chung): lần đồng ý mới ghi một dòng nhật ký.
    $this->travelTo(now()->setDateTime(2026, 10, 4, 10, 30));
    app(RecordMcpConnectionDecision::class)->authorized($lawyer, (string) $client->getKey(), $client->redirect_uris[0]);
    McpOAuth::accessToken($this, $lawyer, $client);

    $html = aicPage($this->admin)->callAction(TestAction::make('showConnections')->table($lawyer))->html();

    expect(aicCells($html, (string) $client->getKey())[2] ?? null)->toBe('04/10/2026 10:30');
});

it('chi tiết hiện host của redirect, không bao giờ client_name tự khai', function () {
    $lawyer = aicLawyer();
    $client = app(RegisterMcpClient::class)->handle('Claude chính chủ', ['http://localhost/callback']);
    McpOAuth::accessToken($this, $lawyer, $client);

    aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->assertSee('localhost')
        ->assertSee(McpPlatform::LocalApp->label())
        ->assertDontSee('Claude chính chủ');
});

it('"Thu hồi" một dòng: token đó 401 ở request kế tiếp và refresh token của nó invalid_grant; token khác của cùng người vẫn chạy', function () {
    $lawyer = aicLawyer();
    $claude = McpOAuth::client();
    $chatgpt = McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']);
    $first = McpOAuth::issueTokens($this, $lawyer, $claude);
    $second = McpOAuth::issueTokens($this, $lawyer, $chatgpt);

    $html = aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->callAction(TestAction::make('revokeConnection')->arguments(['client' => (string) $claude->getKey()]))
        ->html();

    // Màn hình vẽ lại: dòng vừa thu hồi biến mất, dòng kia còn.
    expect(aicCells($html, (string) $claude->getKey()))->toBe([])
        ->and(aicCells($html, (string) $chatgpt->getKey()))->not->toBe([]);

    aicExpectRefused(aicInitialize($first['access_token']));
    aicRefresh((string) $claude->getKey(), $first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    aicInitialize($second['access_token'])->assertOk();

    $row = Activity::query()->where('event', 'ai_connections_revoked')->sole();

    expect($row->causer_id)->toBe($this->admin->getKey())
        ->and($row->subject_id)->toBe($lawyer->getKey())
        ->and($row->properties['reason'])->toBe('revoked_by_admin')
        ->and($row->properties['oauth_client_id'])->toBe((string) $claude->getKey())
        ->and($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Read);

    // Refresh token của kết nối còn lại vẫn làm mới được.
    aicRefresh((string) $chatgpt->getKey(), $second['refresh_token'])->assertOk();
});

it('"Thu hồi" thiếu id client không bao giờ rơi về "thu hồi tất cả": 404, mọi token còn sống', function () {
    $lawyer = aicLawyer();
    $first = McpOAuth::accessToken($this, $lawyer, McpOAuth::client());
    $second = McpOAuth::accessToken($this, $lawyer, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));

    aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->callAction(TestAction::make('revokeConnection')->arguments(['client' => '']))
        ->assertNotFound();

    aicInitialize($first)->assertOk();
    aicInitialize($second)->assertOk();

    expect(Activity::query()->where('event', 'ai_connections_revoked')->count())->toBe(0);
});

it('client đã bị thu hồi (oauth_clients.revoked) không còn là kết nối: không đếm, không liệt kê', function () {
    $lawyer = aicLawyer();
    $dead = McpOAuth::client();
    $alive = McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']);
    McpOAuth::accessToken($this, $lawyer, $dead);
    McpOAuth::accessToken($this, $lawyer, $alive);

    $dead->forceFill(['revoked' => true])->save();

    $html = aicPage($this->admin)
        ->assertTableColumnStateSet('connections', 1, $lawyer)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->html();

    expect(aicCells($html, (string) $dead->getKey()))->toBe([])
        ->and(aicCells($html, (string) $alive->getKey()))->not->toBe([]);
});

it('mọi hành động thật tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function (string $method) {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new AiConnections)->{$method}())->toThrow(NotFoundHttpException::class);

    expect(McpSwitches::enabled())->toBeTrue();
})->with(['saveSwitches', 'recordTransferAssessment', 'closeConnections']);

it('"Thu hồi" một dòng của client CIMD dùng chung chỉ thu hồi token CỦA NGƯỜI ĐÓ, không đụng người khác hay chính dòng client', function () {
    $lawyer = aicLawyer();
    $colleague = aicLawyer();
    $shared = McpOAuth::client();
    $mine = McpOAuth::accessToken($this, $lawyer, $shared);
    $theirs = McpOAuth::accessToken($this, $colleague, $shared);

    aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->callAction(TestAction::make('revokeConnection')->arguments(['client' => (string) $shared->getKey()]));

    aicExpectRefused(aicInitialize($mine));
    aicInitialize($theirs)->assertOk();

    expect((bool) $shared->fresh()->revoked)->toBeFalse();
});

it('"Thu hồi tất cả": mọi token của người đó 401 ở request kế tiếp, người khác vẫn chạy; chế độ giữ nguyên', function () {
    $lawyer = aicLawyer();
    $other = aicLawyer();
    $first = McpOAuth::accessToken($this, $lawyer, McpOAuth::client());
    $second = McpOAuth::accessToken($this, $lawyer, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));
    $untouched = McpOAuth::accessToken($this, $other);

    aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->callAction('revokeAllConnections');

    aicExpectRefused(aicInitialize($first));
    aicExpectRefused(aicInitialize($second));
    aicInitialize($untouched)->assertOk();

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('revoked_by_admin');
});

it('người được chọn đã bị xoá giữa chừng: chi tiết và nút thu hồi trả 404, không thu hồi gì', function () {
    $lawyer = aicLawyer();
    McpOAuth::accessToken($this, $lawyer);

    $page = aicPage($this->admin)
        ->callAction(TestAction::make('showConnections')->table($lawyer))
        ->mountAction('revokeAllConnections');

    $lawyer->delete();

    $page->callMountedAction()->assertNotFound();

    expect(Passport::token()->newQuery()->where('user_id', $lawyer->getKey())->where('revoked', false)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->count())->toBe(0);
});

it('người được chọn đã bị xoá giữa chừng: lần vẽ lại đầy đủ kế tiếp của trang trả 404', function () {
    $lawyer = aicLawyer();

    $page = aicPage($this->admin)->callAction(TestAction::make('showConnections')->table($lawyer));

    $lawyer->delete();

    $page->call('$refresh')->assertNotFound();
});

it('người được chọn không đặt được từ trình duyệt: thuộc tính bị khoá', function () {
    $lawyer = aicLawyer();

    expect(fn () => aicPage($this->admin)->set('selectedUserId', $lawyer->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// ---------------------------------------------------------------------------------------------
// Hai công tắc toàn hệ thống (R2).
// ---------------------------------------------------------------------------------------------

it('mở trang: hai công tắc hiện đúng giá trị đang lưu', function () {
    McpOAuth::openServer(enabled: true, write: true);

    aicPage($this->admin)
        ->assertSet('switches.enabled', true)
        ->assertSet('switches.write_enabled', true);

    McpOAuth::openServer(enabled: false, write: false);

    aicPage($this->admin)
        ->assertSet('switches.enabled', false)
        ->assertSet('switches.write_enabled', false);
});

it('tắt mcp.enabled qua trang: /mcp 401 ở request kế tiếp; bật lại thì mở; mỗi lần đổi một dòng ai_settings_updated', function () {
    $lawyer = aicLawyer();
    $token = McpOAuth::accessToken($this, $lawyer);
    aicInitialize($token)->assertOk();

    aicPage($this->admin)->set('switches.enabled', false)->call('saveSwitches')->assertHasNoErrors();

    aicExpectRefused(aicInitialize($token));

    aicPage($this->admin)->set('switches.enabled', true)->call('saveSwitches')->assertHasNoErrors();

    aicInitialize($token)->assertOk();

    $rows = Activity::query()->where('event', 'ai_settings_updated')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->causer_id)->toBe($this->admin->getKey())
        ->and($rows[0]->properties['changed'])->toBe(['enabled' => false])
        ->and($rows[1]->properties['changed'])->toBe(['enabled' => true]);
});

it('bật mcp.write_enabled qua trang: người read_write ghi được qua AI (McpAccess::canWrite); lưu lại không đổi gì thì không có dòng nhật ký', function () {
    $lawyer = aicLawyer(AiAccessMode::ReadWrite);

    expect(McpAccess::canWrite($lawyer))->toBeFalse();

    aicPage($this->admin)->set('switches.write_enabled', true)->call('saveSwitches')->assertHasNoErrors();

    expect(McpAccess::canWrite($lawyer->fresh()))->toBeTrue()
        ->and(Setting::query()->where('key', McpSwitches::WRITE_ENABLED)->value('value'))->toBe(McpSwitches::ON);

    aicPage($this->admin)->call('saveSwitches')->assertHasNoErrors();

    expect(Activity::query()->where('event', 'ai_settings_updated')->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Dải cảnh báo R12 mục 3 và ngày đã nộp hồ sơ.
// ---------------------------------------------------------------------------------------------

it('dải cảnh báo R12 mục 3 hiện khi chưa ghi ngày nộp hồ sơ; ghi ngày thì tắt dải, không tắt gì khác', function () {
    McpOAuth::openServer(enabled: true, write: true);

    aicPage($this->admin)
        ->assertSee(__('ai_connections.assessment.banner_title'))
        ->assertSee(__('ai_connections.assessment.items.transfer_assessment'))
        ->set('assessment.filed_on', '2026-10-01')
        ->call('recordTransferAssessment')
        ->assertHasNoErrors()
        ->assertDontSee(__('ai_connections.assessment.banner_title'))
        ->assertSee(__('ai_connections.assessment.filed_note', ['date' => '01/10/2026']));

    expect(Setting::query()->where('key', McpSwitches::TRANSFER_ASSESSMENT_FILED_ON)->value('value'))->toBe('2026-10-01')
        ->and(McpSwitches::enabled())->toBeTrue()
        ->and(McpSwitches::writeEnabled())->toBeTrue()
        ->and(Activity::query()->where('event', 'ai_settings_updated')->sole()->properties['changed'])
        ->toBe(['transfer_assessment_filed_on' => '2026-10-01']);

    aicPage($this->admin)->assertDontSee(__('ai_connections.assessment.banner_title'));
});

it('ngày nộp hồ sơ ở tương lai hay sai định dạng bị từ chối, dải cảnh báo còn nguyên', function (string $value) {
    $this->travelTo(now()->setDateTime(2026, 10, 7, 10, 0));

    aicPage($this->admin)
        ->set('assessment.filed_on', $value)
        ->call('recordTransferAssessment')
        ->assertHasErrors(['assessment.filed_on'])
        ->assertSee(__('ai_connections.assessment.banner_title'));

    expect(Setting::query()->where('key', McpSwitches::TRANSFER_ASSESSMENT_FILED_ON)->value('value'))->toBeNull();
})->with(['ngày mai' => '2026-10-08', 'sai định dạng' => '07/10/2026']);

it('giá trị lưu hỏng (không phải một ngày có thật dạng năm-tháng-ngày) không tắt được dải cảnh báo', function (string $stored) {
    app(WriteSettings::class)->handle([McpSwitches::TRANSFER_ASSESSMENT_FILED_ON => $stored], null);

    aicPage($this->admin)->assertSee(__('ai_connections.assessment.banner_title'));
})->with(['ngày không có thật' => '2026-02-30', 'sai định dạng' => '01/10/2026', 'chữ' => 'đã nộp']);

it('cặp dương: giá trị lưu đúng dạng tắt dải cảnh báo', function () {
    app(WriteSettings::class)->handle([McpSwitches::TRANSFER_ASSESSMENT_FILED_ON => '2026-02-28'], null);

    aicPage($this->admin)
        ->assertDontSee(__('ai_connections.assessment.banner_title'))
        ->assertSee(__('ai_connections.assessment.filed_note', ['date' => '28/02/2026']));
});

it('xoá ngày đã ghi thì dải cảnh báo hiện lại', function () {
    aicPage($this->admin)->set('assessment.filed_on', '2026-10-01')->call('recordTransferAssessment')->assertHasNoErrors();

    aicPage($this->admin)
        ->set('assessment.filed_on', null)
        ->call('recordTransferAssessment')
        ->assertHasNoErrors()
        ->assertSee(__('ai_connections.assessment.banner_title'));
});

// ---------------------------------------------------------------------------------------------
// Khối "Nhật ký MCP": 100 dòng gần nhất, lọc theo người và tool.
// ---------------------------------------------------------------------------------------------

it('khối Nhật ký MCP hiện 100 dòng gần nhất, mới nhất trước, kèm ghi chú IP là IP của nền tảng', function () {
    $lawyer = aicLawyer();

    foreach (range(1, 105) as $i) {
        aicToolRow($lawyer, 'search', '34.1.0.'.$i);
    }

    $html = aicPage($this->admin)
        ->assertSee(__('mcp_audit.page.ip_note'))
        ->html();

    expect(strpos($html, '34.1.0.105<'))->toBeLessThan(strpos($html, '34.1.0.104<'))
        ->and(substr_count($html, 'data-mcp-audit-row'))->toBe(100)
        ->and($html)->not->toContain('34.1.0.5<')
        ->and($html)->toContain('34.1.0.6<');
});

it('khối Nhật ký MCP lọc theo người và theo tool', function () {
    $alice = aicLawyer(attributes: ['name' => 'Người A']);
    $bob = aicLawyer(attributes: ['name' => 'Người B']);

    aicToolRow($alice, 'search', '34.0.0.1');
    aicToolRow($alice, 'fetch', '34.0.0.2');
    aicToolRow($bob, 'search', '34.0.0.3');

    aicPage($this->admin)
        ->assertFormFieldExists('tool', 'auditFiltersForm', fn (Select $field): bool => array_keys($field->getOptions()) === ['fetch', 'search'])
        ->set('auditFilters.user', $alice->getKey())
        ->assertSee('34.0.0.1')->assertSee('34.0.0.2')->assertDontSee('34.0.0.3')
        ->set('auditFilters.tool', 'search')
        ->assertSee('34.0.0.1')->assertDontSee('34.0.0.2')->assertDontSee('34.0.0.3')
        ->set('auditFilters.user', null)
        ->assertSee('34.0.0.1')->assertSee('34.0.0.3')->assertDontSee('34.0.0.2');
});

it('khối Nhật ký MCP gồm cả sự kiện kết nối (thu hồi, đổi chế độ) của người được lọc, không dòng nhật ký nào khác', function () {
    $lawyer = aicLawyer();
    McpOAuth::accessToken($this, $lawyer);

    aicPage($this->admin)
        ->callAction(TestAction::make('setAiAccess')->table($lawyer), data: ['ai_access' => AiAccessMode::Off->value]);

    // Một dòng KHÔNG thuộc kênh AI mà chính người đó là causer: không lọt vào khối, lọc hay không lọc.
    Audit::record('office_profile_updated', null, ['changed_fields' => ['hotline']], $lawyer);

    aicPage($this->admin)->assertDontSee(__('activity.events.office_profile_updated'));

    aicPage($this->admin)
        ->set('auditFilters.user', $lawyer->getKey())
        ->assertSee(__('activity.events.ai_access_changed'))
        ->assertSee(__('activity.events.ai_connections_revoked'))
        ->assertDontSee(__('activity.events.office_profile_updated'));
});

it('khối Nhật ký MCP lọc giá trị nhạy cảm qua SensitivePropertyFilter', function () {
    $lawyer = aicLawyer();

    aicToolRow($lawyer, 'search', '34.0.0.7', ['arguments' => ['id_number' => '079123456789', 'phone' => '0903123456']]);

    aicPage($this->admin)
        ->assertSee('34.0.0.7')
        ->assertDontSee('079123456789')
        ->assertDontSee('0903123456');
});
