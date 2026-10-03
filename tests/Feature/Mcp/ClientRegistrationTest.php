<?php

use App\Enums\Role;
use App\Http\Controllers\Mcp\RegisterClientController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 3 — đăng ký client động (DCR, RFC 7591) ở `POST /oauth/register`
|--------------------------------------------------------------------------
| R7: mọi `redirect_uri` phải khớp CHÍNH XÁC một mục của allowlist (`config/vkcrm.php`,
| `mcp.redirect_uris` + `MCP_EXTRA_REDIRECT_URIS`); riêng loopback `http://localhost` /
| `http://127.0.0.1` bỏ qua cổng (RFC 8252 §7.3). Client tạo qua DCR mang cờ `is_mcp`, và `/mcp`
| từ chối token của client không mang cờ (ví dụ client tạo bằng `passport:client`). Đăng ký bị
| throttle theo IP: 10 lần một giờ.
|
| Mọi test đi qua HTTP thật: route của app, throttle, Action, Passport, `/oauth/authorize`, `/mcp`.
*/

const DCR_CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Một lần đăng ký như Claude gửi (RFC 7591 §2): JSON, kèm các trường mà máy chủ được phép thay
 * (`grant_types`, `response_types`, `token_endpoint_auth_method`).
 *
 * @param  array<string, mixed>  $extra
 */
function dcrRegister(mixed $redirectUris, array $extra = [], string $ip = '203.0.113.10'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/oauth/register', array_merge([
        'client_name' => 'Claude',
        'redirect_uris' => $redirectUris,
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], $extra));
}

function dcrClient(TestResponse $response): Client
{
    return Passport::client()->newQuery()->findOrFail($response->json('client_id'));
}

/**
 * `initialize` tới `/mcp` như một request PHP-FPM MỚI. Trong test, ứng dụng sống qua mọi request của
 * cùng một test, nên hai thứ của request trước còn nằm lại — điều không bao giờ xảy ra trên máy chủ
 * thật, nơi mỗi request là một tiến trình mới:
 * - `AuthManager` giữ `TokenGuard`, và guard nhớ người dùng lẫn client (`::$user`, `::$client`):
 *   một token hợp lệ của request trước "mở" request sau;
 * - `Laravel\Passport\ClientRepository` (singleton) nhớ client đã đọc qua `once()`
 *   (`find()`), chỉ được xoá ở cuối test (`Once::flush()`): đổi cờ `is_mcp` trong CSDL giữa hai
 *   request thì request sau vẫn thấy bản cũ.
 */
function dcrInitialize(string $token): TestResponse
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

/**
 * `GET /oauth/authorize` của một luật sư đã đăng nhập, PKCE S256, qua màn hình đồng ý thay tạm.
 */
function dcrAuthorize(User $user, Client $client, string $redirectUri): TestResponse
{
    McpOAuth::useConsentStandIn();

    return test()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri,
        'scope' => 'mcp:use',
        'state' => 'trang-thai-'.Str::random(8),
        'code_challenge' => McpOAuth::pkce()['challenge'],
        'code_challenge_method' => 'S256',
    ]));
}

/*
|--------------------------------------------------------------------------
| Allowlist: nhận đúng URI của từng nền tảng
|--------------------------------------------------------------------------
*/

it('R7 DCR nhận redirect URI của từng nền tảng trong allowlist: 201, client công khai mang cờ is_mcp, chỉ authorization_code + refresh_token', function (string $uri) {
    $response = dcrRegister([$uri]);

    $response->assertCreated()
        ->assertJsonPath('redirect_uris', [$uri])
        ->assertJsonPath('grant_types', ['authorization_code', 'refresh_token'])
        ->assertJsonPath('response_types', ['code'])
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonPath('client_name', 'Claude');

    $client = dcrClient($response);

    expect((bool) $client->getAttribute('is_mcp'))->toBeTrue()
        ->and($client->redirect_uris)->toBe([$uri])
        ->and($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and($client->confidential())->toBeFalse()
        ->and($client->revoked)->toBeFalse()
        ->and($response->json())->not->toHaveKey('client_secret');
})->with([
    'Claude web, desktop, mobile' => [DCR_CLAUDE],
    'ChatGPT, redirect ổn định (cần iss)' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
    'ChatGPT, redirect theo callback_id' => ['https://chatgpt.com/connector/oauth/cb_9Xk-2fA7'],
    'Claude Code, localhost có cổng' => ['http://localhost:51234/callback'],
    'Claude Code, 127.0.0.1 có cổng' => ['http://127.0.0.1:51234/callback'],
    'loopback localhost không cổng' => ['http://localhost/callback'],
    'loopback cổng 65535' => ['http://127.0.0.1:65535/callback'],
    'VS Code web' => ['https://vscode.dev/redirect'],
    'VS Code desktop, đúng cổng 33418' => ['http://127.0.0.1:33418/'],
    'VS Code desktop, cổng khác' => ['http://127.0.0.1:40001/'],
    'Cursor web' => ['https://www.cursor.com/agents/mcp/oauth/callback'],
    'Cursor desktop, đúng cổng 8787' => ['http://localhost:8787/callback'],
    'Cursor desktop, cổng khác' => ['http://localhost:18787/callback'],
    'Antigravity' => ['https://antigravity.google/oauth-callback'],
]);

it('R7 DCR nhận nhiều redirect URI cùng lúc khi mọi URI đều trong allowlist', function () {
    $uris = ['http://localhost:5000/callback', 'http://127.0.0.1:5000/callback'];

    $response = dcrRegister($uris)->assertCreated();

    expect(dcrClient($response)->redirect_uris)->toBe($uris);
});

/*
|--------------------------------------------------------------------------
| Allowlist: từ chối mọi thứ không khớp chính xác
|--------------------------------------------------------------------------
*/

it('R7 DCR từ chối redirect URI không khớp chính xác: 400 invalid_redirect_uri, error_description ASCII, không tạo client nào', function (mixed $uri) {
    $response = dcrRegister([$uri]);

    $response->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

    // RFC 7591 §3.2.2: error_description là "Human-readable ASCII text ... used for debugging".
    expect(preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/', (string) $response->json('error_description')))->toBe(1)
        ->and(Passport::client()->newQuery()->count())->toBe(0);
})->with([
    // Sáu dạng kế hoạch đòi, mỗi dạng một dòng.
    'tên miền giả claude.ai.evil.example' => ['https://claude.ai.evil.example/api/mcp/auth_callback'],
    'đi ngược thư mục sau URI của Claude' => ['https://claude.ai/api/mcp/auth_callback/../x'],
    'localhost.evil.example' => ['http://localhost.evil.example/callback'],
    'javascript:' => ['javascript:alert(1)'],
    'scheme riêng (cursor://)' => ['cursor://anysphere.cursor-retrieval/oauth/callback'],
    'đi ngược thư mục trong callback_id của ChatGPT' => ['https://chatgpt.com/connector/oauth/../../x'],
    // Các dạng khác của "không chính xác".
    'scheme riêng (claude://)' => ['claude://callback'],
    'data:' => ['data:text/html,<script>alert(1)</script>'],
    'không phải URL' => ['khong phai url'],
    'thêm query' => [DCR_CLAUDE.'?next=https://evil.example'],
    'thêm fragment' => [DCR_CLAUDE.'#x'],
    'thêm / cuối' => [DCR_CLAUDE.'/'],
    'http thay https' => ['http://claude.ai/api/mcp/auth_callback'],
    'chữ hoa ở host' => ['https://Claude.ai/api/mcp/auth_callback'],
    'cổng 443 viết tường minh' => ['https://claude.ai:443/api/mcp/auth_callback'],
    'thông tin người dùng trước host' => ['https://evil.example@claude.ai/api/mcp/auth_callback'],
    'chỉ đúng tên miền, sai đường dẫn' => ['https://claude.ai/'],
    'loopback có thông tin người dùng' => ['http://localhost:8080@evil.example/callback'],
    'loopback 127.0.0.1.evil.example' => ['http://127.0.0.1.evil.example/callback'],
    'loopback https' => ['https://localhost:5000/callback'],
    'loopback đường dẫn khác' => ['http://localhost:5000/other'],
    'loopback đi ngược thư mục' => ['http://localhost:5000/callback/../x'],
    'loopback cổng 0' => ['http://localhost:0/callback'],
    'loopback cổng 65536' => ['http://127.0.0.1:65536/callback'],
    'loopback dấu : không cổng' => ['http://localhost:/callback'],
    'loopback ::1 (không có trong allowlist)' => ['http://[::1]:5000/callback'],
    'callback_id rỗng' => ['https://chatgpt.com/connector/oauth/'],
    'callback_id hai đoạn' => ['https://chatgpt.com/connector/oauth/abc/def'],
    'callback_id mã hoá %2F' => ['https://chatgpt.com/connector/oauth/abc%2F..'],
    'callback_id có dấu chấm' => ['https://chatgpt.com/connector/oauth/a.b'],
    'callback_id dài 129 ký tự' => ['https://chatgpt.com/connector/oauth/'.str_repeat('a', 129)],
    'callback_id kèm query' => ['https://chatgpt.com/connector/oauth/abc?x=1'],
    'callback_id ở tên miền con giả' => ['https://chatgpt.com.evil.example/connector/oauth/abc'],
]);

it('R7 DCR: callback_id dài đúng 128 ký tự thì nhận (cặp dương của dòng 129 ký tự)', function () {
    dcrRegister(['https://chatgpt.com/connector/oauth/'.str_repeat('a', 128)])->assertCreated();
});

it('R7 DCR: một URI đúng, một URI sai thì từ chối cả lần đăng ký, không tạo client nào', function () {
    dcrRegister([DCR_CLAUDE, 'https://evil.example/callback'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Hình dạng yêu cầu đăng ký
|--------------------------------------------------------------------------
*/

it('R7 DCR: redirect_uris thiếu, rỗng, không phải danh sách, quá 10 mục, hoặc có mục không phải chuỗi: 400 invalid_redirect_uri, không lỗi 500', function (mixed $redirectUris) {
    dcrRegister($redirectUris)
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(Passport::client()->newQuery()->count())->toBe(0);
})->with([
    'thiếu' => [null],
    'rỗng' => [[]],
    'một chuỗi thay vì danh sách' => [DCR_CLAUDE],
    'đối tượng có khoá' => [['a' => DCR_CLAUDE]],
    '11 mục' => [array_map(fn (int $port) => "http://127.0.0.1:{$port}/callback", range(5000, 5010))],
    'một mục là số' => [[5]],
    'một mục là mảng' => [[[DCR_CLAUDE]]],
    'một mục là chuỗi rỗng' => [['']],
]);

it('R7 DCR: đúng 10 redirect URI thì nhận (cặp dương của dòng 11 mục)', function () {
    $uris = array_map(fn (int $port) => "http://127.0.0.1:{$port}/callback", range(5000, 5009));

    expect(dcrClient(dcrRegister($uris)->assertCreated())->redirect_uris)->toBe($uris);
});

it('R7 DCR: client_name dài hơn cột oauth_clients.name (255) bị từ chối 400 invalid_client_metadata; đúng 255 ký tự thì nhận', function () {
    dcrRegister([DCR_CLAUDE], ['client_name' => str_repeat('a', 256)])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_client_metadata');

    expect(Passport::client()->newQuery()->count())->toBe(0);

    $response = dcrRegister([DCR_CLAUDE], ['client_name' => str_repeat('ă', 255)])->assertCreated();

    expect(dcrClient($response)->name)->toBe(str_repeat('ă', 255));
});

it('R7 DCR: không khai client_name thì tên client là host của redirect URI đầu tiên (tên tự khai không bao giờ là căn cứ tin cậy)', function () {
    $response = dcrRegister([DCR_CLAUDE], ['client_name' => null])->assertCreated();

    expect(dcrClient($response)->name)->toBe('claude.ai')
        ->and($response->json('client_name'))->toBe('claude.ai');
});

it('R7 DCR: tạo client và gắn cờ is_mcp trong CÙNG transaction — gắn cờ hỏng thì không còn client nào (không có client DCR thiếu cờ)', function () {
    Client::saving(function (Client $client): void {
        if ($client->isDirty('is_mcp')) {
            throw new RuntimeException('gắn cờ hỏng (giả lập)');
        }
    });

    dcrRegister([DCR_CLAUDE])->assertStatus(500);

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

it('R7 DCR: trường máy chủ được phép thay (grant_types, token_endpoint_auth_method) không mở được grant hay secret nào', function () {
    $response = dcrRegister([DCR_CLAUDE], [
        'grant_types' => ['client_credentials', 'password', 'authorization_code'],
        'token_endpoint_auth_method' => 'client_secret_post',
    ])->assertCreated();

    $client = dcrClient($response);

    expect($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and($client->confidential())->toBeFalse()
        ->and($response->json('token_endpoint_auth_method'))->toBe('none');
});

/*
|--------------------------------------------------------------------------
| Cấu hình: MCP_EXTRA_REDIRECT_URIS và các mục thêm
|--------------------------------------------------------------------------
*/

it('R7 MCP_EXTRA_REDIRECT_URIS: config/vkcrm.php tách dấu phẩy, cắt khoảng trắng, bỏ mục rỗng', function () {
    $saved = [getenv('MCP_EXTRA_REDIRECT_URIS'), $_ENV['MCP_EXTRA_REDIRECT_URIS'] ?? null, $_SERVER['MCP_EXTRA_REDIRECT_URIS'] ?? null];
    $value = ' https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect , https://vertexaisearch.cloud.google.com/oauth-redirect,,';

    putenv('MCP_EXTRA_REDIRECT_URIS='.$value);
    $_ENV['MCP_EXTRA_REDIRECT_URIS'] = $_SERVER['MCP_EXTRA_REDIRECT_URIS'] = $value;
    Env::enablePutenv();

    try {
        $config = require config_path('vkcrm.php');
    } finally {
        $saved[0] === false ? putenv('MCP_EXTRA_REDIRECT_URIS') : putenv('MCP_EXTRA_REDIRECT_URIS='.$saved[0]);

        if ($saved[1] === null) {
            unset($_ENV['MCP_EXTRA_REDIRECT_URIS']);
        } else {
            $_ENV['MCP_EXTRA_REDIRECT_URIS'] = $saved[1];
        }

        if ($saved[2] === null) {
            unset($_SERVER['MCP_EXTRA_REDIRECT_URIS']);
        } else {
            $_SERVER['MCP_EXTRA_REDIRECT_URIS'] = $saved[2];
        }

        Env::enablePutenv();
    }

    expect($config['mcp']['extra_redirect_uris'])->toBe([
        'https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect',
        'https://vertexaisearch.cloud.google.com/oauth-redirect',
    ]);
});

it('R7 mục thêm (MCP_EXTRA_REDIRECT_URIS) cũng so khớp chính xác: đúng URI thì nhận, kéo dài đường dẫn thì từ chối', function () {
    config(['vkcrm.mcp.extra_redirect_uris' => ['https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect']]);

    dcrRegister(['https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect'])->assertCreated();
    dcrRegister(['https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect/x'])->assertStatus(400);
    dcrRegister(['https://teams.microsoft.com/'])->assertStatus(400);
});

it('R7 không bao giờ nhận ký tự đại diện cho host: "*" là ký tự thường, {callback_id} chỉ có nghĩa trong đường dẫn', function (string $entry, string $uri) {
    config(['vkcrm.mcp.extra_redirect_uris' => [$entry]]);

    dcrRegister([$uri])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'dấu * ở host' => ['https://*.evil.example/cb', 'https://a.evil.example/cb'],
    '{callback_id} ở host' => ['https://{callback_id}.evil.example/cb', 'https://abc.evil.example/cb'],
    '{callback_id} là cả host, không đường dẫn' => ['https://{callback_id}', 'https://evil-example'],
    '{callback_id} ở cổng' => ['https://evil.example:{callback_id}/cb', 'https://evil.example:8443/cb'],
]);

it('R7 chỉ host loopback (localhost, 127.0.0.1, [::1]) được bỏ qua cổng; mục http khác so chính xác cả cổng, đúng URI thì nhận', function (string $entry, string $uri) {
    config(['vkcrm.mcp.extra_redirect_uris' => [$entry]]);

    dcrRegister([$uri])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    dcrRegister([$entry])->assertCreated();
})->with([
    'host http thường, thêm cổng' => ['http://intranet.example/cb', 'http://intranet.example:8080/cb'],
    'host bắt đầu bằng localhost., chèn cổng' => ['http://localhost.corp.example/cb', 'http://localhost:9999.corp.example/cb'],
]);

it('R7 {callback_id} trong đường dẫn của một mục thêm thì mở rộng đúng một đoạn, như mục của ChatGPT', function () {
    config(['vkcrm.mcp.extra_redirect_uris' => ['https://app.example/oauth/{callback_id}/done']]);

    dcrRegister(['https://app.example/oauth/abc-123/done'])->assertCreated();
    dcrRegister(['https://app.example/oauth/abc/x/done'])->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| Route, throttle
|--------------------------------------------------------------------------
*/

it('R7 POST /oauth/register là controller của app (không phải OAuthRegisterController của gói), có throttle, ngoài nhóm web', function () {
    $route = Route::getRoutes()->match(request()->create('/oauth/register', 'POST'));

    expect($route->getActionName())->toBe(RegisterClientController::class)
        ->and($route->gatherMiddleware())->toContain('throttle:'.RegisterClientController::RATE_LIMITER)
        ->and($route->gatherMiddleware())->not->toContain('web');
});

it('R7 /oauth/register: lần thứ 11 trong một giờ từ một IP nhận 429 kèm Retry-After và X-RateLimit-*; lần hỏng cũng tính; IP khác không bị ảnh hưởng; hết giờ thì mở lại', function () {
    $ip = '198.51.100.1';

    foreach (range(1, 5) as $attempt) {
        dcrRegister(['https://evil.example/cb'], ip: $ip)->assertStatus(400);
    }

    foreach (range(1, 5) as $attempt) {
        dcrRegister([DCR_CLAUDE], ip: $ip)->assertCreated();
    }

    $blocked = dcrRegister([DCR_CLAUDE], ip: $ip);

    $blocked->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonPath('error', 'too_many_requests');

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(60)->toBeLessThanOrEqual(3600)
        ->and(preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]+$/', (string) $blocked->json('error_description')))->toBe(1)
        ->and(Passport::client()->newQuery()->count())->toBe(5);

    dcrRegister([DCR_CLAUDE], ip: '198.51.100.2')->assertCreated();

    $this->travel(2)->minutes();
    dcrRegister([DCR_CLAUDE], ip: $ip)->assertStatus(429);

    $this->travel(60)->minutes();
    dcrRegister([DCR_CLAUDE], ip: $ip)->assertCreated();
});

/*
|--------------------------------------------------------------------------
| /oauth/authorize chỉ nhận redirect URI đã đăng ký
|--------------------------------------------------------------------------
*/

it('R7 authorize với redirect_uri khác cái đã đăng ký (dù nằm trong allowlist): bị từ chối, không chuyển hướng; đúng URI đã đăng ký thì tới màn hình đồng ý', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $client = dcrClient(dcrRegister([DCR_CLAUDE])->assertCreated());

    $other = dcrAuthorize($user, $client, 'https://chatgpt.com/connector_platform_oauth_redirect');

    expect($other->isRedirect())->toBeFalse()
        ->and($other->json('error'))->toBe('invalid_client')
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);

    dcrAuthorize($user, $client, DCR_CLAUDE)->assertOk()->assertJsonStructure(['auth_token']);
});

/*
 * league/oauth2-server 9.4.1 (`RedirectUriValidator::isLoopbackUri()`) coi CHỈ `127.0.0.1` và `[::1]`
 * là loopback và bỏ qua cổng của chúng; `localhost` thì so chính xác, kể cả cổng. Kế hoạch ghi điều
 * này là "chưa kiểm được"; hai test sau ghim hành vi đã đo. DCR đăng ký lại mỗi lần kết nối nên cổng
 * lúc authorize là cổng vừa đăng ký; CIMD (Task 5) khai `http://localhost/callback` không cổng nên
 * phải tự xử lý chỗ này.
 */
it('R7 loopback 127.0.0.1: authorize với cổng khác cổng đã đăng ký vẫn nhận (league bỏ qua cổng, RFC 8252 §7.3)', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $client = dcrClient(dcrRegister(['http://127.0.0.1:51234/callback'])->assertCreated());

    dcrAuthorize($user, $client, 'http://127.0.0.1:60000/callback')->assertOk()->assertJsonStructure(['auth_token']);
});

it('R7 loopback localhost: authorize phải dùng đúng cổng đã đăng ký (league so chính xác localhost); đúng cổng thì nhận', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $client = dcrClient(dcrRegister(['http://localhost:51234/callback'])->assertCreated());

    $otherPort = dcrAuthorize($user, $client, 'http://localhost:60000/callback');

    expect($otherPort->isRedirect())->toBeFalse()
        ->and($otherPort->json('error'))->toBe('invalid_client');

    dcrAuthorize($user, $client, 'http://localhost:51234/callback')->assertOk()->assertJsonStructure(['auth_token']);
});

/*
|--------------------------------------------------------------------------
| /mcp chỉ nhận token của client mang cờ is_mcp
|--------------------------------------------------------------------------
*/

it('R2/R7 client không mang cờ is_mcp (tạo bằng passport:client) cầm token hợp lệ gọi /mcp: 401 invalid_token; cùng người, client DCR: 200', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();

    Artisan::call('passport:client', ['--public' => true, '--name' => 'Thu cong', '--redirect_uri' => DCR_CLAUDE]);
    $manual = Passport::client()->newQuery()->where('name', 'Thu cong')->firstOrFail();

    expect((bool) $manual->getAttribute('is_mcp'))->toBeFalse();

    $response = dcrInitialize(McpOAuth::accessToken($this, $user, $manual));

    $response->assertUnauthorized();
    expect($response->headers->get('WWW-Authenticate'))->toContain('error="invalid_token"');

    $registered = dcrClient(dcrRegister([DCR_CLAUDE])->assertCreated());

    dcrInitialize(McpOAuth::accessToken($this, $user, $registered))->assertOk();
});

it('R2/R7 cờ is_mcp được kiểm ở MỖI request, không lúc cấp token: gỡ cờ thì token đang sống nhận 401 ở request kế tiếp', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $client = dcrClient(dcrRegister([DCR_CLAUDE])->assertCreated());
    $token = McpOAuth::accessToken($this, $user, $client);

    dcrInitialize($token)->assertOk();

    $client->forceFill(['is_mcp' => false])->save();

    dcrInitialize($token)->assertUnauthorized();
});
