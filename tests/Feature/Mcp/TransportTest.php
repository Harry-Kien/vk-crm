<?php

use App\Enums\Role;
use App\Http\Middleware\Mcp\CheckOrigin;
use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Http\Middleware\Mcp\EnsureMcpClient;
use App\Http\Middleware\Mcp\EnsureTokenAudience;
use App\Http\Middleware\Mcp\RequireBearerToken;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ApiTokenCookieFactory;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 1 — endpoint `/mcp`: 401 cho mọi request chưa có token, Origin, hai thế hệ giao thức
|--------------------------------------------------------------------------
| Mọi test ở đây đi qua HTTP THẬT tới `/mcp` với token Passport THẬT (`Tests\Support\McpOAuth`:
| ký bằng khoá RSA, lưu bằng repository của Passport, kiểm bằng `ResourceServer`). Không
| `Passport::actingAs()`, không `Server::tool()->actingAs()`: cả hai bỏ qua đúng những lớp mà test
| này phải chứng minh (R7, kế hoạch M11 "Ràng buộc toàn cục").
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Task 6: công tắc toàn hệ thống mở, và `mcpLawyer()` đủ điều kiện của `EnsureMcpAccess` — mọi
    // từ chối ở tệp này vì thế là vì đúng điều kiện test nêu tên (AccessControlTest đo từng điều
    // kiện của Task 6).
    McpOAuth::openServer();
});

const MCP_LEGACY_VERSION = '2025-11-25';
const MCP_STATELESS_VERSION = '2026-07-28';

/** Client kiểu `initialize` (2025-11-25): không `_meta`, không header MCP. */
function mcpInitializeBody(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => MCP_LEGACY_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'client-thu', 'version' => '1.0'],
        ],
    ];
}

/** Client stateless 2026-07-28: `_meta` mang phiên bản và năng lực, kèm header `Mcp-Method`. */
function mcpStatelessBody(string $method): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => $method,
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => MCP_STATELESS_VERSION,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ],
        ],
    ];
}

/** @return array<string, string> */
function mcpStatelessHeaders(string $method): array
{
    return ['MCP-Protocol-Version' => MCP_STATELESS_VERSION, 'Mcp-Method' => $method];
}

function postMcp(array $body, array $headers = [], ?string $token = null): TestResponse
{
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->postJson('/mcp', $body, $headers);
}

/** Luật sư được bật `ai_access` (chỉ đọc) và đã cam kết R12 — đủ điều kiện của `EnsureMcpAccess` (Task 6). */
function mcpLawyer(): User
{
    return User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();
}

/*
|--------------------------------------------------------------------------
| 401 kèm WWW-Authenticate cho mọi request chưa có token (R7)
|--------------------------------------------------------------------------
*/

function expectMcpChallenge(TestResponse $response): void
{
    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toStartWith('Bearer ')
        ->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
}

it('R7 initialize không token: 401 kèm WWW-Authenticate trỏ đúng PRM của /mcp', function () {
    expectMcpChallenge(postMcp(mcpInitializeBody()));
});

it('R7 server/discover không token: 401 kèm WWW-Authenticate', function () {
    expectMcpChallenge(postMcp(mcpStatelessBody('server/discover'), mcpStatelessHeaders('server/discover')));
});

it('R7 tools/list không token: 401 kèm WWW-Authenticate', function () {
    expectMcpChallenge(postMcp(mcpStatelessBody('tools/list'), mcpStatelessHeaders('tools/list')));
});

it('R7 request không có header Accept vẫn nhận 401 JSON, không bị chuyển hướng tới trang đăng nhập', function () {
    $response = test()->call('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(mcpInitializeBody()));

    expectMcpChallenge($response);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('R7 token sai chữ ký: 401', function () {
    expectMcpChallenge(postMcp(mcpInitializeBody(), token: 'khong.phai.jwt'));
});

it('R7 token đặt trong query string: 401 (token chỉ đọc từ header Authorization)', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    expectMcpChallenge(test()->postJson('/mcp?access_token='.$token, mcpInitializeBody()));
});

it('R7 cặp dương: cùng token đặt ở header Authorization thì initialize trả 200', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), token: $token)->assertOk();
});

it('R1 token thiếu scope mcp:use bị chặn (403), không chạy server', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer(), scope: '');

    postMcp(mcpInitializeBody(), token: $token)->assertForbidden();
});

it('R1 token không gắn người dùng nào (kiểu client_credentials) không xác thực được /mcp', function () {
    $client = McpOAuth::client();
    $userToken = McpOAuth::accessToken($this, mcpLawyer(), $client);
    Passport::token()->newQuery()->whereKey(McpOAuth::tokenId($userToken))->update(['user_id' => null]);

    expectMcpChallenge(postMcp(mcpInitializeBody(), token: McpOAuth::resign(
        McpOAuth::tokenId($userToken), new DateTimeImmutable('+10 minutes'), withUser: false,
    )));
});

/*
|--------------------------------------------------------------------------
| Không xác thực bằng cookie (R1: chỉ bearer)
|--------------------------------------------------------------------------
*/

it('R1 phiên /admin và phiên cổng khách, kèm cookie, nhưng không bearer: 401', function () {
    $staff = User::factory()->withRole(Role::Admin)->create();
    $clientUser = ClientUser::factory()->create();

    // `withCredentials()`: không có nó, `postJson()` của Laravel KHÔNG gửi cookie nào, và test này
    // xanh vì một lý do không liên quan.
    $response = $this->actingAs($staff, 'web')
        ->actingAs($clientUser, 'client')
        ->withCredentials()
        ->withCookie(config('session.cookie'), 'phien-bat-ky')
        ->postJson('/mcp', mcpInitializeBody());

    expectMcpChallenge($response);
});

/**
 * `TokenGuard::user()` của Passport rẽ nhánh theo giá trị ĐÚNG/SAI của `bearerToken()`, không theo
 * `null`: `Bearer 0` cho ra `"0"`, `Bearer ,` cho ra `""`, cả hai là "sai" trong PHP, nên guard bỏ
 * qua bearer và đọc cookie `laravel_token`. Mỗi dòng gửi kèm một cookie hợp lệ và mã CSRF đúng.
 * Vế đối chứng: đúng cookie và mã CSRF ấy mở được route thăm dò chỉ có `auth:mcp`.
 */
it('R1 cookie laravel_token hợp lệ (kèm X-CSRF-TOKEN đúng) không xác thực được /mcp, kể cả khi kèm bearer rỗng hoặc "0"', function (?string $authorization) {
    $lawyer = mcpLawyer();
    $cookie = app(ApiTokenCookieFactory::class)->make($lawyer->getKey(), 'csrf-thu');
    $probe = McpOAuth::registerGuardProbe();

    $this->withCredentials()
        ->withCookie(Passport::cookie(), $cookie->getValue())
        ->withHeader('X-CSRF-TOKEN', 'csrf-thu');

    $headers = $authorization === null ? [] : ['Authorization' => $authorization];

    expectMcpChallenge($this->postJson('/mcp', mcpInitializeBody(), $headers));

    $this->postJson($probe)->assertOk()->assertJsonPath('user_id', $lawyer->getKey());
})->with([
    'không có header Authorization' => [null],
    'Bearer 0' => ['Bearer 0'],
    'Bearer ,' => ['Bearer ,'],
    'Bearer và hai khoảng trắng' => ['Bearer  '],
]);

it('R1 bearer rỗng hoặc chỉ có khoảng trắng bị chặn ngay ở RequireBearerToken: 401, không tới bộ kiểm token nên không ghi lỗi nào', function (string $authorization) {
    Exceptions::fake();

    expectMcpChallenge(postMcp(mcpInitializeBody(), ['Authorization' => $authorization]));

    Exceptions::assertNothingReported();
})->with([
    'Bearer' => ['Bearer '],
    'Bearer ,' => ['Bearer ,'],
    'Bearer và hai khoảng trắng' => ['Bearer  '],
]);

it('R7 token sai và không có header Accept: vẫn 401 JSON, không chuyển hướng (không có route login)', function () {
    $response = test()->call(
        'POST', '/mcp',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer khong.phai.jwt'],
        content: json_encode(mcpInitializeBody()),
    );

    expectMcpChallenge($response);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('R1 /mcp không nằm trong nhóm web: không phiên, không CSRF', function () {
    $middleware = Route::getRoutes()->match(request()->create('/mcp', 'POST'))->gatherMiddleware();

    expect($middleware)->not->toContain('web')
        ->and($middleware)->toContain(CheckOrigin::class)
        ->and($middleware)->toContain(RequireBearerToken::class)
        ->and($middleware)->toContain('auth:mcp')
        ->and($middleware)->toContain(CheckToken::using('mcp:use'))
        ->and($middleware)->toContain(EnsureMcpAccess::class);

    // Hành vi: request có token hợp lệ, không có mã CSRF nào, không nhận 419 và không được phát
    // cookie phiên.
    postMcp(mcpInitializeBody(), token: McpOAuth::accessToken($this, mcpLawyer()))
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});

/*
 * Thứ tự bảy middleware của app trước `POST /mcp` (sau ba middleware của gói): Origin trước mọi bước
 * xác thực; xoá cookie `laravel_token` trước guard; `aud` chỉ đọc sau khi guard đã kiểm chữ ký của
 * chính token đó (Task 2); client của token phải mang cờ `is_mcp` (Task 3) — đọc client mà guard vừa
 * gắn cho request; scope; rồi điều kiện của NGƯỜI sở hữu token (`EnsureMcpAccess`, Task 6).
 */
it('R1/R7 bảy middleware của app trước /mcp đứng đúng thứ tự: Origin, chỉ bearer, auth:mcp, aud, client is_mcp, scope, quyền truy cập của người', function () {
    $middleware = Route::getRoutes()->match(request()->create('/mcp', 'POST'))->gatherMiddleware();

    $ours = array_values(array_filter($middleware, fn ($entry) => in_array($entry, [
        CheckOrigin::class,
        RequireBearerToken::class,
        'auth:mcp',
        EnsureTokenAudience::class,
        EnsureMcpClient::class,
        CheckToken::using('mcp:use'),
        EnsureMcpAccess::class,
    ], true)));

    expect($ours)->toBe([
        CheckOrigin::class,
        RequireBearerToken::class,
        'auth:mcp',
        EnsureTokenAudience::class,
        EnsureMcpClient::class,
        CheckToken::using('mcp:use'),
        EnsureMcpAccess::class,
    ]);
});

/*
 * Bất biến (rà soát Task 1, mr1): `RequireBearerToken` xoá cookie `laravel_token` chỉ ở route có nó.
 * Mọi route đứng sau một guard driver `passport` (`auth:mcp`, hay một guard passport thêm sau này)
 * phải có `RequireBearerToken` ĐỨNG TRƯỚC guard đó, nếu không cookie `laravel_token` (mọi scope,
 * phát cho bất kỳ phiên web nào ở `POST /oauth/token/refresh`) mở được route ấy. Route thăm dò của
 * test (`McpOAuth::registerGuardProbe()`) chỉ được đăng ký trong chính test cần nó, nên không có ở đây.
 */
it('R1 bất biến: mọi route sau một guard driver passport đều có RequireBearerToken đứng trước guard đó', function () {
    $passportGuards = collect(config('auth.guards'))
        ->filter(fn (array $guard) => ($guard['driver'] ?? null) === 'passport')
        ->keys()
        ->all();

    expect($passportGuards)->toContain('mcp');

    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        foreach ($middleware as $position => $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'auth:')) {
                continue;
            }

            if (array_intersect(explode(',', substr($entry, 5)), $passportGuards) === []) {
                continue;
            }

            $checked++;
            $bearerAt = array_search(RequireBearerToken::class, $middleware, true);

            if ($bearerAt === false || $bearerAt > $position) {
                $violations[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);
});

it('R7 GET và DELETE /mcp trả 405, Allow: POST', function () {
    $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    $this->delete('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
});

/*
|--------------------------------------------------------------------------
| Origin (R7): có Origin ngoài allowlist thì 403, không Origin thì cho qua
|--------------------------------------------------------------------------
*/

it('R7 Origin https://evil.example: 403, kể cả khi token hợp lệ', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), ['Origin' => 'https://evil.example'], $token)->assertForbidden();
});

it('R7 Origin lạ bị chặn TRƯỚC bước xác thực: không token vẫn là 403, không phải 401', function () {
    postMcp(mcpInitializeBody(), ['Origin' => 'https://evil.example'])->assertForbidden();
});

it('R7 Origin so khớp chính xác: tên miền con giả, "null" và chuỗi rỗng đều 403', function (string $origin) {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), ['Origin' => $origin], $token)->assertForbidden();
})->with([
    'https://claude.ai.evil.example',
    'https://evil.claude.ai',
    'http://claude.ai',
    'null',
]);

it('R7 Origin https://claude.ai và https://chatgpt.com: qua', function (string $origin) {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), ['Origin' => $origin], $token)->assertOk();
})->with(['https://claude.ai', 'https://chatgpt.com']);

it('R7 Origin của chính ứng dụng (APP_URL): qua', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer());
    $parts = parse_url((string) config('app.url'));
    $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

    postMcp(mcpInitializeBody(), ['Origin' => $origin], $token)->assertOk();
});

it('R7 không có Origin: qua (client chạy từ máy chủ không gửi Origin)', function () {
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), token: $token)->assertOk();
});

it('R7 MCP_EXTRA_ALLOWED_ORIGINS thêm được một Origin mà không sửa mã', function () {
    config(['vkcrm.mcp.allowed_origins' => [...config('vkcrm.mcp.allowed_origins'), 'https://vscode.dev']]);
    $token = McpOAuth::accessToken($this, mcpLawyer());

    postMcp(mcpInitializeBody(), ['Origin' => 'https://vscode.dev'], $token)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Hai thế hệ giao thức (R7, [DC:626])
|--------------------------------------------------------------------------
*/

it('R7 client kiểu initialize (2025-11-25) nhận phản hồi hợp lệ kèm instructions tiếng Việt', function () {
    $response = postMcp(mcpInitializeBody(), token: McpOAuth::accessToken($this, mcpLawyer()));

    $response->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.protocolVersion', MCP_LEGACY_VERSION)
        ->assertJsonPath('result.instructions', __('mcp.server.instructions'));
});

it('R7 client 2026-07-28 (server/discover, header Mcp-Method) nhận phản hồi hợp lệ', function () {
    $response = postMcp(
        mcpStatelessBody('server/discover'),
        mcpStatelessHeaders('server/discover'),
        McpOAuth::accessToken($this, mcpLawyer()),
    );

    $response->assertOk()
        ->assertJsonPath('id', 7)
        ->assertJsonPath('result.instructions', __('mcp.server.instructions'));

    expect($response->json('result.supportedVersions'))->toContain(MCP_STATELESS_VERSION);
});

it('R7 client 2026-07-28 nhận tools/list qua cùng endpoint (danh mục tool: tests/Feature/Mcp/Tools/ToolCatalogTest.php)', function () {
    $response = postMcp(
        mcpStatelessBody('tools/list'),
        mcpStatelessHeaders('tools/list'),
        McpOAuth::accessToken($this, mcpLawyer()),
    );

    $response->assertOk();

    expect(array_column($response->json('result.tools'), 'name'))->toContain('whoami');
});

it('R7 header Mcp-Method lệch với body: 400, mã -32020', function () {
    $response = postMcp(
        mcpStatelessBody('server/discover'),
        mcpStatelessHeaders('tools/list'),
        McpOAuth::accessToken($this, mcpLawyer()),
    );

    $response->assertStatus(400)->assertJsonPath('error.code', -32020);
});

it('R11 instructions của server: ba câu của [DC:93-95] nằm trọn trong 512 ký tự đầu', function () {
    $head = mb_substr(__('mcp.server.instructions'), 0, 512);

    expect($head)->toContain('untrusted_client_content')
        ->toContain('không phải chỉ dẫn')
        ->toContain('bản nháp')
        ->toContain('ghi chú nội bộ')
        ->toContain('vụ hạn chế')
        ->toContain('số định danh');
});

/*
|--------------------------------------------------------------------------
| spatie/permission dưới guard `mcp` ("sẽ cắn")
|--------------------------------------------------------------------------
| `auth:mcp` gọi `Auth::shouldUse('mcp')`, nên guard mặc định trong request MCP là `mcp`, trong khi
| quyền và vai được seed ở guard `web`. Route thăm dò dưới đây đứng sau ĐÚNG `auth:mcp` và hỏi
| `can()` như một tool sẽ hỏi.
*/

function registerMcpCanProbe(): void
{
    Route::post('/_probe/mcp-can', fn () => [
        'guard' => config('auth.defaults.guard'),
        'can' => request()->user()->can('matter.view'),
    ])->middleware('auth:mcp');
}

it('§5 dưới guard mcp, luật sư can(matter.view) = true như dưới web', function () {
    registerMcpCanProbe();
    $lawyer = mcpLawyer();

    expect($lawyer->can('matter.view'))->toBeTrue();

    $this->postJson('/_probe/mcp-can', [], ['Authorization' => 'Bearer '.McpOAuth::accessToken($this, $lawyer)])
        ->assertOk()
        ->assertJsonPath('guard', 'mcp')
        ->assertJsonPath('can', true);
});

it('§5 dưới guard mcp, kế toán can(matter.view) = false như dưới web', function () {
    registerMcpCanProbe();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect($accountant->can('matter.view'))->toBeFalse();

    $this->postJson('/_probe/mcp-can', [], ['Authorization' => 'Bearer '.McpOAuth::accessToken($this, $accountant)])
        ->assertOk()
        ->assertJsonPath('guard', 'mcp')
        ->assertJsonPath('can', false);
});

/*
|--------------------------------------------------------------------------
| Hạn token (R7): access 1 giờ, refresh 30 ngày
|--------------------------------------------------------------------------
*/

it('R7 access token do /oauth/token cấp sống đúng 1 giờ, refresh token đúng 30 ngày', function () {
    $tokens = McpOAuth::issueTokens($this, mcpLawyer());
    $claims = McpOAuth::claims($tokens['access_token']);

    // `iat` do lcobucci/jwt ghi kèm phần lẻ micro giây, `exp` là số nguyên: hiệu của hai số lệch
    // dưới một giây.
    //
    // `expires_in` do league/oauth2-server tính lúc DỰNG phản hồi (`exp - time()`), không lúc cấp:
    // nếu đồng hồ hệ thống qua ranh giới một giây giữa hai lúc đó thì nó là 3599 (lần chạy MariaDB của
    // MAIN MERGE 2 gặp đúng chuyện này). Mọi giá trị ngoài [3599, 3600] vẫn đỏ, kể cả mặc định một năm
    // của Passport.
    expect($tokens['expires_in'])->toBeGreaterThanOrEqual(3599)->toBeLessThanOrEqual(3600)
        ->and($claims['exp'] - $claims['iat'])->toEqualWithDelta(3600, 1);

    $refresh = Passport::refreshToken()->newQuery()->where('access_token_id', $claims['jti'])->sole();

    expect(now()->diffInDays($refresh->expires_at, absolute: true))->toEqualWithDelta(30, 0.01);
});

it('R7 access token quá hạn (exp đã qua): 401', function () {
    $jti = McpOAuth::tokenId(McpOAuth::accessToken($this, mcpLawyer()));

    expectMcpChallenge(postMcp(mcpInitializeBody(), token: McpOAuth::resign($jti, new DateTimeImmutable('-1 minute'))));
});

it('R7 cặp dương: cùng token ký lại với exp còn hạn thì 200', function () {
    $jti = McpOAuth::tokenId(McpOAuth::accessToken($this, mcpLawyer()));

    postMcp(mcpInitializeBody(), token: McpOAuth::resign($jti, new DateTimeImmutable('+1 minute')))->assertOk();
});
