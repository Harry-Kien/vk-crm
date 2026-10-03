<?php

use App\Actions\Mcp\ResolveClientIdMetadataDocument;
use App\Enums\Role;
use App\Models\User;
use App\Support\Mcp\HostResolver;
use App\Support\Mcp\MetadataDocumentFetcher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 5 — CIMD (Client ID Metadata Documents) phía máy chủ uỷ quyền
|--------------------------------------------------------------------------
| `client_id` là một URL HTTPS; máy chủ tải tài liệu JSON ở URL đó, kiểm nó, rồi giữ một dòng
| `oauth_clients` mang cờ `is_mcp`, khoá theo URL (`metadata_url`). Mọi test đi qua HTTP thật:
| `/oauth/authorize` (controller và middleware của Passport, màn hình đồng ý THAY TẠM của
| `McpOAuth::useConsentStandIn()`), `/oauth/token`, `/mcp`.
|
| Không request mạng thật nào: `Http::preventStrayRequests()` + `Http::fake()` cho phần tải tài liệu,
| và một `HostResolver` giả cho phần phân giải DNS (chặn IP nội bộ). Request ra ngoài mà không có
| stub thì `StrayRequestException` làm test đỏ.
|
| Cờ `vkcrm.mcp.client_id_metadata_documents` mặc định TẮT (cổng dừng của Task 5 chưa đạt: chưa thử
| được với Claude thật trên staging). Các test dưới đây bật nó, trừ test về cờ tắt.
*/

const CIMD_ORIGIN = 'https://khachhang.luatvukhang.com';
const CIMD_RESOURCE = 'https://khachhang.luatvukhang.com/mcp';
// URL client_id CIMD của Claude (web, desktop, mobile) chưa có trong tra cứu: dùng một URL trên
// host `claude.ai` cho ví dụ. Hai URL dưới thì có trong tra cứu [DC:739], [PL:169], [PL:200].
const CIMD_CLAUDE = 'https://claude.ai/oauth/mcp-client-metadata';
const CIMD_CLAUDE_CODE = 'https://claude.ai/oauth/claude-code-client-metadata';
const CIMD_CHATGPT = 'https://chatgpt.com/oauth/client.json';
const CIMD_VSCODE = 'https://vscode.dev/oauth/client-metadata.json';
const CIMD_CLAUDE_IP = '160.79.104.10';

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);

    config([
        'app.url' => CIMD_ORIGIN,
        'vkcrm.admin_domain' => null,
        'vkcrm.portal_domain' => null,
        'vkcrm.mcp.client_id_metadata_documents' => true,
    ]);

    Http::preventStrayRequests();

    cimdResolveTo([
        'claude.ai' => [CIMD_CLAUDE_IP],
        'chatgpt.com' => ['104.18.32.47'],
        'vscode.dev' => ['13.107.246.40'],
    ]);
});

/**
 * DNS giả: `$map` là host → danh sách IP. Host vắng mặt phân giải ra rỗng.
 *
 * @param  array<string, list<string>>  $map
 */
function cimdResolveTo(array $map): void
{
    app()->instance(HostResolver::class, new class($map) extends HostResolver
    {
        /** @param  array<string, list<string>>  $map */
        public function __construct(private readonly array $map) {}

        public function addresses(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });
}

/**
 * DNS giả phân giải MỌI host ra một IP công khai, và ghi lại các host đã được hỏi
 * (`app(HostResolver::class)->lookups`). Dùng khi test muốn chắc rằng chỉ phép kiểm URL (không phải
 * DNS) chặn một request: với bộ phân giải này, URL lọt qua phép kiểm thì request đi ra.
 */
function cimdResolveEverything(): void
{
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        /** @var list<string> */
        public array $lookups = [];

        public function addresses(string $host): array
        {
            $this->lookups[] = $host;

            return [CIMD_CLAUDE_IP];
        }
    });
}

/**
 * Tài liệu metadata như Claude phát hành: `$overrides` đổi hoặc (giá trị `null`) bỏ từng trường.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cimdDocument(string $url, array $overrides = []): array
{
    return array_filter(array_merge([
        'client_id' => $url,
        'client_name' => 'Claude',
        'redirect_uris' => [McpOAuth::REDIRECT_URI],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ], $overrides), fn ($value) => $value !== null);
}

/**
 * Một tài liệu JSON dài ĐÚNG `$bytes` byte: thêm trường đệm `x` vào {@see cimdDocument()}.
 *
 * @param  list<string>  $redirectUris
 */
function cimdSizedDocument(string $url, array $redirectUris, int $bytes): string
{
    $base = json_encode(cimdDocument($url, ['x' => '', 'redirect_uris' => $redirectUris]), JSON_UNESCAPED_SLASHES);

    return json_encode(cimdDocument($url, [
        'x' => str_repeat('a', $bytes - strlen($base)),
        'redirect_uris' => $redirectUris,
    ]), JSON_UNESCAPED_SLASHES);
}

/** Phục vụ `$body` (mảng → JSON) ở đúng `$url`. */
function cimdServe(string $url, array|string $body, int $status = 200, array $headers = []): void
{
    Http::fake([$url => Http::response(
        is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : $body,
        $status,
        ['Content-Type' => 'application/json', ...$headers],
    )]);
}

function cimdLawyer(): User
{
    return User::factory()->withRole(Role::Lawyer)->create();
}

/**
 * `GET /oauth/authorize` của một nhân sự đã đăng nhập, PKCE S256, `resource` đúng URL MCP, qua màn
 * hình đồng ý thay tạm (200 JSON mang `auth_token` khi tới được màn hình đó).
 *
 * @param  array{verifier: string, challenge: string}|null  $pkce
 */
function cimdAuthorize(User $user, string $clientId, string $redirectUri = McpOAuth::REDIRECT_URI, ?array $pkce = null): TestResponse
{
    McpOAuth::useConsentStandIn();
    $pkce ??= McpOAuth::pkce();

    return test()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'mcp:use',
        'state' => 'trang-thai-cimd',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'resource' => CIMD_RESOURCE,
    ]));
}

/** `POST /oauth/token` dạng `application/x-www-form-urlencoded`, như Claude gửi. */
function cimdToken(array $parameters): TestResponse
{
    Auth::forgetGuards();
    Once::flush();

    return test()->call('POST', '/oauth/token', $parameters, server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

/**
 * Luồng đầy đủ: authorize → màn hình thay tạm → duyệt → đổi mã. Trả cặp token.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function cimdConnect(User $user, string $clientId, string $redirectUri = McpOAuth::REDIRECT_URI): array
{
    $pkce = McpOAuth::pkce();

    $consent = cimdAuthorize($user, $clientId, $redirectUri, $pkce)->assertOk();

    $approve = test()->actingAs($user, 'web')->post('/oauth/authorize', ['auth_token' => $consent->json('auth_token')]);
    $approve->assertRedirect();

    $location = (string) $approve->headers->get('Location');
    expect($location)->toStartWith($redirectUri.'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return cimdToken([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code' => $query['code'] ?? '',
        'code_verifier' => $pkce['verifier'],
        'resource' => CIMD_RESOURCE,
    ])->assertOk()->json();
}

/** `initialize` tới `/mcp` như một request PHP-FPM mới (xem `dcrInitialize()` ở ClientRegistrationTest). */
function cimdInitialize(string $token): TestResponse
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

/** Từ chối ở `/oauth/authorize`: lỗi client của Passport, không màn hình đồng ý, không chuyển hướng. */
function cimdAssertRejected(TestResponse $response): void
{
    $response->assertStatus(401);
    expect($response->json('error'))->toBe('invalid_client')
        ->and($response->json('auth_token'))->toBeNull();
}

/** @return Collection<int, Client> */
function cimdClients(): Collection
{
    return Passport::client()->newQuery()->whereNotNull('metadata_url')->get();
}

/*
|--------------------------------------------------------------------------
| Luồng đầy đủ
|--------------------------------------------------------------------------
*/

it('CIMD luồng đầy đủ: client_id là URL → tải tài liệu một lần → dòng client is_mcp khoá theo URL → authorize, token, /mcp 200, làm mới bằng cùng URL', function () {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));
    $user = cimdLawyer();

    $tokens = cimdConnect($user, CIMD_CLAUDE);

    $client = cimdClients()->sole();
    expect($client->metadata_url)->toBe(CIMD_CLAUDE)
        ->and((bool) $client->is_mcp)->toBeTrue()
        ->and($client->secret)->toBeNull()
        ->and($client->confidential())->toBeFalse()
        ->and($client->redirect_uris)->toBe([McpOAuth::REDIRECT_URI])
        ->and($client->grant_types)->toBe(['authorization_code', 'refresh_token'])
        ->and($client->name)->toBe('Claude')
        ->and((array) McpOAuth::claims($tokens['access_token'])['aud'])->toBe([$client->getKey(), CIMD_RESOURCE]);

    cimdInitialize($tokens['access_token'])->assertOk();

    $refreshed = cimdToken([
        'grant_type' => 'refresh_token',
        'client_id' => CIMD_CLAUDE,
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->json();

    cimdInitialize($refreshed['access_token'])->assertOk();

    // Bốn lần tra client (authorize, đổi mã, làm mới) chỉ tải tài liệu MỘT lần: phần còn lại từ cache.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === CIMD_CLAUDE && $request->method() === 'GET');
});

it('CIMD tài liệu của ChatGPT và VS Code (theo tra cứu) đều tới được màn hình đồng ý', function (string $url, array $redirectUris, string $redirectUri) {
    cimdServe($url, cimdDocument($url, ['client_name' => 'Nền tảng', 'redirect_uris' => $redirectUris]));

    cimdAuthorize(cimdLawyer(), $url, $redirectUri)->assertOk();

    expect(cimdClients()->sole()->redirect_uris)->toBe($redirectUris);
})->with([
    'ChatGPT' => [CIMD_CHATGPT, ['https://chatgpt.com/connector_platform_oauth_redirect'], 'https://chatgpt.com/connector_platform_oauth_redirect'],
    'ChatGPT theo callback' => ['https://chatgpt.com/oauth/cb_9Xa-1/client.json', ['https://chatgpt.com/connector/oauth/cb_9Xa-1'], 'https://chatgpt.com/connector/oauth/cb_9Xa-1'],
    'VS Code' => [CIMD_VSCODE, ['http://127.0.0.1:33418/', 'https://vscode.dev/redirect'], 'https://vscode.dev/redirect'],
]);

it('CIMD /oauth/token cũng đi qua cùng phép kiểm: client_id URL ngoài allowlist host bị từ chối 401 invalid_client, không request nào đi ra', function () {
    cimdResolveEverything();
    Http::fake(fn (Request $request) => Http::response(json_encode(cimdDocument($request->url()), JSON_UNESCAPED_SLASHES)));

    $response = cimdToken([
        'grant_type' => 'refresh_token',
        'client_id' => 'https://evil.example/client.json',
        'refresh_token' => 'khong-quan-trong',
    ]);

    $response->assertStatus(401);
    expect($response->json('error'))->toBe('invalid_client');
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Cờ tắt: không quảng bá, không tải
|--------------------------------------------------------------------------
*/

it('CIMD cờ tắt (mặc định): client_id URL bị từ chối như một id không tồn tại, không request nào đi ra, không dòng client nào; metadata không quảng bá', function () {
    config(['vkcrm.mcp.client_id_metadata_documents' => false]);
    Http::fake();

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    Http::assertNothingSent();
    expect(cimdClients())->toHaveCount(0);
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonMissingPath('client_id_metadata_document_supported');
});

it('CIMD cờ đọc từ MCP_CLIENT_ID_METADATA_DOCUMENTS, mặc định tắt; chỉ "true" (không phải chuỗi lạ) mới bật', function () {
    $read = function (?string $value): mixed {
        $value === null ? putenv('MCP_CLIENT_ID_METADATA_DOCUMENTS') : putenv('MCP_CLIENT_ID_METADATA_DOCUMENTS='.$value);

        try {
            return (require config_path('vkcrm.php'))['mcp']['client_id_metadata_documents'];
        } finally {
            putenv('MCP_CLIENT_ID_METADATA_DOCUMENTS');
        }
    };

    expect($read(null))->toBeFalse()
        ->and($read('false'))->toBeFalse()
        ->and($read('khong'))->toBeFalse()
        ->and($read('true'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| URL client_id: hình dạng và allowlist host, kiểm TRƯỚC mọi request ra ngoài
|--------------------------------------------------------------------------
*/

it('CIMD client_id URL sai hình dạng hoặc ngoài allowlist host bị từ chối, không hỏi DNS, không request nào đi ra, không dòng client nào', function (string $url) {
    // Mọi host phân giải ra IP công khai và mọi URL trả một tài liệu hợp lệ cho chính nó: chỉ phép
    // kiểm URL đứng giữa request này và một client mới.
    cimdResolveEverything();
    Http::fake(fn (Request $request) => Http::response(json_encode(cimdDocument($request->url()), JSON_UNESCAPED_SLASHES)));

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), $url));

    Http::assertNothingSent();
    expect(app(HostResolver::class)->lookups)->toBe([])
        ->and(cimdClients())->toHaveCount(0);
})->with([
    'host ngoài allowlist' => 'https://evil.example/client.json',
    'tên miền con của host được phép' => 'https://evil.claude.ai/client.json',
    'host giả dạng' => 'https://claude.ai.evil.example/client.json',
    'http' => 'http://claude.ai/oauth/mcp-client-metadata',
    'scheme chữ hoa' => 'HTTPS://claude.ai/oauth/mcp-client-metadata',
    'host chữ hoa' => 'https://Claude.ai/oauth/mcp-client-metadata',
    'có cổng' => 'https://claude.ai:443/oauth/mcp-client-metadata',
    'có thông tin người dùng' => 'https://user@claude.ai/oauth/mcp-client-metadata',
    'có query' => 'https://claude.ai/oauth/mcp-client-metadata?a=1',
    'có fragment' => 'https://claude.ai/oauth/mcp-client-metadata#f',
    'không đường dẫn' => 'https://claude.ai',
    'đường dẫn chỉ /' => 'https://claude.ai/',
    '/ cuối' => 'https://claude.ai/oauth/',
    'đoạn ..' => 'https://claude.ai/oauth/../mcp-client-metadata',
    'đoạn .' => 'https://claude.ai/./mcp-client-metadata',
    'mã hoá phần trăm' => 'https://claude.ai/oauth/%2e%2e/mcp-client-metadata',
    'dài 256 ký tự (cột 255)' => 'https://claude.ai/'.str_repeat('a', 256 - strlen('https://claude.ai/')),
]);

it('CIMD client_id dài đúng 255 ký tự (cột oauth_clients.metadata_url) thì được tải và lưu', function () {
    $url = 'https://claude.ai/'.str_repeat('a', 255 - strlen('https://claude.ai/'));
    expect(strlen($url))->toBe(ResolveClientIdMetadataDocument::MAX_URL_LENGTH);
    cimdServe($url, cimdDocument($url));

    cimdAuthorize(cimdLawyer(), $url)->assertOk();

    expect(cimdClients()->sole()->metadata_url)->toBe($url);
});

/*
|--------------------------------------------------------------------------
| Tài liệu: kiểm nội dung sau khi tải
|--------------------------------------------------------------------------
*/

it('CIMD tài liệu không đạt bị từ chối sau đúng một lần tải, không dòng client nào', function (array|string $body) {
    cimdServe(CIMD_CLAUDE, $body);

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    Http::assertSentCount(1);
    expect(cimdClients())->toHaveCount(0);
})->with([
    'redirect_uris ngoài allowlist' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => ['https://evil.example/callback']]),
    'một redirect đúng, một sai' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => [McpOAuth::REDIRECT_URI, 'https://evil.example/callback']]),
    'redirect_uris thiếu' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => null]),
    'redirect_uris rỗng' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => []]),
    'redirect_uris là chuỗi' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => McpOAuth::REDIRECT_URI]),
    'redirect_uris là đối tượng' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => ['a' => McpOAuth::REDIRECT_URI]]),
    'redirect_uris có mục không phải chuỗi' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => [McpOAuth::REDIRECT_URI, 7]]),
    'redirect_uris có mục là mảng' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => [McpOAuth::REDIRECT_URI, [McpOAuth::REDIRECT_URI]]]),
    'redirect_uris 11 mục' => fn () => cimdDocument(CIMD_CLAUDE, ['redirect_uris' => array_fill(0, 11, McpOAuth::REDIRECT_URI)]),
    'client_id khác URL' => fn () => cimdDocument('https://claude.ai/oauth/khac'),
    'client_id thêm / cuối' => fn () => cimdDocument(CIMD_CLAUDE.'/'),
    'client_id thiếu' => fn () => cimdDocument(CIMD_CLAUDE, ['client_id' => null]),
    'token_endpoint_auth_method thiếu' => fn () => cimdDocument(CIMD_CLAUDE, ['token_endpoint_auth_method' => null]),
    'token_endpoint_auth_method client_secret_basic' => fn () => cimdDocument(CIMD_CLAUDE, ['token_endpoint_auth_method' => 'client_secret_basic']),
    'không phải JSON' => '<html>không phải JSON</html>',
    'JSON là danh sách' => '["https://claude.ai/oauth/mcp-client-metadata"]',
    'JSON là chuỗi' => '"https://claude.ai/oauth/mcp-client-metadata"',
]);

it('CIMD tài liệu có đúng 10 redirect URI (cặp dương của dòng 11 mục) thì nhận', function () {
    $uris = [McpOAuth::REDIRECT_URI, ...array_map(fn (int $port) => "http://127.0.0.1:{$port}/callback", range(40001, 40009))];
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE, ['redirect_uris' => $uris]));

    cimdAuthorize(cimdLawyer(), CIMD_CLAUDE)->assertOk();

    expect(cimdClients()->sole()->redirect_uris)->toBe($uris);
});

it('CIMD tên client: client_name dài hơn cột oauth_clients.name bị cắt còn 255 ký tự (không lỗi 500 trên MariaDB strict); thiếu hay rỗng thì là host', function (?string $clientName, string $expected) {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE, ['client_name' => $clientName]));

    cimdAuthorize(cimdLawyer(), CIMD_CLAUDE)->assertOk();

    expect(cimdClients()->sole()->name)->toBe($expected);
})->with([
    '300 ký tự' => [str_repeat('ă', 300), str_repeat('ă', 255)],
    'thiếu' => [null, 'claude.ai'],
    'chỉ khoảng trắng' => ['   ', 'claude.ai'],
]);

it('CIMD hai request tạo cùng lúc: dòng của request kia đã chèn trước thì request này dùng lại đúng dòng đó (mục unique của metadata_url), không lỗi 500', function () {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));
    $winner = (string) Str::uuid();

    // Giả lập request thắng: chèn dòng cùng `metadata_url` ngay trước lần `INSERT` của request này.
    Passport::client()::creating(function () use ($winner) {
        if (DB::table('oauth_clients')->where('metadata_url', CIMD_CLAUDE)->doesntExist()) {
            DB::table('oauth_clients')->insert([
                'id' => $winner,
                'name' => 'Claude',
                'redirect_uris' => json_encode([McpOAuth::REDIRECT_URI]),
                'grant_types' => json_encode(['authorization_code', 'refresh_token']),
                'revoked' => false,
                'is_mcp' => true,
                'metadata_url' => CIMD_CLAUDE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    cimdAuthorize(cimdLawyer(), CIMD_CLAUDE)->assertOk()->assertJsonPath('client_id', $winner);

    expect(cimdClients())->toHaveCount(1)
        ->and(cimdClients()->sole()->getKey())->toBe($winner);
});

it('CIMD tài liệu lớn hơn 16 KB bị từ chối; đúng 16 KB thì nhận', function () {
    $tooBig = cimdSizedDocument(CIMD_CLAUDE, [McpOAuth::REDIRECT_URI], MetadataDocumentFetcher::MAX_BYTES + 1);
    $exact = cimdSizedDocument(CIMD_CHATGPT, ['https://chatgpt.com/connector_platform_oauth_redirect'], MetadataDocumentFetcher::MAX_BYTES);
    expect(strlen($tooBig))->toBe(16385)->and(strlen($exact))->toBe(16384);

    cimdServe(CIMD_CLAUDE, $tooBig);
    cimdServe(CIMD_CHATGPT, $exact);

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));
    expect(cimdClients())->toHaveCount(0);

    cimdAuthorize(cimdLawyer(), CIMD_CHATGPT, 'https://chatgpt.com/connector_platform_oauth_redirect')->assertOk();
    expect(cimdClients()->sole()->metadata_url)->toBe(CIMD_CHATGPT);
});

it('CIMD không theo chuyển hướng: 302 bị từ chối và URL đích không bao giờ được gọi', function () {
    Http::fake([
        CIMD_CLAUDE => Http::response('', 302, ['Location' => 'https://claude.ai/oauth/dich']),
        'https://claude.ai/oauth/dich' => Http::response(json_encode(cimdDocument(CIMD_CLAUDE), JSON_UNESCAPED_SLASHES)),
    ]);

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://claude.ai/oauth/dich');
    expect(cimdClients())->toHaveCount(0);
});

it('CIMD trạng thái khác 200 hoặc lỗi kết nối bị từ chối, không dòng client nào', function (int|string $outcome) {
    Http::fake([CIMD_CLAUDE => $outcome === 'lỗi kết nối'
        ? Http::failedConnection('cURL error 28: Operation timed out')
        : Http::response(json_encode(cimdDocument(CIMD_CLAUDE), JSON_UNESCAPED_SLASHES), $outcome)]);

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    expect(cimdClients())->toHaveCount(0);
})->with(['201' => 201, '404' => 404, '500' => 500, 'lỗi kết nối' => 'lỗi kết nối']);

it('CIMD lần tải hỏng không bị nhớ: nền tảng sửa xong thì lần kế tiếp tải lại và nhận', function () {
    Http::fake([CIMD_CLAUDE => Http::sequence()
        ->push('', 503)
        ->push(json_encode(cimdDocument(CIMD_CLAUDE), JSON_UNESCAPED_SLASHES))]);
    $user = cimdLawyer();

    cimdAssertRejected(cimdAuthorize($user, CIMD_CLAUDE));
    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();

    Http::assertSentCount(2);
});

it('CIMD tài liệu bị từ chối được ghi log cảnh báo kèm client_id và lý do (không chứa nội dung tài liệu)', function () {
    Log::spy();
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE, ['redirect_uris' => ['https://evil.example/callback']]));

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === [
        'client_id' => CIMD_CLAUDE,
        'reason' => 'redirect_uris',
    ]);
});

/*
|--------------------------------------------------------------------------
| SSRF: phân giải DNS, chặn IP không công khai, ghim IP vào request
|--------------------------------------------------------------------------
*/

it('CIMD host phân giải về địa chỉ không công khai bị từ chối, không request nào đi ra', function (array $addresses) {
    cimdResolveTo(['claude.ai' => $addresses]);
    Http::fake();

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE));

    Http::assertNothingSent();
    expect(cimdClients())->toHaveCount(0);
})->with([
    '127.0.0.1' => [['127.0.0.1']],
    '10.0.0.5' => [['10.0.0.5']],
    '172.16.0.1' => [['172.16.0.1']],
    '192.168.1.1' => [['192.168.1.1']],
    'siêu dữ liệu đám mây 169.254.169.254' => [['169.254.169.254']],
    'CGNAT 100.64.0.1' => [['100.64.0.1']],
    '0.0.0.0' => [['0.0.0.0']],
    '::1' => [['::1']],
    'fe80::1' => [['fe80::1']],
    'fc00::1' => [['fc00::1']],
    '::ffff:127.0.0.1' => [['::ffff:127.0.0.1']],
    'một IP công khai, một IP nội bộ' => [[CIMD_CLAUDE_IP, '127.0.0.1']],
    'không phân giải được' => [[]],
]);

it('CIMD request tải tài liệu ghim đúng IP đã kiểm (CURLOPT_RESOLVE), không theo chuyển hướng, timeout 5 giây, giới hạn 16 KB phía curl', function (string $ip, string $pinned) {
    cimdResolveTo(['claude.ai' => [$ip]]);
    $captured = null;

    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(json_encode(cimdDocument(CIMD_CLAUDE), JSON_UNESCAPED_SLASHES));
    });

    cimdAuthorize(cimdLawyer(), CIMD_CLAUDE)->assertOk();

    expect($captured['allow_redirects'])->toBeFalse()
        ->and($captured['timeout'])->toBe(5)
        ->and($captured['connect_timeout'])->toBe(5)
        ->and($captured['curl'][CURLOPT_RESOLVE])->toBe(['claude.ai:443:'.$pinned])
        ->and($captured['curl'][CURLOPT_MAXFILESIZE])->toBe(16384);
})->with([
    'IPv4' => [CIMD_CLAUDE_IP, CIMD_CLAUDE_IP],
    'IPv6' => ['2606:4700::1111', '[2606:4700::1111]'],
]);

it('CIMD curl là extension bắt buộc ở production: ghim IP cần CURLOPT_RESOLVE', function () {
    expect(config('vkcrm.deployment.required_extensions'))->toContain('curl');
});

/*
|--------------------------------------------------------------------------
| Cache, giới hạn tải, upsert theo URL
|--------------------------------------------------------------------------
*/

it('CIMD tài liệu được cache một ngày: trong hạn không tải lại, quá hạn thì tải lại và cập nhật CÙNG dòng client', function () {
    Http::fake([CIMD_CLAUDE => Http::sequence()
        ->push(json_encode(cimdDocument(CIMD_CLAUDE), JSON_UNESCAPED_SLASHES))
        ->push(json_encode(cimdDocument(CIMD_CLAUDE, [
            'client_name' => 'Claude mới',
            'redirect_uris' => [McpOAuth::REDIRECT_URI, 'http://127.0.0.1/callback'],
        ]), JSON_UNESCAPED_SLASHES))]);
    $user = cimdLawyer();

    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    $id = cimdClients()->sole()->getKey();

    $this->travel(ResolveClientIdMetadataDocument::CACHE_SECONDS - 1)->seconds();
    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    Http::assertSentCount(2);

    $client = cimdClients()->sole();
    expect($client->getKey())->toBe($id)
        ->and($client->name)->toBe('Claude mới')
        ->and($client->redirect_uris)->toBe([McpOAuth::REDIRECT_URI, 'http://127.0.0.1/callback']);
});

it('CIMD mất cache (dòng client vẫn còn) thì tải lại và dùng lại đúng dòng khoá theo URL, không tạo dòng thứ hai', function () {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));
    $user = cimdLawyer();

    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    $id = cimdClients()->sole()->getKey();

    Cache::flush();
    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();

    expect(cimdClients())->toHaveCount(1)
        ->and(cimdClients()->sole()->getKey())->toBe($id);
    Http::assertSentCount(2);
});

it('CIMD allowlist redirect thu hẹp sau khi đã cache: bản cache bị kiểm lại và từ chối ngay, không chờ hết hạn', function () {
    config(['vkcrm.mcp.extra_redirect_uris' => ['https://copilot.example/callback']]);
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE, ['redirect_uris' => ['https://copilot.example/callback']]));
    $user = cimdLawyer();

    cimdAuthorize($user, CIMD_CLAUDE, 'https://copilot.example/callback')->assertOk();

    config(['vkcrm.mcp.extra_redirect_uris' => []]);

    cimdAssertRejected(cimdAuthorize($user, CIMD_CLAUDE, 'https://copilot.example/callback'));
});

it('CIMD dòng client đã bị thu hồi (revoked) không được hồi sinh bởi một tài liệu hợp lệ', function () {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));
    $user = cimdLawyer();

    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    cimdClients()->sole()->forceFill(['revoked' => true])->save();
    Once::flush();

    cimdAssertRejected(cimdAuthorize($user, CIMD_CLAUDE));

    expect((bool) cimdClients()->sole()->revoked)->toBeTrue();
});

it('CIMD giới hạn tải tài liệu toàn hệ thống: lần thứ 31 trong một phút bị từ chối mà không request nào đi ra; phút sau thì tải được', function () {
    Http::fake(fn (Request $request) => Http::response(json_encode(cimdDocument($request->url()), JSON_UNESCAPED_SLASHES)));
    $user = cimdLawyer();

    foreach (range(1, ResolveClientIdMetadataDocument::FETCHES_PER_MINUTE) as $n) {
        cimdAuthorize($user, "https://claude.ai/oauth/client-{$n}")->assertOk();
    }

    cimdAssertRejected(cimdAuthorize($user, 'https://claude.ai/oauth/client-31'));
    Http::assertSentCount(30);

    $this->travel(61)->seconds();

    cimdAuthorize($user, 'https://claude.ai/oauth/client-31')->assertOk();
    Http::assertSentCount(31);
});

it('CIMD lệnh dọn xoá dòng client CIMD cũ không còn token sống; lần dùng kế tiếp tạo lại từ cache, không tải lại', function () {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));
    $user = cimdLawyer();

    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();
    $old = cimdClients()->sole();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    Artisan::call('vkcrm:mcp-prune-clients');
    expect(cimdClients())->toHaveCount(0);

    Once::flush();
    cimdAuthorize($user, CIMD_CLAUDE)->assertOk();

    expect(cimdClients()->sole()->getKey())->not->toBe($old->getKey());
    Http::assertSentCount(1);
});

/*
|--------------------------------------------------------------------------
| Loopback `localhost` bỏ qua cổng (RFC 8252 §7.3): tài liệu CIMD của Claude Code
|--------------------------------------------------------------------------
| league/oauth2-server 9.4.1 coi chỉ `127.0.0.1` / `[::1]` là loopback; `localhost` thì so chính xác
| cả cổng. Tài liệu CIMD của Claude Code khai `http://localhost/callback` KHÔNG cổng [DC:739], còn
| Claude Code mở cổng ngẫu nhiên mỗi phiên (Task 3, PROGRESS).
*/

it('CIMD Claude Code: redirect http://localhost:<cổng>/callback được nhận và nhận mã, đổi được token', function () {
    cimdServe(CIMD_CLAUDE_CODE, cimdDocument(CIMD_CLAUDE_CODE, [
        'client_name' => 'Claude Code',
        'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
    ]));

    $tokens = cimdConnect(cimdLawyer(), CIMD_CLAUDE_CODE, 'http://localhost:53682/callback');

    cimdInitialize($tokens['access_token'])->assertOk();
});

it('CIMD Claude Code: 127.0.0.1 với cổng bất kỳ cũng tới màn hình đồng ý', function () {
    cimdServe(CIMD_CLAUDE_CODE, cimdDocument(CIMD_CLAUDE_CODE, [
        'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
    ]));

    cimdAuthorize(cimdLawyer(), CIMD_CLAUDE_CODE, 'http://127.0.0.1:61000/callback')->assertOk();
});

it('CIMD Claude Code: redirect loopback khác đường dẫn, cổng ngoài 1–65535 hay host giả localhost bị từ chối', function (string $redirectUri) {
    cimdServe(CIMD_CLAUDE_CODE, cimdDocument(CIMD_CLAUDE_CODE, [
        'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
    ]));

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE_CODE, $redirectUri));
})->with([
    'khác đường dẫn' => 'http://localhost:53682/khac',
    'cổng 65536' => 'http://localhost:65536/callback',
    'host giả' => 'http://localhost.evil.example:53682/callback',
    'https' => 'https://localhost:53682/callback',
]);

it('CIMD redirect_uri không có trong tài liệu bị từ chối (league so chính xác), kể cả khi nó nằm trong allowlist R7', function (string $redirectUri) {
    cimdServe(CIMD_CLAUDE, cimdDocument(CIMD_CLAUDE));

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), CIMD_CLAUDE, $redirectUri));
})->with([
    'host lạ' => 'https://evil.example/callback',
    'thêm / cuối' => McpOAuth::REDIRECT_URI.'/',
    'trong allowlist nhưng không khai trong tài liệu' => 'https://chatgpt.com/connector_platform_oauth_redirect',
    'loopback không khai trong tài liệu' => 'http://localhost:53682/callback',
]);

it('CIMD bỏ qua cổng chỉ áp cho client CIMD: client DCR đăng ký http://localhost:8787/callback vẫn đòi đúng cổng đó', function () {
    $client = McpOAuth::client(['http://localhost:8787/callback']);

    cimdAssertRejected(cimdAuthorize(cimdLawyer(), $client->getKey(), 'http://localhost:9999/callback'));
});
