<?php

use App\Enums\Role;
use App\Http\Middleware\Mcp\RestrictOAuthGrantTypes;
use App\Models\User;
use App\Support\Mcp\McpAccessToken;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Bridge\AccessToken as PassportAccessToken;
use Laravel\Passport\Bridge\Client as ClientEntity;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 2 — metadata OAuth (RFC 9728, RFC 8414), `iss` (RFC 9207), `resource` (RFC 8707), `aud`
|--------------------------------------------------------------------------
| Mọi test đi qua HTTP thật: route `/.well-known/*`, controller và middleware của Passport,
| `/oauth/token`, `/mcp`. Màn hình đồng ý chưa có (Task 4), nên luồng authorize dùng
| `McpOAuth::useConsentStandIn()`: một JSON trả `auth_token` thay cho trang HTML, rồi test tự bấm
| "duyệt" (`POST /oauth/authorize`) hoặc "từ chối" (`DELETE /oauth/authorize`).
|
| APP_URL được đặt về URL production của kế hoạch để các giá trị trong test đọc được bằng mắt. URL
| chuẩn dựng từ cấu hình (`App\Support\Mcp\McpEndpoint`), không từ host của request.
*/

const OAUTH_META_ORIGIN = 'https://khachhang.luatvukhang.com';
const OAUTH_META_RESOURCE = 'https://khachhang.luatvukhang.com/mcp';

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    // Task 6: công tắc toàn hệ thống mở; `oauthMetaLawyer()` đủ điều kiện của `EnsureMcpAccess`.
    McpOAuth::openServer();

    config([
        'app.url' => OAUTH_META_ORIGIN,
        'vkcrm.admin_domain' => null,
        'vkcrm.portal_domain' => null,
    ]);
});

function oauthMetaLawyer(): User
{
    return User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();
}

/**
 * `GET /oauth/authorize` của một nhân sự đã đăng nhập. Mặc định là một yêu cầu hợp lệ (PKCE S256,
 * `resource` đúng URL MCP); `$overrides` đổi hoặc (giá trị `null`) bỏ từng tham số.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{response: TestResponse, query: array<string, mixed>, verifier: string}
 */
function oauthMetaAuthorize(User $user, Client $client, array $overrides = []): array
{
    $pkce = McpOAuth::pkce();

    $query = array_filter(array_merge([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'scope' => 'mcp:use',
        'state' => 'trang-thai-'.Str::random(8),
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'resource' => OAUTH_META_RESOURCE,
    ], $overrides), fn ($value) => $value !== null);

    return [
        'response' => test()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query)),
        'query' => $query,
        'verifier' => $pkce['verifier'],
    ];
}

/**
 * Phản hồi phải là chuyển hướng về ĐÚNG redirect URI của client; trả query của `Location`.
 *
 * @return array<string, string>
 */
function oauthMetaRedirectQuery(TestResponse $response): array
{
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith(McpOAuth::REDIRECT_URI.'?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Luồng đầy đủ tới lúc có mã: GET authorize → màn hình thay tạm → POST duyệt.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{code: string, verifier: string, redirect: array<string, string>, query: array<string, mixed>}
 */
function oauthMetaApprovedCode(User $user, Client $client, array $overrides = []): array
{
    McpOAuth::useConsentStandIn();

    ['response' => $consent, 'query' => $query, 'verifier' => $verifier] = oauthMetaAuthorize($user, $client, $overrides);
    $consent->assertOk();

    $redirect = oauthMetaRedirectQuery(
        test()->actingAs($user, 'web')->post('/oauth/authorize', ['auth_token' => $consent->json('auth_token')]),
    );

    return ['code' => $redirect['code'], 'verifier' => $verifier, 'redirect' => $redirect, 'query' => $query];
}

/**
 * `POST /oauth/token` dạng `application/x-www-form-urlencoded`. Tham số đi vào phần thân đã phân
 * tích (như PHP-FPM tự làm với kiểu nội dung này), không phải JSON.
 *
 * @param  array<string, string>  $parameters
 */
function oauthMetaTokenRequest(array $parameters): TestResponse
{
    return test()->call('POST', '/oauth/token', $parameters, server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

/** @param  array<string, string>  $extra */
function oauthMetaExchange(Client $client, string $code, ?string $verifier, array $extra = []): TestResponse
{
    return oauthMetaTokenRequest(array_filter(array_merge([
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => $code,
        'code_verifier' => $verifier,
    ], $extra), fn ($value) => $value !== null));
}

/** @param  array<string, string>  $extra */
function oauthMetaRefresh(Client $client, string $refreshToken, array $extra = []): TestResponse
{
    return oauthMetaTokenRequest(array_merge([
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $refreshToken,
    ], $extra));
}

/** `initialize` (2025-11-25) tới `/mcp` với một bearer. */
function oauthMetaInitialize(string $token): TestResponse
{
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

/** `aud` của một JWT luôn dưới dạng danh sách (lcobucci ghi một phần tử thành chuỗi). */
function oauthMetaAudience(string $jwt): array
{
    return (array) McpOAuth::claims($jwt)['aud'];
}

/*
|--------------------------------------------------------------------------
| PRM (RFC 9728) và AS metadata (RFC 8414)
|--------------------------------------------------------------------------
*/

dataset('oauth meta prm paths', [
    'gốc' => '/.well-known/oauth-protected-resource',
    'lồng /mcp' => '/.well-known/oauth-protected-resource/mcp',
]);

dataset('oauth meta as paths', [
    'gốc' => '/.well-known/oauth-authorization-server',
    'lồng /mcp' => '/.well-known/oauth-authorization-server/mcp',
]);

it('R7 PRM ở cả hai đường dẫn: resource là đúng URL MCP, không / cuối; authorization_servers[0] là issuer của AS metadata', function (string $path) {
    $prm = $this->getJson($path)->assertOk();
    $issuer = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json('issuer');

    expect($prm->json())->toBe([
        'resource' => OAUTH_META_RESOURCE,
        'authorization_servers' => [OAUTH_META_ORIGIN],
        'scopes_supported' => ['mcp:use'],
        'bearer_methods_supported' => ['header'],
    ])
        ->and($prm->json('resource'))->not->toEndWith('/')
        ->and($prm->json('authorization_servers.0'))->toBe($issuer);
})->with('oauth meta prm paths');

it('R7 AS metadata tự khai ở cả hai đường dẫn: đủ trường R7, điểm cuối tuyệt đối trên URL chuẩn, không offline_access', function (string $path) {
    $metadata = $this->getJson($path)->assertOk();

    expect($metadata->json('issuer'))->toBe(OAUTH_META_ORIGIN)
        ->and($metadata->json('authorization_endpoint'))->toBe(OAUTH_META_ORIGIN.'/oauth/authorize')
        ->and($metadata->json('token_endpoint'))->toBe(OAUTH_META_ORIGIN.'/oauth/token')
        ->and($metadata->json('registration_endpoint'))->toBe(OAUTH_META_ORIGIN.'/oauth/register')
        ->and($metadata->json('response_types_supported'))->toBe(['code'])
        ->and($metadata->json('response_modes_supported'))->toBe(['query'])
        ->and($metadata->json('grant_types_supported'))->toBe(['authorization_code', 'refresh_token'])
        ->and($metadata->json('token_endpoint_auth_methods_supported'))->toBe(['none'])
        ->and($metadata->json('code_challenge_methods_supported'))->toBe(['S256'])
        ->and($metadata->json('scopes_supported'))->toBe(['mcp:use'])
        ->and($metadata->json('scopes_supported'))->not->toContain('offline_access')
        ->and($metadata->json('authorization_response_iss_parameter_supported'))->toBeTrue();
})->with('oauth meta as paths');

it('R7 tách ADMIN_DOMAIN/PORTAL_DOMAIN: gọi qua tên miền cổng khách vẫn nhận MỘT URL chuẩn trên tên miền quản trị (PRM, AS metadata, WWW-Authenticate)', function () {
    config([
        'vkcrm.admin_domain' => 'quantri.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);

    $portal = 'https://khachhang.luatvukhang.test';
    $admin = 'https://quantri.luatvukhang.test';

    $this->getJson($portal.'/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', $admin.'/mcp')
        ->assertJsonPath('authorization_servers.0', $admin);

    $this->getJson($portal.'/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('issuer', $admin)
        ->assertJsonPath('authorization_endpoint', $admin.'/oauth/authorize')
        ->assertJsonPath('token_endpoint', $admin.'/oauth/token');

    $challenge = $this->postJson($portal.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

    $challenge->assertUnauthorized();
    expect($challenge->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata="'.$admin.'/.well-known/oauth-protected-resource/mcp"');
});

it('R7 authorization_response_iss_parameter_supported chỉ có khi middleware gắn iss đứng trong nhóm route của Passport', function () {
    config(['passport.middleware' => [RestrictOAuthGrantTypes::class]]);

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonMissingPath('authorization_response_iss_parameter_supported');
});

it('R7 không quảng bá CIMD khi Task 5 chưa đạt: cờ cấu hình mặc định tắt và metadata không có client_id_metadata_document_supported; bật cờ thì có', function () {
    expect(config('vkcrm.mcp.client_id_metadata_documents'))->toBeFalse();

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonMissingPath('client_id_metadata_document_supported');

    config(['vkcrm.mcp.client_id_metadata_documents' => true]);

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('client_id_metadata_document_supported', true);
});

it('R7 Mcp::oauthRoutes() của gói gọi SAU không đè được metadata của app ở bốn đường dẫn (khai URI cụ thể, không mẫu {path})', function () {
    Mcp::oauthRoutes();

    foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server/mcp'] as $path) {
        $this->getJson($path)->assertOk()->assertJsonPath('token_endpoint_auth_methods_supported', ['none']);
    }

    foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $path) {
        $this->getJson($path)->assertOk()
            ->assertJsonPath('resource', OAUTH_META_RESOURCE)
            ->assertJsonPath('bearer_methods_supported', ['header']);
    }
});

/*
|--------------------------------------------------------------------------
| Luồng authorize → token đầy đủ (PKCE S256), `iss` (RFC 9207), `aud`
|--------------------------------------------------------------------------
*/

it('R7 luồng đầy đủ: authorize → duyệt → redirect mang code, state và iss = issuer → /oauth/token (form-urlencoded) → token có aud [id client, URL MCP] → /mcp 200', function () {
    $user = oauthMetaLawyer();
    $client = McpOAuth::client();

    ['code' => $code, 'verifier' => $verifier, 'redirect' => $redirect, 'query' => $query] = oauthMetaApprovedCode($user, $client);

    expect($redirect['state'])->toBe($query['state'])
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN)
        ->and($redirect['iss'])->toBe($this->getJson('/.well-known/oauth-authorization-server')->json('issuer'));

    $tokens = oauthMetaExchange($client, $code, $verifier, ['resource' => OAUTH_META_RESOURCE])->assertOk()->json();

    expect(oauthMetaAudience($tokens['access_token']))->toBe([$client->getKey(), OAUTH_META_RESOURCE])
        ->and(McpOAuth::claims($tokens['access_token'])['sub'])->toBe((string) $user->getKey());

    oauthMetaInitialize($tokens['access_token'])->assertOk();
});

it('R7 iss: từ chối trên màn hình đồng ý (DELETE) chuyển hướng với error=access_denied, state và iss', function () {
    $user = oauthMetaLawyer();
    McpOAuth::useConsentStandIn();

    ['response' => $consent, 'query' => $query] = oauthMetaAuthorize($user, McpOAuth::client());

    $redirect = oauthMetaRedirectQuery(
        $this->actingAs($user, 'web')->delete('/oauth/authorize', ['auth_token' => $consent->json('auth_token')]),
    );

    expect($redirect['error'])->toBe('access_denied')
        ->and($redirect['state'])->toBe($query['state'])
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN)
        ->and($redirect)->not->toHaveKey('code');
});

/*
 * Nhánh Passport tự duyệt ngay ở GET không còn từ Task 4
 * (`App\Http\Controllers\Mcp\ConsentAuthorizationController`, AuthorizeScreenTest): người đã có token
 * còn hạn cho client đó vẫn thấy màn hình đồng ý. Chuyển hướng duy nhất ở GET cho người đó mà không
 * qua màn hình là lỗi `consent_required` của `prompt=none` — và nó cũng mang `iss`.
 */
it('R7 iss: prompt=none với người đã có token còn hạn cho client đó nhận consent_required (không còn tự duyệt, Task 4), mang iss', function () {
    $user = oauthMetaLawyer();
    $client = McpOAuth::client();
    McpOAuth::issueTokens($this, $user, $client);
    McpOAuth::useConsentStandIn();

    ['response' => $response] = oauthMetaAuthorize($user, $client, ['prompt' => 'none']);
    $redirect = oauthMetaRedirectQuery($response);

    expect($redirect['error'])->toBe('consent_required')
        ->and($redirect)->not->toHaveKey('code')
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN);
});

it('R7 iss: lỗi mà Passport tự chuyển hướng về client (scope không tồn tại) cũng mang iss', function () {
    McpOAuth::useConsentStandIn();

    ['response' => $response, 'query' => $query] = oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), ['scope' => 'khong-ton-tai']);
    $redirect = oauthMetaRedirectQuery($response);

    expect($redirect['error'])->toBe('invalid_scope')
        ->and($redirect['state'])->toBe($query['state'])
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN);
});

/*
|--------------------------------------------------------------------------
| PKCE: chỉ S256, bắt buộc với mọi client
|--------------------------------------------------------------------------
*/

it('R7 PKCE: đổi mã mà thiếu code_verifier bị từ chối invalid_request, không cấp token; cùng mã kèm verifier thì cấp', function () {
    $client = McpOAuth::client();
    ['code' => $code, 'verifier' => $verifier] = oauthMetaApprovedCode(oauthMetaLawyer(), $client);

    oauthMetaExchange($client, $code, null)->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    expect(Passport::token()->newQuery()->count())->toBe(0);

    oauthMetaExchange($client, $code, $verifier)->assertOk();
});

it('R7 PKCE: code_challenge_method khác S256 bị từ chối invalid_request (chuyển hướng về client kèm state và iss), không hiện màn hình đồng ý, không mã nào', function (array $overrides) {
    McpOAuth::useConsentStandIn();

    ['response' => $response, 'query' => $query] = oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), $overrides);
    $redirect = oauthMetaRedirectQuery($response);

    expect($redirect['error'])->toBe('invalid_request')
        ->and($redirect['state'])->toBe($query['state'])
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN)
        ->and(session()->has('authRequest'))->toBeFalse()
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
})->with([
    'plain' => [['code_challenge_method' => 'plain']],
    'không khai method (RFC 7636: mặc định là plain)' => [['code_challenge_method' => null]],
]);

it('R7 PKCE bắt buộc cả với client confidential (league chỉ bắt buộc với client công khai); cùng client kèm S256 thì tới màn hình đồng ý', function () {
    $user = oauthMetaLawyer();
    $confidential = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Bi mat', [McpOAuth::REDIRECT_URI], confidential: true);
    McpOAuth::useConsentStandIn();

    ['response' => $response] = oauthMetaAuthorize($user, $confidential, ['code_challenge' => null, 'code_challenge_method' => null]);

    expect(oauthMetaRedirectQuery($response)['error'])->toBe('invalid_request')
        ->and(session()->has('authRequest'))->toBeFalse();

    oauthMetaAuthorize($user, $confidential)['response']->assertOk()->assertJsonStructure(['auth_token']);
});

it('R7 PKCE: client công khai không gửi code_challenge thì không có mã nào được cấp', function () {
    McpOAuth::useConsentStandIn();

    ['response' => $response] = oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), ['code_challenge' => null, 'code_challenge_method' => null]);

    expect($response->getStatusCode())->not->toBe(200)
        ->and((string) $response->headers->get('Location'))->not->toContain('code=')
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| resource (RFC 8707): chỉ URL MCP chuẩn, `invalid_target` cho mọi giá trị khác
|--------------------------------------------------------------------------
*/

dataset('oauth meta foreign resources', [
    'máy chủ khác' => ['https://evil.example/mcp'],
    'tên miền con giả' => ['https://khachhang.luatvukhang.com.evil.example/mcp'],
    'đường dẫn khác' => [OAUTH_META_ORIGIN.'/khac'],
    'chỉ origin' => [OAUTH_META_ORIGIN],
    'http thay https' => ['http://khachhang.luatvukhang.com/mcp'],
    'kèm query' => [OAUTH_META_RESOURCE.'?a=1'],
    'kèm fragment' => [OAUTH_META_RESOURCE.'#x'],
    'hai giá trị, một sai' => [[OAUTH_META_RESOURCE, 'https://evil.example/mcp']],
]);

it('R7 authorize: resource khác URL MCP chuẩn bị từ chối invalid_target (chuyển hướng về client kèm state và iss), không hiện màn hình đồng ý', function (string|array $resource) {
    McpOAuth::useConsentStandIn();

    ['response' => $response, 'query' => $query] = oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), ['resource' => $resource]);
    $redirect = oauthMetaRedirectQuery($response);

    expect($redirect['error'])->toBe('invalid_target')
        ->and($redirect['state'])->toBe($query['state'])
        ->and($redirect['iss'])->toBe(OAUTH_META_ORIGIN)
        ->and(session()->has('authRequest'))->toBeFalse()
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
})->with('oauth meta foreign resources');

it('R7 authorize: resource đúng URL MCP (kể cả chữ hoa ở scheme/host hay một / cuối) hoặc không gửi resource thì tới màn hình đồng ý', function (?string $resource) {
    McpOAuth::useConsentStandIn();

    oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), ['resource' => $resource])['response']
        ->assertOk()
        ->assertJsonStructure(['auth_token']);
})->with([
    'đúng URL chuẩn' => [OAUTH_META_RESOURCE],
    'chữ hoa ở scheme và host' => ['HTTPS://KhachHang.LuatVuKhang.com/mcp'],
    'một / cuối' => [OAUTH_META_RESOURCE.'/'],
    'không gửi resource' => [null],
]);

it('R7 resource sai kèm redirect_uri chưa đăng ký: không chuyển hướng tới URI chưa kiểm, lỗi client của Passport đi trước', function () {
    McpOAuth::useConsentStandIn();

    ['response' => $response] = oauthMetaAuthorize(oauthMetaLawyer(), McpOAuth::client(), [
        'redirect_uri' => 'https://evil.example/callback',
        'resource' => 'https://evil.example/mcp',
    ]);

    expect($response->isRedirect())->toBeFalse()
        ->and((string) $response->headers->get('Location'))->not->toContain('evil.example')
        ->and($response->json('error'))->toBe('invalid_client');
});

it('R7 /oauth/token: resource khác URL MCP trả 400 invalid_target và không cấp token; cùng mã với resource đúng thì cấp', function (string|array $resource) {
    $client = McpOAuth::client();
    ['code' => $code, 'verifier' => $verifier] = oauthMetaApprovedCode(oauthMetaLawyer(), $client);

    oauthMetaExchange($client, $code, $verifier, ['resource' => $resource])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_target');

    expect(Passport::token()->newQuery()->count())->toBe(0);

    oauthMetaExchange($client, $code, $verifier, ['resource' => OAUTH_META_RESOURCE])->assertOk();
})->with('oauth meta foreign resources');

it('R7 refresh_token: resource khác URL MCP trả 400 invalid_target; refresh token đó vẫn dùng được với resource đúng', function () {
    $client = McpOAuth::client();
    $tokens = McpOAuth::issueTokens($this, oauthMetaLawyer(), $client);

    oauthMetaRefresh($client, $tokens['refresh_token'], ['resource' => 'https://evil.example/mcp'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_target');

    oauthMetaRefresh($client, $tokens['refresh_token'], ['resource' => OAUTH_META_RESOURCE])->assertOk();
});

/*
|--------------------------------------------------------------------------
| aud: token chỉ dùng được cho đúng URL MCP này
|--------------------------------------------------------------------------
*/

it('R7 aud: token cấp cho một resource khác (URL MCP lúc cấp khác URL chuẩn hiện tại) bị /mcp từ chối 401 invalid_token', function () {
    $user = oauthMetaLawyer();

    config(['app.url' => 'https://may-chu-khac.example']);
    $foreign = McpOAuth::accessToken($this, $user);
    config(['app.url' => OAUTH_META_ORIGIN]);

    expect(oauthMetaAudience($foreign))->toContain('https://may-chu-khac.example/mcp')
        ->not->toContain(OAUTH_META_RESOURCE);

    $response = oauthMetaInitialize($foreign);

    $response->assertUnauthorized();
    expect($response->headers->get('WWW-Authenticate'))->toContain('error="invalid_token"');

    oauthMetaInitialize(McpOAuth::accessToken($this, $user))->assertOk();
});

it('R7 aud: token Passport gốc (aud chỉ có id client, không có URL MCP) bị /mcp từ chối 401; cùng token ký bằng entity của app thì 200', function () {
    $jti = McpOAuth::tokenId(McpOAuth::accessToken($this, oauthMetaLawyer()));
    $stock = McpOAuth::resign($jti, new DateTimeImmutable('+10 minutes'), entity: PassportAccessToken::class);

    expect(oauthMetaAudience($stock))->not->toContain(OAUTH_META_RESOURCE);

    oauthMetaInitialize($stock)->assertUnauthorized();
    oauthMetaInitialize(McpOAuth::resign($jti, new DateTimeImmutable('+10 minutes')))->assertOk();
});

it('R7 entity access token của app chỉ khác token gốc của Passport ở aud: cùng tập claim, aud[0] vẫn là id client (Passport tìm client theo aud[0])', function () {
    $sign = function (string $class): array {
        $entity = new $class('42', [new Scope('mcp:use')], new ClientEntity('client-thu', 'Client thử', [McpOAuth::REDIRECT_URI]));
        $entity->setIdentifier('jti-thu');
        $entity->setExpiryDateTime(new DateTimeImmutable('2030-01-01 00:00:00'));
        $entity->setPrivateKey(new CryptKey((string) config('passport.private_key'), null, false));

        return McpOAuth::claims($entity->toString());
    };

    $ours = $sign(McpAccessToken::class);
    $stock = $sign(PassportAccessToken::class);

    expect(Passport::$accessTokenEntity)->toBe(McpAccessToken::class)
        ->and(array_keys($ours))->toEqualCanonicalizing(array_keys($stock))
        ->and(Arr::except($ours, ['aud', 'iat', 'nbf']))->toEqual(Arr::except($stock, ['aud', 'iat', 'nbf']))
        ->and((array) $stock['aud'])->toBe(['client-thu'])
        ->and($ours['aud'])->toBe(['client-thu', OAUTH_META_RESOURCE]);
});

/*
|--------------------------------------------------------------------------
| /oauth/token: form-urlencoded, refresh xoay vòng
|--------------------------------------------------------------------------
*/

it('R7 /oauth/token nhận application/x-www-form-urlencoded và trả JSON có access_token, refresh_token', function () {
    $client = McpOAuth::client();
    ['code' => $code, 'verifier' => $verifier] = oauthMetaApprovedCode(oauthMetaLawyer(), $client);

    $response = oauthMetaExchange($client, $code, $verifier);

    $response->assertOk()->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('R7 refresh xoay vòng: refresh token mới khác cũ, refresh token cũ dùng lại trả invalid_grant, access token cũ hết hiệu lực', function () {
    $client = McpOAuth::client();
    $first = McpOAuth::issueTokens($this, oauthMetaLawyer(), $client);

    $second = oauthMetaRefresh($client, $first['refresh_token'])->assertOk()->json();

    expect($second['refresh_token'])->not->toBe($first['refresh_token']);

    oauthMetaRefresh($client, $first['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    oauthMetaInitialize($first['access_token'])->assertUnauthorized();
    oauthMetaInitialize($second['access_token'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| WWW-Authenticate (RFC 6750 §3, MCP "scope challenge")
|--------------------------------------------------------------------------
*/

it('R7 401 với bearer đã gửi nhưng không hợp lệ mang error="invalid_token"; không gửi token thì không có mã lỗi (RFC 6750 §3.1)', function () {
    $invalid = test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], ['Authorization' => 'Bearer khong.phai.jwt']);

    $invalid->assertUnauthorized();
    expect($invalid->headers->get('WWW-Authenticate'))
        ->toContain('error="invalid_token"')
        ->toContain('resource_metadata="'.OAUTH_META_ORIGIN.'/.well-known/oauth-protected-resource/mcp"');

    $anonymous = test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

    $anonymous->assertUnauthorized();
    expect($anonymous->headers->get('WWW-Authenticate'))->not->toContain('error=');
});

it('R7 403 vì token thiếu mcp:use mang WWW-Authenticate error="insufficient_scope", scope="mcp:use" và resource_metadata; 403 vì Origin lạ thì không', function () {
    $scopeless = oauthMetaInitialize(McpOAuth::accessToken($this, oauthMetaLawyer(), scope: ''));

    $scopeless->assertForbidden();
    expect($scopeless->headers->get('WWW-Authenticate'))
        ->toStartWith('Bearer ')
        ->toContain('error="insufficient_scope"')
        ->toContain('scope="mcp:use"')
        ->toContain('resource_metadata="'.OAUTH_META_ORIGIN.'/.well-known/oauth-protected-resource/mcp"');

    $origin = test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], ['Origin' => 'https://evil.example']);

    $origin->assertForbidden();
    expect($origin->headers->has('WWW-Authenticate'))->toBeFalse();
});
