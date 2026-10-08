<?php

use App\Actions\Mcp\AcknowledgeAiPolicy;
use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\Mcp\SetUserAiAccess;
use App\Actions\User\ResetStaffTwoFactor;
use App\Enums\AiAccessMode;
use App\Enums\AiRevocationReason;
use App\Enums\McpAccessRefusal;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Http\Middleware\Mcp\AuditToolCall;
use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Http\Middleware\Mcp\ThrottleMcp;
use App\Mcp\Methods\CrmToolInvoker;
use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\AiAcknowledgement;
use App\Models\ClientUser;
use App\Models\Setting;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpSwitches;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 6 — quyền truy cập theo người (R2), thu hồi (R8), cam kết (R12)
|--------------------------------------------------------------------------
| `EnsureMcpAccess` kiểm ở MỌI request `/mcp`, không lúc cấp token [DC:149]: tài khoản đang hoạt
| động, `users.ai_access` khác `off` (và người đó còn giữ được nó: có `matter.view`), công tắc toàn
| hệ thống `mcp.enabled` trong bảng `settings`, lời cam kết R12 đúng phiên bản hiện hành. Điều kiện
| "client mang cờ mcp" là `EnsureMcpClient` (Task 3), scope là `CheckToken` — cả hai đứng ngay
| trước.
|
| Mọi test của `/mcp` đi qua HTTP THẬT với token Passport THẬT (`Tests\Support\McpOAuth`), mỗi
| request như một tiến trình PHP-FPM mới ({@see aclFresh()}). Màn hình đi qua Livewire. Action của
| màn hình "Kết nối AI" — bật chế độ, cam kết — đo ở tầng Action ở đây (viết trước khi có màn hình);
| hai màn hình của Task 15 có test riêng: `tests/Feature/Filament/AiConnectionsTest.php`,
| `tests/Feature/Filament/MyAiConnectionsTest.php`.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer();

    app('translator')->addLines([
        'mcp.tools.acl_doc.title' => 'Thử đọc (Task 6)',
        'mcp.tools.acl_doc.description' => 'Dùng khi thử đọc. Không dùng để ghi.',
        'mcp.tools.acl_ghi.title' => 'Thử ghi (Task 6)',
        'mcp.tools.acl_ghi.description' => 'Dùng khi thử ghi. Không dùng để gửi gì.',
    ], 'vi');

    // Mỗi lần handler của một tool thử chạy, tên tool được thêm vào đây: "tool không chạy" là
    // một khẳng định đo được, không phải suy từ mã lỗi.
    app()->instance('acl.tool-calls', new ArrayObject);
});

/** Ứng dụng sống qua mọi request của một test; máy chủ thật thì không (xem `dcrInitialize()`). */
function aclFresh(): void
{
    Auth::forgetGuards();
    Once::flush();
}

function aclInitialize(string $token): TestResponse
{
    aclFresh();

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

/**
 * Một request stateless 2026-07-28 kèm ba header của đặc tả (`MCP-Protocol-Version`, `Mcp-Method`,
 * và `Mcp-Name` cho `tools/call`).
 *
 * @param  array<string, mixed>  $params
 */
function aclMcp(string $method, string $token, array $params = []): TestResponse
{
    aclFresh();

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $method,
    ];

    if (isset($params['name'])) {
        $headers['Mcp-Name'] = $params['name'];
    }

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => $method,
        'params' => [
            ...$params,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ],
        ],
    ], $headers);
}

function aclCallTool(string $name, string $token): TestResponse
{
    return aclMcp('tools/call', $token, ['name' => $name, 'arguments' => (object) []]);
}

/** @return list<string> */
function aclListedTools(string $token): array
{
    $response = aclMcp('tools/list', $token)->assertOk();

    return array_column($response->json('result.tools'), 'name');
}

/** 401 kèm `WWW-Authenticate` — client gửi bearer, nên header mang `error="invalid_token"`. */
function aclExpectRefused(TestResponse $response): void
{
    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toStartWith('Bearer ')
        ->toContain('error="invalid_token"');
}

/** Nhân sự đủ mọi điều kiện của R2 ở chế độ `$mode`, chức danh khớp vai. */
function aclStaff(AiAccessMode $mode = AiAccessMode::Read, UserPosition $position = UserPosition::Lawyer): User
{
    return User::factory()
        ->position($position)
        ->withRole(Role::fromPosition($position))
        ->withAiAccess($mode)
        ->create();
}

function aclAdmin(): User
{
    return User::factory()->admin()->create();
}

/** @return array{access: int, refresh: int, codes: int} số dòng CHƯA thu hồi của người này */
function aclLiveCredentials(User $user): array
{
    $accessIds = Passport::token()->newQuery()->where('user_id', $user->getKey())->pluck('id');

    return [
        'access' => Passport::token()->newQuery()->where('user_id', $user->getKey())->where('revoked', false)->count(),
        'refresh' => Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessIds)->where('revoked', false)->count(),
        'codes' => Passport::authCode()->newQuery()->where('user_id', $user->getKey())->where('revoked', false)->count(),
    ];
}

function aclRefresh(string $clientId, string $refreshToken): TestResponse
{
    aclFresh();

    return test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $refreshToken,
    ]);
}

/**
 * Thay `CrmServer` (chỉ trong test này) bằng một lớp con có các tool cho trước. `/mcp` phân giải lớp
 * server qua container (`Registrar::startServer()`), nên mọi middleware, guard và method của
 * request vẫn là mã thật.
 *
 * @param  list<Tool>  $tools
 */
function aclServeTools(array $tools): void
{
    app()->bind(CrmServer::class, function ($app, array $parameters) use ($tools) {
        $server = new class($parameters['transport']) extends CrmServer
        {
            /** @param  list<Tool>  $tools */
            public function useTools(array $tools): static
            {
                $this->tools = $tools;

                return $this;
            }
        };

        return $server->useTools($tools);
    });
}

function aclReadTool(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'acl_doc';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): Response
        {
            app('acl.tool-calls')[] = 'acl_doc';

            return Response::text('đã đọc');
        }
    };
}

function aclWriteTool(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'acl_ghi';

        protected function writes(): bool
        {
            return true;
        }

        public function handle(Request $request): Response
        {
            app('acl.tool-calls')[] = 'acl_ghi';

            return Response::text('đã ghi');
        }
    };
}

/** @return list<string> */
function aclToolCalls(): array
{
    return app('acl.tool-calls')->getArrayCopy();
}

/** Ép một giá trị thô vào công tắc, đi vòng Action (để thử cách ĐỌC giá trị). */
function aclRawSwitch(string $key, ?string $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

/** Làm sụp transaction ĐÚNG ở bước ghi audit thu hồi — bước cuối của `RevokeAiConnections`. */
function aclFailOnRevocationAudit(): void
{
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === 'ai_connections_revoked') {
            throw new RuntimeException('Sụp ở audit thu hồi (test).');
        }
    });
}

/*
|--------------------------------------------------------------------------
| EnsureMcpAccess: mỗi điều kiện một test HTTP, kèm cặp dương
|--------------------------------------------------------------------------
*/

it('R2 cặp dương: đang hoạt động, ai_access read, công tắc bật, cam kết đúng phiên bản, client mcp → initialize 200', function () {
    aclInitialize(McpOAuth::accessToken($this, aclStaff()))->assertOk();
});

it('R2 người bị vô hiệu hoá: cùng token đang mở được /mcp nhận 401 ngay ở request kế tiếp', function () {
    $staff = aclStaff();
    $token = McpOAuth::accessToken($this, $staff);

    aclInitialize($token)->assertOk();

    // Đi thẳng vào CSDL: thứ đang đo là điều kiện của middleware, không phải đường thu hồi.
    $staff->forceFill(['is_active' => false])->save();

    aclExpectRefused(aclInitialize($token));
});

it('R2 ai_access = off: cùng token nhận 401 ở request kế tiếp', function () {
    $staff = aclStaff();
    $token = McpOAuth::accessToken($this, $staff);

    aclInitialize($token)->assertOk();

    $staff->forceFill(['ai_access' => AiAccessMode::Off])->save();

    aclExpectRefused(aclInitialize($token));
});

it('R2 ai_access read_write cũng mở được /mcp (không chỉ read)', function () {
    aclInitialize(McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite)))->assertOk();
});

it('R2 công tắc toàn hệ thống tắt: mọi request /mcp nhận 401 ngay ở request kế tiếp; bật lại thì mở', function () {
    $token = McpOAuth::accessToken($this, aclStaff());

    aclInitialize($token)->assertOk();

    McpOAuth::openServer(enabled: false);
    aclExpectRefused(aclInitialize($token));

    McpOAuth::openServer();
    aclInitialize($token)->assertOk();
});

it('R2 công tắc chưa từng được lưu (không có dòng settings) là TẮT: mặc định đóng', function () {
    $token = McpOAuth::accessToken($this, aclStaff());
    Setting::query()->where('key', McpSwitches::ENABLED)->delete();

    aclExpectRefused(aclInitialize($token));
});

it('R2 công tắc chỉ bật với đúng chuỗi "1": giá trị gõ sai, rỗng hay null đều là tắt', function (?string $value) {
    $token = McpOAuth::accessToken($this, aclStaff());
    aclRawSwitch(McpSwitches::ENABLED, $value);

    aclExpectRefused(aclInitialize($token));
})->with([
    'true' => ['true'],
    'on' => ['on'],
    'khoảng trắng trước' => [' 1'],
    '01' => ['01'],
    'rỗng' => [''],
    'null' => [null],
]);

it('R12 chưa cam kết lần nào: 401 dù quản trị đã bật', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $staff->forceFill(['ai_access' => AiAccessMode::Read])->save();

    aclExpectRefused(aclInitialize(McpOAuth::accessToken($this, $staff)));
});

it('R12 đổi phiên bản chính sách: mọi kết nối ngừng ở request kế tiếp, cho tới khi người đó cam kết lại', function () {
    $staff = aclStaff();
    $token = McpOAuth::accessToken($this, $staff);

    aclInitialize($token)->assertOk();

    config(['vkcrm.mcp.policy_version' => 'thu-nghiem-2']);
    aclExpectRefused(aclInitialize($token));

    app(AcknowledgeAiPolicy::class)->handle($staff, true, '203.0.113.7', 'Trình duyệt thử');
    aclInitialize($token)->assertOk();
});

it('R12 cam kết của NGƯỜI KHÁC đúng phiên bản không mở được /mcp cho người này', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $staff->forceFill(['ai_access' => AiAccessMode::Read])->save();
    aclStaff(); // người khác, đã cam kết đúng phiên bản

    aclExpectRefused(aclInitialize(McpOAuth::accessToken($this, $staff)));
});

it('R12 phiên bản chính sách trống trong cấu hình: không ai qua được (đóng, không mở)', function () {
    $token = McpOAuth::accessToken($this, aclStaff());
    config(['vkcrm.mcp.policy_version' => '']);

    aclExpectRefused(aclInitialize($token));
});

it('R2 client không mang cờ is_mcp: 401 dù người dùng đủ mọi điều kiện còn lại', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Client ngoài MCP', [McpOAuth::REDIRECT_URI], confidential: false,
    );

    aclExpectRefused(aclInitialize(McpOAuth::accessToken($this, aclStaff(), $client)));
});

it('R2 kế toán bị ép ai_access read thẳng trong CSDL (đi vòng Action): vẫn 401 vì không có matter.view', function () {
    $accountant = aclStaff(AiAccessMode::Read, UserPosition::Accountant);

    expect($accountant->ai_access)->toBe(AiAccessMode::Read)
        ->and($accountant->can('matter.view'))->toBeFalse()
        // Task 4 (rà soát Task 6, m6): lý do riêng, không phải "quản trị chưa bật" — màn hình đồng ý
        // hiện nhãn của lý do cho chính người đó.
        ->and(McpAccess::refusal($accountant))->toBe(McpAccessRefusal::NoMatterView);

    aclExpectRefused(aclInitialize(McpOAuth::accessToken($this, $accountant)));
});

it('R2 vai nào có matter.view (luật sư, trợ lý, trưởng phòng, quản trị) thì mở được', function (UserPosition $position) {
    aclInitialize(McpOAuth::accessToken($this, aclStaff(AiAccessMode::Read, $position)))->assertOk();
})->with([
    'luật sư' => [UserPosition::Lawyer],
    'trợ lý' => [UserPosition::Assistant],
    'trưởng phòng' => [UserPosition::Manager],
    'quản trị' => [UserPosition::Admin],
]);

it('R2 người dùng đã xoá mềm: 401', function () {
    $staff = aclStaff();
    $token = McpOAuth::accessToken($this, $staff);

    $staff->delete();

    aclExpectRefused(aclInitialize($token));
});

/*
 * Rà soát Task 8, I3: giữa CheckToken và EnsureMcpAccess chỉ có hai middleware của audit/rate limit
 * (AuditToolCall, ThrottleMcp) — để một tools/call bị EnsureMcpAccess từ chối vẫn có dòng nhật ký.
 * Không bước kiểm quyền nào khác đứng giữa, và EnsureMcpAccess vẫn là middleware cuối của app.
 */
it('R2 EnsureMcpAccess đứng sau CheckToken mcp:use, chỉ cách bởi AuditToolCall và ThrottleMcp, cuối danh sách middleware của app', function () {
    $middleware = Route::getRoutes()->match(request()->create('/mcp', 'POST'))->gatherMiddleware();

    $scopeAt = array_search(CheckToken::using('mcp:use'), $middleware, true);
    $accessAt = array_search(EnsureMcpAccess::class, $middleware, true);

    expect($scopeAt)->not->toBeFalse()
        ->and(array_slice($middleware, $scopeAt + 1, 2))->toBe([AuditToolCall::class, ThrottleMcp::class])
        ->and($accessAt)->toBe($scopeAt + 3)
        ->and($accessAt)->toBe(count($middleware) - 1);
});

it('R2 McpAccess::refusal() trả lý do ĐẦU TIÊN theo thứ tự của R2', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    Setting::query()->where('key', McpSwitches::ENABLED)->delete();

    expect(McpAccess::refusal($staff))->toBe(McpAccessRefusal::Inactive);

    $staff->forceFill(['is_active' => true])->save();
    expect(McpAccess::refusal($staff))->toBe(McpAccessRefusal::AiAccessOff);

    $staff->forceFill(['ai_access' => AiAccessMode::Read])->save();
    expect(McpAccess::refusal($staff))->toBe(McpAccessRefusal::ServerDisabled);

    McpOAuth::openServer();
    expect(McpAccess::refusal($staff))->toBe(McpAccessRefusal::PolicyNotAcknowledged);

    AiAcknowledgement::factory()->for($staff)->create();
    expect(McpAccess::refusal($staff))->toBeNull();

    // Xoá mềm cũng là "không hoạt động" (màn hình đồng ý của Task 4 hỏi người của phiên `web`,
    // không qua provider của guard `mcp`, nơi người đã xoá mềm không nạp được).
    $staff->delete();
    expect(McpAccess::refusal($staff))->toBe(McpAccessRefusal::Inactive);
});

it('R12 phiên bản chính sách dài hơn cột (20 ký tự): coi như trống — không ai qua, không ai cam kết được', function () {
    $staff = aclStaff();
    config(['vkcrm.mcp.policy_version' => str_repeat('a', 21)]);

    expect(McpAccess::policyVersion())->toBeNull()
        ->and(McpAccess::refusal($staff))->toBe(McpAccessRefusal::PolicyNotAcknowledged)
        ->and(fn () => app(AcknowledgeAiPolicy::class)->handle($staff, true, null, null))->toThrow(LogicException::class);

    // Không phải chuỗi (ai đó ghi số 2026 thay vì '2026'): cũng đóng.
    config(['vkcrm.mcp.policy_version' => 2026]);
    expect(McpAccess::policyVersion())->toBeNull();

    config(['vkcrm.mcp.policy_version' => str_repeat('a', 20)]);
    expect(McpAccess::policyVersion())->toBe(str_repeat('a', 20));
});

it('R13 McpAccess::canWrite(): chỉ khi dùng được máy chủ, chế độ read_write VÀ cả hai công tắc bật', function () {
    McpOAuth::openServer(write: true);
    $writer = aclStaff(AiAccessMode::ReadWrite);

    expect(McpAccess::canWrite($writer))->toBeTrue()
        ->and(McpAccess::canWrite(aclStaff(AiAccessMode::Read)))->toBeFalse();

    // read_write nhưng không còn dùng được máy chủ (ở đây: bị vô hiệu hoá).
    $inactive = aclStaff(AiAccessMode::ReadWrite);
    $inactive->forceFill(['is_active' => false])->save();
    expect(McpAccess::canWrite($inactive))->toBeFalse();

    // Công tắc ghi bật nhưng công tắc chung tắt: không ghi được (và McpSwitches nói như vậy).
    aclRawSwitch(McpSwitches::ENABLED, McpSwitches::OFF);
    aclRawSwitch(McpSwitches::WRITE_ENABLED, McpSwitches::ON);
    expect(McpSwitches::writeEnabled())->toBeFalse()
        ->and(McpAccess::canWrite($writer))->toBeFalse();
});

it('R2 EnsureMcpAccess đứng một mình (lỡ bị đặt trước auth:mcp): không có người của guard mcp thì 401, không lỗi 500', function () {
    Route::post('/_probe/mcp-access', fn () => ['ok' => true])->middleware(EnsureMcpAccess::class);

    $this->postJson('/_probe/mcp-access')->assertUnauthorized();
});

it('R13 CrmTool::shouldRegister(): tool ghi chỉ đăng ký cho người của guard mcp ghi được; không có người thì không; tool đọc luôn đăng ký', function () {
    McpOAuth::openServer(write: true);

    // Hỏi qua đúng đường của laravel/mcp (`eligibleForRegistration()` → `Container::call()`), vì từ M11
    // Task 14 `shouldRegister()` nhận request MCP qua container.
    expect(aclWriteTool()->eligibleForRegistration())->toBeFalse()
        ->and(aclReadTool()->eligibleForRegistration())->toBeTrue();

    Auth::guard('mcp')->setUser(aclStaff(AiAccessMode::ReadWrite));
    expect(aclWriteTool()->eligibleForRegistration())->toBeTrue();

    Auth::guard('mcp')->setUser(aclStaff(AiAccessMode::Read));
    expect(aclWriteTool()->eligibleForRegistration())->toBeFalse();
});

it('R13 bước gọi tool không có người của guard mcp: tool ghi bị từ chối (isError), handler không chạy', function () {
    McpOAuth::openServer(write: true);

    $response = app(CrmToolInvoker::class)->invoke(
        aclWriteTool(),
        new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => 'acl_ghi', 'arguments' => []]),
    );

    expect($response->toArray()['result']['isError'])->toBeTrue()
        ->and($response->toArray()['result']['content'][0]['text'])->toBe(__('ai_access.tools.write_refused'))
        ->and(aclToolCalls())->toBe([]);
});

it('R2 mỗi lý do từ chối có nhãn tiếng Việt cho màn hình đồng ý (Task 4), và không hai lý do nào chung một câu', function () {
    $labels = array_map(fn (McpAccessRefusal $refusal) => $refusal->label(), McpAccessRefusal::cases());

    expect(array_unique($labels))->toHaveCount(count(McpAccessRefusal::cases()));

    foreach (McpAccessRefusal::cases() as $refusal) {
        expect($refusal->label())->not->toBe('enums.mcp_access_refusal.'.$refusal->value)
            ->and($refusal->label())->not->toBeEmpty();
    }
});

/*
|--------------------------------------------------------------------------
| SetUserAiAccess (R2) — Action của màn hình "Kết nối AI" (màn hình ở Task 15)
|--------------------------------------------------------------------------
*/

it('R2 cột users.ai_access mặc định off, và không nằm trong $fillable', function () {
    $id = DB::table('users')->insertGetId([
        'name' => 'Nhân sự cũ',
        'email' => 'nhansucu@luatvukhang.com',
        'password' => Hash::make('password'),
        'position' => UserPosition::Lawyer->value,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('users')->where('id', $id)->value('ai_access'))->toBe('off')
        ->and(User::factory()->make()->ai_access)->toBe(AiAccessMode::Off)
        ->and((new User)->isFillable('ai_access'))->toBeFalse();

    $user = User::findOrFail($id);
    $user->fill(['ai_access' => 'read_write'])->save();

    expect($user->fresh()->ai_access)->toBe(AiAccessMode::Off);
});

it('R2 admin bật read rồi read_write cho một luật sư: lưu, mỗi lần đổi một dòng ai_access_changed, causer là admin', function () {
    $admin = aclAdmin();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    app(SetUserAiAccess::class)->handle($lawyer, AiAccessMode::Read, $admin);
    app(SetUserAiAccess::class)->handle($lawyer, AiAccessMode::ReadWrite, $admin);

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::ReadWrite);

    $rows = Activity::query()->where('event', 'ai_access_changed')->where('subject_id', $lawyer->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->properties->only(['from', 'to'])->all())->toBe(['from' => 'off', 'to' => 'read'])
        ->and($rows[1]->properties->only(['from', 'to'])->all())->toBe(['from' => 'read', 'to' => 'read_write'])
        ->and($rows[0]->causer?->is($admin))->toBeTrue()
        ->and($rows[1]->causer?->is($admin))->toBeTrue();
});

it('R2 từ chối bật cho kế toán (thiếu matter.view) bằng một câu tiếng Việt, không ghi gì', function (AiAccessMode $mode) {
    $admin = aclAdmin();
    $accountant = User::factory()->position(UserPosition::Accountant)->withRole(Role::Accountant)->create();

    expect(fn () => app(SetUserAiAccess::class)->handle($accountant, $mode, $admin))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toBe(['ai_access' => [__('ai_access.validation.needs_matter_view')]]);
        });

    expect($accountant->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(Activity::query()->where('event', 'ai_access_changed')->exists())->toBeFalse();
})->with([
    'read' => [AiAccessMode::Read],
    'read_write' => [AiAccessMode::ReadWrite],
]);

it('R2 từ chối bật cho một tài khoản đang bị vô hiệu hoá', function () {
    $admin = aclAdmin();
    $inactive = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    expect(fn () => app(SetUserAiAccess::class)->handle($inactive, AiAccessMode::Read, $admin))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toBe(['ai_access' => [__('ai_access.validation.inactive')]]);
        });

    expect($inactive->fresh()->ai_access)->toBe(AiAccessMode::Off);
});

it('R2 TẮT thì luôn được, kể cả với người không còn giữ được quyền (kế toán bị ép bật)', function () {
    $admin = aclAdmin();
    $accountant = aclStaff(AiAccessMode::Read, UserPosition::Accountant);

    app(SetUserAiAccess::class)->handle($accountant, AiAccessMode::Off, $admin);

    expect($accountant->fresh()->ai_access)->toBe(AiAccessMode::Off);
});

it('R2 người thiếu settings.manage gọi: AuthorizationException, không ghi gì', function (UserPosition $position) {
    $actor = User::factory()->position($position)->withRole(Role::fromPosition($position))->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(SetUserAiAccess::class)->handle($lawyer, AiAccessMode::Read, $actor))
        ->toThrow(AuthorizationException::class);

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(Activity::query()->where('event', 'ai_access_changed')->exists())->toBeFalse();
})->with([
    'trưởng phòng' => [UserPosition::Manager],
    'luật sư' => [UserPosition::Lawyer],
]);

it('R2 nhân sự không tự bật cho chính mình nếu không có settings.manage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(SetUserAiAccess::class)->handle($lawyer, AiAccessMode::ReadWrite, $lawyer))
        ->toThrow(AuthorizationException::class);

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Off);
});

it('R2 đặt lại đúng chế độ đang có: không ghi, không audit', function () {
    $admin = aclAdmin();
    $staff = aclStaff();

    app(SetUserAiAccess::class)->handle($staff, AiAccessMode::Read, $admin);

    expect(Activity::query()->where('event', 'ai_access_changed')->exists())->toBeFalse();
});

it('R2/R8 hạ về off: access token, refresh token và mã uỷ quyền của người đó bị thu hồi cùng lúc, request kế tiếp 401, làm mới bị từ chối', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    $client = McpOAuth::client();
    $tokens = McpOAuth::issueTokens($this, $staff, $client);
    McpOAuth::authorizationCode($staff, $client);
    $other = aclStaff();
    McpOAuth::issueTokens($this, $other);

    expect(aclLiveCredentials($staff))->toBe(['access' => 1, 'refresh' => 1, 'codes' => 1]);

    app(SetUserAiAccess::class)->handle($staff, AiAccessMode::Off, $admin);

    expect(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and(aclLiveCredentials($other))->toBe(['access' => 1, 'refresh' => 1, 'codes' => 0]);

    // Đúng MỘT dòng đổi chế độ (của SetUserAiAccess): bước thu hồi không ghi thêm "off → off".
    expect(Activity::query()->where('event', 'ai_access_changed')->sole()->properties->all())
        ->toBe(['from' => 'read', 'to' => 'off']);

    $revoked = Activity::query()->where('event', 'ai_connections_revoked')->sole();

    expect($revoked->subject?->is($staff))->toBeTrue()
        ->and($revoked->causer?->is($admin))->toBeTrue()
        ->and($revoked->properties->all())->toBe([
            'reason' => 'ai_access_off',
            'access_tokens' => 1,
            'refresh_tokens' => 1,
            'authorization_codes' => 1,
        ]);

    aclExpectRefused(aclInitialize($tokens['access_token']));
    aclRefresh($client->getKey(), $tokens['refresh_token'])->assertStatus(400);
});

it('R2 hạ read_write về read: token cũ còn hạn vẫn đọc được, nhưng tool ghi bị từ chối ở request kế tiếp; token KHÔNG bị thu hồi', function () {
    McpOAuth::openServer(write: true);
    aclServeTools([aclReadTool(), aclWriteTool()]);
    $admin = aclAdmin();
    $staff = aclStaff(AiAccessMode::ReadWrite);
    $token = McpOAuth::accessToken($this, $staff);

    aclCallTool('acl_ghi', $token)->assertOk()->assertJsonPath('result.isError', false);
    expect(aclToolCalls())->toBe(['acl_ghi']);

    app(SetUserAiAccess::class)->handle($staff, AiAccessMode::Read, $admin);

    $refused = aclCallTool('acl_ghi', $token);
    // M11 Task 13 (rà soát Task 6, m5): tên tool ghi client còn giữ trong danh sách cũ nhận câu tiếng Việt
    // (isError, HTTP 200), không còn -32602 "Tool not found" tiếng Anh qua HTTP 400.
    $refused->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('ai_access.tools.write_refused'));

    aclCallTool('acl_doc', $token)->assertOk()->assertJsonPath('result.isError', false);

    expect(aclToolCalls())->toBe(['acl_ghi', 'acl_doc'])
        ->and(aclLiveCredentials($staff)['access'])->toBe(1)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| R13 — danh sách tool theo quyền; handler vẫn kiểm lại
|--------------------------------------------------------------------------
*/

it('R13 read_write và mcp.write_enabled bật: tools/list có tool ghi, gọi được', function () {
    McpOAuth::openServer(write: true);
    aclServeTools([aclReadTool(), aclWriteTool()]);
    $token = McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite));

    expect(aclListedTools($token))->toBe(['acl_doc', 'acl_ghi']);

    aclCallTool('acl_ghi', $token)->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content.0.text', 'đã ghi');
});

it('R13 người read: tools/list không có tool ghi; gọi thẳng tên tool ghi bị từ chối, handler không chạy', function () {
    McpOAuth::openServer(write: true);
    aclServeTools([aclReadTool(), aclWriteTool()]);
    $token = McpOAuth::accessToken($this, aclStaff(AiAccessMode::Read));

    expect(aclListedTools($token))->toBe(['acl_doc']);

    aclCallTool('acl_ghi', $token)->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('ai_access.tools.write_refused'));

    expect(aclToolCalls())->toBe([]);
});

it('R13 read_write nhưng công tắc mcp.write_enabled tắt: như người read', function () {
    McpOAuth::openServer(write: false);
    aclServeTools([aclReadTool(), aclWriteTool()]);
    $token = McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite));

    expect(aclListedTools($token))->toBe(['acl_doc']);

    aclCallTool('acl_ghi', $token)->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('ai_access.tools.write_refused'));

    expect(aclToolCalls())->toBe([]);
});

it('R13 mcp.write_enabled chỉ bật với đúng chuỗi "1"', function (?string $value) {
    aclServeTools([aclReadTool(), aclWriteTool()]);
    $token = McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite));
    aclRawSwitch(McpSwitches::WRITE_ENABLED, $value);

    expect(aclListedTools($token))->toBe(['acl_doc']);
})->with([
    'true' => ['true'],
    'khoảng trắng' => ['1 '],
    'null' => [null],
]);

it('R13 handler vẫn kiểm lại: tool ghi lỡ tự cho mình đăng ký với mọi người vẫn bị từ chối khi gọi (isError, câu tiếng Việt), handler không chạy', function () {
    McpOAuth::openServer(write: true);
    $leaky = new class extends CrmTool
    {
        protected string $name = 'acl_ghi';

        protected function writes(): bool
        {
            return true;
        }

        public function shouldRegister(Request $request): bool
        {
            return true;
        }

        public function handle(Request $request): Response
        {
            app('acl.tool-calls')[] = 'acl_ghi';

            return Response::text('đã ghi');
        }
    };
    aclServeTools([$leaky]);

    $reader = McpOAuth::accessToken($this, aclStaff(AiAccessMode::Read));

    aclCallTool('acl_ghi', $reader)->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('ai_access.tools.write_refused'));

    expect(aclToolCalls())->toBe([]);

    // Cặp dương: cùng tool, người read_write → chạy.
    aclCallTool('acl_ghi', McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite)))
        ->assertOk()->assertJsonPath('result.isError', false);

    expect(aclToolCalls())->toBe(['acl_ghi']);
});

it('R13 tool không kế thừa CrmTool trên CrmServer không bao giờ chạy: không biết nó đọc hay ghi thì từ chối', function () {
    McpOAuth::openServer(write: true);
    $foreign = new class extends Tool
    {
        protected string $name = 'acl_ngoai';

        protected string $description = 'Tool không qua lớp cơ sở.';

        public function handle(Request $request): Response
        {
            app('acl.tool-calls')[] = 'acl_ngoai';

            return Response::text('đã chạy');
        }
    };
    aclServeTools([$foreign]);

    aclCallTool('acl_ngoai', McpOAuth::accessToken($this, aclStaff(AiAccessMode::ReadWrite)))
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('ai_access.tools.unavailable'));

    expect(aclToolCalls())->toBe([]);
});

it('R13 tool đọc vẫn chạy cho người read (cặp dương của mọi từ chối ghi ở trên)', function () {
    aclServeTools([aclReadTool(), aclWriteTool()]);

    aclCallTool('acl_doc', McpOAuth::accessToken($this, aclStaff(AiAccessMode::Read)))
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.content.0.text', 'đã đọc');
});

/*
|--------------------------------------------------------------------------
| AcknowledgeAiPolicy (R12 mục 1) — Action của "Kết nối AI của tôi" (màn hình ở Task 15)
|--------------------------------------------------------------------------
*/

it('R12 gửi cam kết KHÔNG tích: lỗi validation tiếng Việt, không ghi gì', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => app(AcknowledgeAiPolicy::class)->handle($staff, false, '203.0.113.7', 'Trình duyệt'))
        ->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toBe(['acknowledged' => [__('ai_access.validation.acknowledgement_required')]]);
        });

    expect(AiAcknowledgement::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'ai_policy_acknowledged')->exists())->toBeFalse();
});

it('R12 cam kết có tích: lưu user_id, phiên bản hiện hành, thời điểm, IP, user agent; một dòng audit, causer là chính người đó', function () {
    $this->freezeSecond();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $acknowledgement = app(AcknowledgeAiPolicy::class)->handle($staff, true, '203.0.113.7', 'Mozilla/5.0 thử');

    expect($acknowledgement->fresh()->only(['user_id', 'policy_version', 'ip_address', 'user_agent']))->toBe([
        'user_id' => $staff->id,
        'policy_version' => config('vkcrm.mcp.policy_version'),
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 thử',
    ])->and($acknowledgement->fresh()->accepted_at->equalTo(now()))->toBeTrue();

    $audit = Activity::query()->where('event', 'ai_policy_acknowledged')->sole();

    expect($audit->subject?->is($staff))->toBeTrue()
        ->and($audit->causer?->is($staff))->toBeTrue()
        ->and($audit->properties->all())->toBe(['policy_version' => config('vkcrm.mcp.policy_version')]);
});

it('R12 cam kết lại cùng phiên bản: trả đúng dòng đã có, không dòng thứ hai, không audit thứ hai', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $first = app(AcknowledgeAiPolicy::class)->handle($staff, true, '203.0.113.7', 'A');
    $second = app(AcknowledgeAiPolicy::class)->handle($staff, true, '198.51.100.1', 'B');

    expect($second->is($first))->toBeTrue()
        ->and(AiAcknowledgement::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'ai_policy_acknowledged')->count())->toBe(1);
});

it('R12 phiên bản mới: một dòng mới, dòng cũ giữ nguyên làm bằng chứng', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $original = (string) config('vkcrm.mcp.policy_version');
    app(AcknowledgeAiPolicy::class)->handle($staff, true, null, null);

    config(['vkcrm.mcp.policy_version' => 'thu-nghiem-2']);
    app(AcknowledgeAiPolicy::class)->handle($staff, true, null, null);

    expect(AiAcknowledgement::query()->where('user_id', $staff->id)->orderBy('id')->pluck('policy_version')->all())
        ->toBe([$original, 'thu-nghiem-2']);
});

it('R12 user agent dài hơn cột bị cắt đúng 500 ký tự', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $acknowledgement = app(AcknowledgeAiPolicy::class)->handle($staff, true, null, str_repeat('ă', 700));

    expect(mb_strlen($acknowledgement->fresh()->user_agent))->toBe(AcknowledgeAiPolicy::USER_AGENT_MAX_LENGTH)
        ->and(AcknowledgeAiPolicy::USER_AGENT_MAX_LENGTH)->toBe(500);
});

it('R12 địa chỉ IP dài hơn cột (45 ký tự) bị cắt; IP rỗng lưu null', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $acknowledgement = app(AcknowledgeAiPolicy::class)->handle($staff, true, 'fe80:0000:0000:0000:0204:61ff:fe9d:f156%enp0s31f6', '');

    expect($acknowledgement->fresh()->ip_address)->toBe('fe80:0000:0000:0000:0204:61ff:fe9d:f156%enp0s')
        ->and(mb_strlen($acknowledgement->fresh()->ip_address))->toBe(AcknowledgeAiPolicy::IP_ADDRESS_MAX_LENGTH)
        ->and($acknowledgement->fresh()->user_agent)->toBeNull();

    $other = app(AcknowledgeAiPolicy::class)->handle(User::factory()->withRole(Role::Lawyer)->create(), true, '', 'Trình duyệt');

    expect($other->fresh()->ip_address)->toBeNull();
});

it('R12 hai nhân sự cam kết cùng phiên bản: mỗi người một dòng của chính mình', function () {
    $first = User::factory()->withRole(Role::Lawyer)->create();
    $second = User::factory()->withRole(Role::Lawyer)->create();

    $a = app(AcknowledgeAiPolicy::class)->handle($first, true, null, null);
    $b = app(AcknowledgeAiPolicy::class)->handle($second, true, null, null);

    expect($a->is($b))->toBeFalse()
        ->and($b->user_id)->toBe($second->id)
        ->and(AiAcknowledgement::query()->count())->toBe(2);
});

it('R12 phiên bản chính sách trống trong cấu hình: không cam kết được (LogicException), không ghi gì', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    config(['vkcrm.mcp.policy_version' => '  ']);

    expect(fn () => app(AcknowledgeAiPolicy::class)->handle($staff, true, null, null))->toThrow(LogicException::class);
    expect(AiAcknowledgement::query()->count())->toBe(0);
});

it('R12 phiên bản chính sách trong cấu hình vừa cột policy_version (≤ 20 ký tự), không rỗng', function () {
    $version = config('vkcrm.mcp.policy_version');

    expect($version)->toBeString()->not->toBe('')
        ->and(mb_strlen($version))->toBeLessThanOrEqual(AcknowledgeAiPolicy::POLICY_VERSION_MAX_LENGTH)
        ->and(AcknowledgeAiPolicy::POLICY_VERSION_MAX_LENGTH)->toBe(20);
});

/*
|--------------------------------------------------------------------------
| R8 — các sự kiện thu hồi mọi token của người đó TRONG CÙNG transaction (màn hình qua Livewire)
|--------------------------------------------------------------------------
*/

it('R8 vô hiệu hoá trên màn hình sửa nhân sự: token, refresh token và mã uỷ quyền của người đó bị thu hồi; ai_access về off; người khác không bị đụng', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    $client = McpOAuth::client();
    $tokens = McpOAuth::issueTokens($this, $staff, $client);
    McpOAuth::authorizationCode($staff, $client);
    $other = aclStaff();
    McpOAuth::issueTokens($this, $other);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->is_active)->toBeFalse()
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and(aclLiveCredentials($other))->toBe(['access' => 1, 'refresh' => 1, 'codes' => 0]);

    $revoked = Activity::query()->where('event', 'ai_connections_revoked')->sole();
    expect($revoked->properties['reason'])->toBe('deactivated')
        ->and($revoked->causer?->is($admin))->toBeTrue();

    aclExpectRefused(aclInitialize($tokens['access_token']));
    aclRefresh($client->getKey(), $tokens['refresh_token'])->assertStatus(400);
});

it('R8 vô hiệu hoá: transaction sụp ở bước cuối thì tài khoản vẫn hoạt động VÀ token còn nguyên', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);
    aclFailOnRevocationAudit();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    expect(fn () => $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save'))->toThrow(RuntimeException::class);

    expect($staff->fresh()->is_active)->toBeTrue()
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and(aclLiveCredentials($staff))->toBe(['access' => 1, 'refresh' => 1, 'codes' => 0]);
});

it('R8 admin đặt mật khẩu mới cho nhân sự trên màn hình sửa: thu hồi mọi token; chế độ AI giữ nguyên', function () {
    $admin = aclAdmin();
    $staff = aclStaff(AiAccessMode::ReadWrite);
    $tokens = McpOAuth::issueTokens($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['password' => 'mat-khau-moi-2026'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::ReadWrite)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('password_changed');

    aclExpectRefused(aclInitialize($tokens['access_token']));
});

it('R8 cặp âm: sửa tên trên màn hình sửa nhân sự (không mật khẩu, không đổi chức danh, không tắt) không thu hồi gì', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    $token = McpOAuth::accessToken($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['name' => 'Tên mới của nhân sự'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(aclLiveCredentials($staff)['access'])->toBe(1)
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->exists())->toBeFalse();

    aclInitialize($token)->assertOk();
});

it('R2/R8 đổi chức danh (vai) của một nhân sự: ai_access về off và mọi token bị thu hồi — quản trị phải bật lại', function (UserPosition $to) {
    $admin = aclAdmin();
    $staff = aclStaff(AiAccessMode::ReadWrite);
    $token = McpOAuth::accessToken($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['position' => $to->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(aclLiveCredentials($staff)['access'])->toBe(0);

    $changed = Activity::query()->where('event', 'ai_access_changed')->sole();
    expect($changed->properties->all())->toBe(['from' => 'read_write', 'to' => 'off', 'reason' => 'role_changed'])
        ->and($changed->causer?->is($admin))->toBeTrue()
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('role_changed');

    aclExpectRefused(aclInitialize($token));
})->with([
    'luật sư → trưởng phòng (vẫn có matter.view)' => [UserPosition::Manager],
    'luật sư → kế toán (mất matter.view)' => [UserPosition::Accountant],
]);

it('R8 nhân sự tự đổi mật khẩu ở trang hồ sơ: mọi token của chính người đó bị thu hồi, causer là chính họ', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create(['password' => 'mat-khau-cu-1']);
    $tokens = McpOAuth::issueTokens($this, $staff);

    $this->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'mat-khau-cu-1',
            'password' => 'mat-khau-moi-2',
            'passwordConfirmation' => 'mat-khau-moi-2',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Read);

    $revoked = Activity::query()->where('event', 'ai_connections_revoked')->sole();
    expect($revoked->properties['reason'])->toBe('password_changed')
        ->and($revoked->causer?->is($staff))->toBeTrue();

    aclExpectRefused(aclInitialize($tokens['access_token']));
});

it('R8 trang hồ sơ: transaction sụp ở bước cuối thì mật khẩu cũ còn và token còn nguyên', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create(['password' => 'mat-khau-cu-1']);
    McpOAuth::issueTokens($this, $staff);
    aclFailOnRevocationAudit();

    $this->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');

    expect(fn () => $this->livewire(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'mat-khau-cu-1',
            'password' => 'mat-khau-moi-2',
            'passwordConfirmation' => 'mat-khau-moi-2',
        ])
        ->call('save'))->toThrow(RuntimeException::class);

    expect(Hash::check('mat-khau-cu-1', $staff->fresh()->password))->toBeTrue()
        ->and(aclLiveCredentials($staff)['access'])->toBe(1);
});

it('R8 cặp âm: lưu trang hồ sơ mà không đổi mật khẩu không thu hồi gì', function () {
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);

    $this->actingAs($staff, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditProfile::class)
        ->fillForm(['name' => 'Tên tự sửa'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(aclLiveCredentials($staff)['access'])->toBe(1)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->exists())->toBeFalse();
});

it('R8 nút "Đặt lại 2FA" trên màn hình sửa nhân sự: thu hồi mọi token của người đó', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    $tokens = McpOAuth::issueTokens($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->callAction('resetTwoFactor');

    expect($staff->fresh()->getAppAuthenticationSecret())->toBeNull()
        ->and(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('two_factor_reset');

    aclExpectRefused(aclInitialize($tokens['access_token']));
});

it('R8 "Đặt lại 2FA": transaction sụp ở bước cuối thì secret 2FA còn và token còn nguyên', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);
    aclFailOnRevocationAudit();

    expect(fn () => app(ResetStaffTwoFactor::class)->handle($admin, $staff))->toThrow(RuntimeException::class);

    expect($staff->fresh()->getAppAuthenticationSecret())->not->toBeNull()
        ->and(aclLiveCredentials($staff)['access'])->toBe(1);
});

it('R8 lệnh vkcrm:reset-2fa (console): thu hồi mọi token, dòng audit không có causer', function () {
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);

    $this->artisan('vkcrm:reset-2fa', ['email' => $staff->email])->assertSuccessful();

    expect(aclLiveCredentials($staff)['access'])->toBe(0);

    $revoked = Activity::query()->where('event', 'ai_connections_revoked')->sole();
    expect($revoked->causer_id)->toBeNull()
        ->and($revoked->properties['reason'])->toBe('two_factor_reset');
});

it('R8 xoá nhân sự trên màn hình sửa: thu hồi mọi token, ai_access về off', function () {
    $admin = aclAdmin();
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->callAction('delete');

    $trashed = User::withTrashed()->findOrFail($staff->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->ai_access)->toBe(AiAccessMode::Off)
        ->and(aclLiveCredentials($staff))->toBe(['access' => 0, 'refresh' => 0, 'codes' => 0])
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('deleted');
});

it('R8 mã uỷ quyền chưa đổi lấy token, bị thu hồi rồi, không đổi được nữa', function () {
    $staff = aclStaff();
    $client = McpOAuth::client();
    ['code' => $code, 'verifier' => $verifier] = McpOAuth::authorizationCode($staff, $client);

    app(RevokeAiConnections::class)->handle($staff, AiRevocationReason::PasswordChanged, $staff);

    aclFresh();
    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => $code,
        'code_verifier' => $verifier,
    ])->assertStatus(400);

    expect(Passport::token()->newQuery()->where('user_id', $staff->id)->count())->toBe(0);
});

it('R8 RevokeAiConnections chạy TRONG transaction của người gọi: người gọi sụp thì không thu hồi gì', function () {
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);

    expect(fn () => DB::transaction(function () use ($staff): void {
        app(RevokeAiConnections::class)->handle($staff, AiRevocationReason::Deactivated, null);

        throw new RuntimeException('Người gọi sụp sau khi thu hồi.');
    }))->toThrow(RuntimeException::class);

    expect(aclLiveCredentials($staff)['access'])->toBe(1)
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Read);
});

it('R8 thu hồi không có người thực hiện ($actor null) giữa một phiên web đang mở: dòng audit KHÔNG lấy người của phiên làm causer', function () {
    $staff = aclStaff();
    McpOAuth::issueTokens($this, $staff);

    $this->actingAs(aclAdmin(), 'web');

    app(RevokeAiConnections::class)->handle($staff, AiRevocationReason::Deactivated, null);

    expect(Activity::query()->where('event', 'ai_connections_revoked')->sole()->causer_id)->toBeNull()
        ->and(Activity::query()->where('event', 'ai_access_changed')->sole()->causer_id)->toBeNull();
});

it('R2/R8 chỉ vai spatie đổi (chức danh giữ nguyên): lưu trang sửa nhân sự đồng bộ lại vai, nên cũng là đổi vai — ai_access về off, token bị thu hồi', function () {
    $admin = aclAdmin();
    // Vai lệch chức danh (ví dụ do một lần seed cũ); lần lưu kế tiếp `assignRoleFromPosition()` đồng bộ lại.
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Manager)->withAiAccess()->create();
    McpOAuth::issueTokens($this, $staff);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['name' => 'Tên mới'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->hasRole(Role::Lawyer->value))->toBeTrue()
        ->and($staff->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(aclLiveCredentials($staff)['access'])->toBe(0)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties['reason'])->toBe('role_changed');
});

it('R8 số dòng trong audit thu hồi chỉ đếm dòng VỪA thu hồi, không đếm cặp token cũ đã chết sau một lần làm mới', function () {
    $staff = aclStaff();
    $client = McpOAuth::client();
    $tokens = McpOAuth::issueTokens($this, $staff, $client);
    aclRefresh($client->getKey(), $tokens['refresh_token'])->assertOk();

    expect(Passport::token()->newQuery()->where('user_id', $staff->id)->count())->toBe(2)
        ->and(aclLiveCredentials($staff))->toBe(['access' => 1, 'refresh' => 1, 'codes' => 0]);

    app(RevokeAiConnections::class)->handle($staff, AiRevocationReason::PasswordChanged, $staff);

    expect(Activity::query()->where('event', 'ai_connections_revoked')->sole()->properties->all())->toBe([
        'reason' => 'password_changed',
        'access_tokens' => 1,
        'refresh_tokens' => 1,
        'authorization_codes' => 0,
    ]);
});

it('R8 không có token nào để thu hồi: không ghi dòng audit rỗng', function () {
    app(RevokeAiConnections::class)->handle(aclStaff(), AiRevocationReason::PasswordChanged, null);

    expect(Activity::query()->where('event', 'ai_connections_revoked')->exists())->toBeFalse();
});

it('R8 lý do thu hồi: chỉ vô hiệu hoá, đổi vai, xoá và tắt AI mới hạ ai_access về off; đổi mật khẩu và đặt lại 2FA thì không', function () {
    expect(collect(AiRevocationReason::cases())->filter->turnsAccessOff()->values()->all())->toBe([
        AiRevocationReason::AiAccessOff,
        AiRevocationReason::Deactivated,
        AiRevocationReason::RoleChanged,
        AiRevocationReason::Deleted,
    ]);
});

it('R8 lý do cho một lần sửa nhân sự: vô hiệu hoá trước, rồi đổi vai, rồi đổi mật khẩu; không gì đổi thì không thu hồi', function () {
    expect(AiRevocationReason::forStaffUpdate(deactivated: true, roleChanged: true, passwordChanged: true))->toBe(AiRevocationReason::Deactivated)
        ->and(AiRevocationReason::forStaffUpdate(deactivated: false, roleChanged: true, passwordChanged: true))->toBe(AiRevocationReason::RoleChanged)
        ->and(AiRevocationReason::forStaffUpdate(deactivated: false, roleChanged: false, passwordChanged: true))->toBe(AiRevocationReason::PasswordChanged)
        ->and(AiRevocationReason::forStaffUpdate(deactivated: false, roleChanged: false, passwordChanged: false))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Công tắc toàn hệ thống chặn cả đăng ký client (DCR) và CIMD
|--------------------------------------------------------------------------
*/

it('R2 công tắc tắt: POST /oauth/register từ chối (403 access_denied), không ghi dòng oauth_clients nào; bật thì 201', function () {
    McpOAuth::openServer(enabled: false);

    $refused = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpOAuth::REDIRECT_URI]]);

    $refused->assertForbidden()->assertJsonPath('error', 'access_denied');
    expect(Passport::client()->newQuery()->count())->toBe(0);

    McpOAuth::openServer();

    $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [McpOAuth::REDIRECT_URI]])
        ->assertCreated();
});

it('R2 công tắc tắt: CIMD không quảng bá và không tải gì dù cờ CIMD bật; bật công tắc thì quảng bá lại', function () {
    config(['vkcrm.mcp.client_id_metadata_documents' => true]);
    Http::fake();
    McpOAuth::openServer(enabled: false);

    expect(ResolveClientIdMetadataDocument::enabled())->toBeFalse()
        ->and(app(ResolveClientIdMetadataDocument::class)->handle('https://claude.ai/oauth/claude-code-client-metadata'))->toBeNull();

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonMissingPath('client_id_metadata_document_supported');
    Http::assertNothingSent();

    McpOAuth::openServer();

    expect(ResolveClientIdMetadataDocument::enabled())->toBeTrue();
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('client_id_metadata_document_supported', true);
});

/*
|--------------------------------------------------------------------------
| Bảng ai_acknowledgements: không bao giờ tới cổng khách; ai xem được
|--------------------------------------------------------------------------
*/

it('R12 dòng cam kết vô hình dưới phiên cổng khách (1 = 0), và policy từ chối khách', function () {
    $staff = aclStaff(); // một nhân sự đã cam kết: bảng có đúng một dòng
    $acknowledgement = $staff->aiAcknowledgements()->sole();
    // Khách mang CÙNG số id với nhân sự (hai bảng khác nhau): policy không được nhầm là "của chính mình".
    $clientUser = ClientUser::factory()->activated()->create(['id' => $staff->id]);

    expect(AiAcknowledgement::query()->count())->toBe(1);

    $this->actingAs($clientUser, 'client');

    expect(AiAcknowledgement::query()->count())->toBe(0)
        ->and($clientUser->can('viewAny', AiAcknowledgement::class))->toBeFalse()
        ->and($clientUser->can('view', $acknowledgement))->toBeFalse();
});

it('R12 policy: quản trị (settings.manage) xem mọi cam kết; nhân sự chỉ xem của chính mình', function () {
    $admin = aclAdmin();
    $lawyer = aclStaff();
    $other = aclStaff();
    $own = $lawyer->aiAcknowledgements()->sole();
    $others = $other->aiAcknowledgements()->sole();

    expect($admin->can('viewAny', AiAcknowledgement::class))->toBeTrue()
        ->and($admin->can('view', $others))->toBeTrue()
        ->and($lawyer->can('viewAny', AiAcknowledgement::class))->toBeFalse()
        ->and($lawyer->can('view', $own))->toBeTrue()
        ->and($lawyer->can('view', $others))->toBeFalse();
});
