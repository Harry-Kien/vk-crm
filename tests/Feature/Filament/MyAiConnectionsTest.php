<?php

use App\Enums\AiAccessMode;
use App\Enums\McpAccessRefusal;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\MyAiConnections;
use App\Models\AiAcknowledgement;
use App\Models\User;
use App\Support\Mcp\McpEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 15 — trang "Kết nối AI của tôi" của nhân sự (R2, R8, R12 mục 1)
|--------------------------------------------------------------------------
| Chế độ của tôi, chính sách và ô cam kết (không đánh dấu sẵn), URL MCP để dán vào client, kết nối
| của tôi kèm nút tự thu hồi [DC:144]. Mọi hành vi đo qua Livewire hoặc HTTP; hiệu lực của một lần
| thu hồi đo bằng một request `/mcp` THẬT ở request kế tiếp.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    McpOAuth::openServer();
});

afterEach(function () {
    Livewire::flushState();
});

function myaiInitialize(string $token): TestResponse
{
    Auth::forgetGuards();
    Once::flush();

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

function myaiLawyer(AiAccessMode $mode = AiAccessMode::Read, array $attributes = []): User
{
    return User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->withAiAccess($mode)->create($attributes);
}

function myaiPage(User $actor): mixed
{
    test()->actingAs($actor, 'web');

    return Livewire::test(MyAiConnections::class);
}

function myaiSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === MyAiConnections::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang Kết nối AI của tôi trong HTML.');
}

// ---------------------------------------------------------------------------------------------
// Cổng: nhân sự đang hoạt động có `matter.view`.
// ---------------------------------------------------------------------------------------------

it('mở được cho mọi vai có matter.view, ở ĐÚNG đường dẫn mà màn hình đồng ý OAuth trỏ tới', function (Role $role) {
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(McpEndpoint::myAiConnectionsUrl())
        ->assertOk()
        ->assertSee(__('ai_connections.mine.title'));

    expect(MyAiConnections::getUrl(panel: 'admin'))->toBe(McpEndpoint::myAiConnectionsUrl())
        ->and(MyAiConnections::shouldRegisterNavigation())->toBeTrue();
})->with([Role::Admin, Role::Manager, Role::Lawyer, Role::Assistant]);

it('kế toán (không có matter.view) nhận 404 và không thấy mục này trên thanh điều hướng', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web')
        ->get(MyAiConnections::getUrl(panel: 'admin'))
        ->assertNotFound();

    expect(MyAiConnections::shouldRegisterNavigation())->toBeFalse();
});

it('request cập nhật Livewire THẬT của người vừa thành kế toán: 404, không ghi cam kết nào', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $snapshot = myaiSnapshot($this->actingAs($lawyer, 'web')->get(MyAiConnections::getUrl(panel: 'admin'))->assertOk()->getContent());

    $lawyer->syncRoles([Role::Accountant->value]);
    $this->actingAs($lawyer->fresh(), 'web');

    $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => ['acknowledgement.acknowledged' => true], 'calls' => []]],
    ])->assertNotFound();

    expect(AiAcknowledgement::query()->count())->toBe(0);
});

it('cặp dương: cùng snapshot, người còn matter.view thì request cập nhật Livewire chạy', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $snapshot = myaiSnapshot($this->actingAs($lawyer, 'web')->get(MyAiConnections::getUrl(panel: 'admin'))->assertOk()->getContent());

    $this->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ])->assertOk();
});

it('acknowledge() và nút thu hồi tự hỏi lại cổng: người mất matter.view giữa chừng nhận 404, không ghi gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $page = myaiPage($lawyer)->set('acknowledgement.acknowledged', true);

    $lawyer->syncRoles([Role::Accountant->value]);
    $this->actingAs($lawyer->fresh(), 'web');

    $page->call('acknowledge')->assertNotFound();

    expect(AiAcknowledgement::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Nội dung: chế độ của tôi, URL MCP, chính sách.
// ---------------------------------------------------------------------------------------------

it('hiện chế độ của tôi, trạng thái dùng được, URL MCP để dán vào client và đường tới hướng dẫn', function () {
    $lawyer = myaiLawyer(AiAccessMode::ReadWrite);

    myaiPage($lawyer)
        ->assertSee(AiAccessMode::ReadWrite->label())
        ->assertSee(__('ai_connections.mine.status_ready'))
        ->assertSee(McpEndpoint::resource())
        ->assertSee(__('ai_connections.mine.guide'));
});

it('người chưa được bật: chế độ Tắt và câu lý do của McpAccess, không có trạng thái dùng được', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    myaiPage($lawyer)
        ->assertSee(AiAccessMode::Off->label())
        ->assertSee(McpAccessRefusal::AiAccessOff->label())
        ->assertDontSee(__('ai_connections.mine.status_ready'));
});

it('máy chủ AI đang tắt: trang nói đúng lý do đó', function () {
    McpOAuth::openServer(enabled: false);

    myaiPage(myaiLawyer())->assertSee(McpAccessRefusal::ServerDisabled->label());
});

// ---------------------------------------------------------------------------------------------
// Cam kết chính sách (R12 mục 1).
// ---------------------------------------------------------------------------------------------

/**
 * Rà soát cuối M11, I2 (Task 15 m2): nhân sự cam kết ĐÚNG câu chữ này dưới một phiên bản chính sách —
 * bản ghi "kiểm chứng được" mà Nghị định 356 đòi. Câu chỉ được hứa điều hệ thống làm: R8 ghi mỗi lần
 * gọi một tool (`tools/call`, `ToolCallContext::isToolCall()`), cùng kết nối, làm mới và thu hồi kết
 * nối; `tools/list`, `ping` và request không xác thực KHÔNG được ghi (`tests/Feature/Mcp/AuditTest.php`),
 * `initialize` cũng không (không phải `tools/call`). Sửa câu sau khi đã có người cam kết thì phải tăng phiên bản và
 * bắt mọi người cam kết lại, nên câu được ghim nguyên văn ở đây.
 */
it('chính sách chỉ hứa ghi nhật ký điều hệ thống thật sự ghi: mỗi lần dùng một chức năng (tool), không phải mọi lần gọi', function () {
    $promise = 'Mọi lần trợ lý AI dùng một chức năng (tool) của hệ thống đều được ghi nhật ký với tên anh/chị, cùng các lần kết nối, làm mới và thu hồi kết nối.';

    expect(__('ai_connections.policy.items.logged'))->toBe($promise)
        // Câu giới thiệu của trang quản trị "Kết nối AI" kể khối "Nhật ký MCP" bằng cùng phạm vi.
        ->and(__('ai_connections.admin.intro'))->toContain('đọc nhật ký mọi lần trợ lý AI dùng một chức năng (tool) của hệ thống')
        ->and(__('ai_connections.admin.intro'))->not->toContain('gọi vào hệ thống');

    myaiPage(User::factory()->withRole(Role::Lawyer)->create())
        ->assertSee($promise)
        ->assertDontSee('gọi vào hệ thống');
});

it('ô cam kết không đánh dấu sẵn; gửi khi chưa tích bị từ chối, không ghi gì', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    myaiPage($lawyer)
        ->assertSee(__('ai_connections.policy.items.never_exposed'))
        ->assertSet('acknowledgement.acknowledged', false)
        ->call('acknowledge')
        ->assertHasErrors(['acknowledgement.acknowledged']);

    expect(AiAcknowledgement::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'ai_policy_acknowledged')->count())->toBe(0);
});

it('tích rồi gửi: một dòng ai_acknowledgements đúng phiên bản, IP của request, audit causer chính người đó; ô cam kết biến mất', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    myaiPage($lawyer)
        ->set('acknowledgement.acknowledged', true)
        ->call('acknowledge')
        ->assertHasNoErrors()
        ->assertSee(__('ai_connections.policy.acknowledged', [
            'version' => config('vkcrm.mcp.policy_version'),
            'date' => now()->format('d/m/Y H:i'),
        ]))
        ->assertDontSee(__('ai_connections.policy.checkbox'));

    $row = AiAcknowledgement::query()->sole();

    expect($row->user_id)->toBe($lawyer->getKey())
        ->and($row->policy_version)->toBe(config('vkcrm.mcp.policy_version'))
        ->and($row->ip_address)->not->toBeNull()
        ->and(Activity::query()->where('event', 'ai_policy_acknowledged')->sole()->causer_id)->toBe($lawyer->getKey());
});

it('cam kết ở trang này mở được màn hình đồng ý OAuth: trước là màn hình từ chối "chưa cam kết", sau là màn hình có nút Đồng ý', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $lawyer->forceFill(['ai_access' => AiAccessMode::Read])->save();
    $client = McpOAuth::client();

    $query = fn (): string => '/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->redirect_uris[0],
        'scope' => 'mcp:use',
        'state' => 'trang-thai-thu',
        'code_challenge' => McpOAuth::pkce()['challenge'],
        'code_challenge_method' => 'S256',
    ]);

    $this->actingAs($lawyer, 'web')->get($query())
        ->assertForbidden()
        ->assertSeeText(McpAccessRefusal::PolicyNotAcknowledged->label())
        ->assertDontSee('data-consent="approve"', false);

    myaiPage($lawyer)->set('acknowledgement.acknowledged', true)->call('acknowledge')->assertHasNoErrors();

    $this->actingAs($lawyer->fresh(), 'web')->get($query())
        ->assertOk()
        ->assertSee('data-consent="approve"', false);
});

it('chính sách đổi phiên bản: ô cam kết hiện lại cho người đã cam kết bản cũ', function () {
    $lawyer = myaiLawyer();

    myaiPage($lawyer)->assertDontSee(__('ai_connections.policy.checkbox'));

    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    myaiPage($lawyer)->assertSee(__('ai_connections.policy.checkbox'));
});

// ---------------------------------------------------------------------------------------------
// Kết nối của tôi và nút tự thu hồi (R8, [DC:144]).
// ---------------------------------------------------------------------------------------------

it('liệt kê kết nối của chính mình, không bao giờ kết nối của người khác', function () {
    $alice = myaiLawyer();
    $bob = myaiLawyer();
    McpOAuth::accessToken($this, $alice, McpOAuth::client());
    McpOAuth::accessToken($this, $bob, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));

    myaiPage($alice)
        ->assertSee('claude.ai')
        ->assertDontSee('chatgpt.com');
});

it('tự thu hồi một kết nối: token đó 401 ở request kế tiếp, kết nối khác của tôi vẫn chạy; nhật ký ghi lý do tự thu hồi', function () {
    $lawyer = myaiLawyer();
    $claude = McpOAuth::client();
    $first = McpOAuth::accessToken($this, $lawyer, $claude);
    $second = McpOAuth::accessToken($this, $lawyer, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));

    myaiPage($lawyer)->callAction(TestAction::make('revokeConnection')->arguments(['client' => (string) $claude->getKey()]));

    expect(myaiInitialize($first)->status())->toBe(401);
    myaiInitialize($second)->assertOk();

    $row = Activity::query()->where('event', 'ai_connections_revoked')->sole();

    expect($row->causer_id)->toBe($lawyer->getKey())
        ->and($row->properties['reason'])->toBe('revoked_by_self')
        ->and($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Read);
});

it('người A gửi id client của người B lên trang của mình: không thu hồi gì của B', function () {
    $alice = myaiLawyer();
    $bob = myaiLawyer();
    $bobClient = McpOAuth::client();
    $bobToken = McpOAuth::accessToken($this, $bob, $bobClient);

    myaiPage($alice)->callAction(TestAction::make('revokeConnection')->arguments(['client' => (string) $bobClient->getKey()]));

    myaiInitialize($bobToken)->assertOk();

    expect(Activity::query()->where('event', 'ai_connections_revoked')->count())->toBe(0);
});

it('nút tự thu hồi thiếu id client không bao giờ rơi về "thu hồi tất cả": 404, mọi token còn sống', function () {
    $lawyer = myaiLawyer();
    $first = McpOAuth::accessToken($this, $lawyer, McpOAuth::client());
    $second = McpOAuth::accessToken($this, $lawyer, McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']));

    myaiPage($lawyer)
        ->callAction(TestAction::make('revokeConnection')->arguments(['client' => '']))
        ->assertNotFound();

    myaiInitialize($first)->assertOk();
    myaiInitialize($second)->assertOk();
});

it('acknowledge() tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');

    $page = new MyAiConnections;
    $page->acknowledgement = ['acknowledged' => true];

    expect(fn () => $page->acknowledge())->toThrow(NotFoundHttpException::class)
        ->and(AiAcknowledgement::query()->count())->toBe(0);
});
