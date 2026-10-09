<?php

namespace Tests\Support;

use App\Actions\Mcp\RegisterMcpClient;
use App\Actions\Settings\WriteSettings;
use App\Models\User;
use App\Support\Mcp\McpSwitches;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessToken as AccessTokenEntity;
use Laravel\Passport\Bridge\Client as ClientEntity;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as UserEntity;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use RuntimeException;

/**
 * Token Passport THẬT cho test của M11 — ký bằng khoá RSA thật, lưu vào `oauth_access_tokens`
 * bằng repository thật, kiểm bằng `ResourceServer` thật. Không dùng `Passport::actingAs()`: helper
 * đó gắn người dùng thẳng vào guard và bỏ qua đúng những thứ M11 phải chứng minh (chữ ký, hạn,
 * thu hồi, scope, middleware của `/mcp`).
 *
 * {@see self::issueTokens()} đi đúng đường của `ApproveAuthorizationController` của Passport (dựng
 * `AuthorizationRequest` từ một request `/oauth/authorize` có PKCE S256, gắn người dùng, duyệt, lấy
 * mã), rồi đổi mã lấy token qua HTTP `POST /oauth/token` như một client thật. Chỉ bỏ qua MÀN HÌNH
 * đồng ý (và các điều kiện từ chối của nó), thứ Task 4 dựng và test riêng (`AuthorizeScreenTest`).
 */
final class McpOAuth
{
    public const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    /**
     * Cặp khoá RSA sinh một lần cho cả tiến trình test (sinh khoá 2048 bit tốn vài trăm mili giây)
     * và đưa vào cấu hình dạng NỘI DUNG khoá, không phải tệp: `storage/oauth-*.key` của máy đang
     * chạy không bị đụng tới. Gọi TRƯỚC khi `AuthorizationServer`/`ResourceServer` được phân giải
     * (cả hai là singleton đọc khoá lúc dựng).
     */
    public static function useTestKeys(): void
    {
        if (self::$keys === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if ($resource === false || ! openssl_pkey_export($resource, $private)) {
                throw new RuntimeException('Không sinh được khoá RSA cho test.');
            }

            self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
        }

        config([
            'passport.private_key' => self::$keys['private'],
            'passport.public_key' => self::$keys['public'],
        ]);
    }

    /**
     * Client công khai (không secret) cho grant `authorization_code` + `refresh_token`, mang cờ
     * `is_mcp` — tạo bằng CHÍNH Action của đăng ký động `POST /oauth/register`
     * (`App\Actions\Mcp\RegisterMcpClient`, Task 3), nên cùng hình dạng với client mà Claude hay
     * ChatGPT đăng ký. Redirect URI phải nằm trong allowlist. Client KHÔNG mang cờ (token của nó bị
     * `/mcp` từ chối) thì tạo bằng `passport:client` hoặc `ClientRepository`, như
     * `ClientRegistrationTest` làm.
     *
     * @param  list<string>  $redirectUris
     */
    public static function client(array $redirectUris = [self::REDIRECT_URI]): Client
    {
        return app(RegisterMcpClient::class)->handle('Client thử', $redirectUris);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public static function issueTokens(TestCase $test, User $user, ?Client $client = null, string $scope = 'mcp:use'): array
    {
        $client ??= self::client();
        ['code' => $code, 'verifier' => $verifier] = self::authorizationCode($user, $client, $scope);

        $response = $test->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => $client->redirect_uris[0],
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        $response->assertOk();

        return $response->json();
    }

    public static function accessToken(TestCase $test, User $user, ?Client $client = null, string $scope = 'mcp:use'): string
    {
        return self::issueTokens($test, $user, $client, $scope)['access_token'];
    }

    /**
     * Nửa đầu của {@see self::issueTokens()}: một mã uỷ quyền THẬT (dòng `oauth_auth_codes`) cho
     * người này, CHƯA đổi lấy token — như lúc nhân sự vừa bấm "Đồng ý" và client chưa kịp gọi
     * `/oauth/token`. Trả kèm `verifier` PKCE để đổi mã sau.
     *
     * @return array{code: string, verifier: string}
     */
    public static function authorizationCode(User $user, Client $client, string $scope = 'mcp:use'): array
    {
        ['verifier' => $verifier, 'challenge' => $challenge] = self::pkce();

        $server = app(AuthorizationServer::class);

        $authRequest = $server->validateAuthorizationRequest(
            (new ServerRequest('GET', url('/oauth/authorize')))->withQueryParams(array_filter([
                'response_type' => 'code',
                'client_id' => $client->getKey(),
                'redirect_uri' => $client->redirect_uris[0],
                'scope' => $scope,
                'state' => Str::random(16),
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ], fn ($value) => $value !== '')),
        );

        $authRequest->setUser(new UserEntity($user->getAuthIdentifier()));
        $authRequest->setAuthorizationApproved(true);

        $location = $server->completeAuthorizationRequest($authRequest, new Psr7Response)->getHeaderLine('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['code' => (string) ($query['code'] ?? ''), 'verifier' => $verifier];
    }

    /**
     * Hai công tắc toàn hệ thống của R2 (`mcp.enabled`, `mcp.write_enabled`, bảng `settings`), ghi
     * qua bước ghi chung `WriteSettings` (Task 15 dựng Action và màn hình của chúng). Mặc định của
     * hệ thống là TẮT cả hai, nên mọi test gọi `/mcp` thành công phải mở công tắc trước — và người
     * gọi phải có `ai_access` cùng lời cam kết R12 (`UserFactory::withAiAccess()`).
     */
    public static function openServer(bool $enabled = true, bool $write = false): void
    {
        app(WriteSettings::class)->handle([
            McpSwitches::ENABLED => $enabled ? McpSwitches::ON : McpSwitches::OFF,
            McpSwitches::WRITE_ENABLED => $write ? McpSwitches::ON : McpSwitches::OFF,
        ], null);
    }

    /**
     * Một cặp PKCE S256 (RFC 7636 §4.1–4.2): `verifier` 64 ký tự, `challenge` =
     * BASE64URL(SHA256(verifier)) không dấu `=`.
     *
     * @return array{verifier: string, challenge: string}
     */
    public static function pkce(): array
    {
        $verifier = Str::random(64);

        return [
            'verifier' => $verifier,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
    }

    /**
     * Màn hình đồng ý THAY TẠM cho test luồng OAuth: bind `AuthorizationViewResponse` của Passport
     * thành một JSON mang `auth_token` mà `GET /oauth/authorize` vừa lưu vào phiên, đè màn hình thật
     * của Task 4 (`App\Http\Responses\Mcp\ConsentScreenResponse`). Test đọc mã đó rồi tự gửi
     * `POST /oauth/authorize` (duyệt) hay `DELETE /oauth/authorize` (từ chối), như hai nút của màn
     * hình thật gửi. Mọi thứ khác của luồng là mã thật: controller (của app, Task 4), phiên,
     * middleware của nhóm route, `/oauth/token` — và bước "Đồng ý" vẫn kiểm lại mọi điều kiện từ
     * chối, nên người gọi phải đủ điều kiện. Màn hình thật (nội dung, từ chối, nhật ký):
     * `AuthorizeScreenTest`, không dùng hàm này.
     */
    public static function useConsentStandIn(): void
    {
        Passport::authorizationView(fn (array $parameters) => response()->json([
            'auth_token' => $parameters['authToken'],
            'client_id' => $parameters['client']->getKey(),
        ]));
    }

    /**
     * Ký lại một token ĐÃ CÓ trong `oauth_access_tokens` (cùng `jti`, cùng người, cùng client, cùng
     * scope, chưa thu hồi) với một hạn khác, bằng chính khoá riêng của server.
     *
     * Vì sao không "du hành thời gian": bộ kiểm token của league/oauth2-server đọc đồng hồ HỆ THỐNG
     * (`Lcobucci\Clock\SystemClock`, `new DateTimeImmutable('now')`), và lúc cấp cũng vậy — cả hai
     * không nhìn `Carbon::setTestNow()`, nên `$this->travel()` không làm một token hết hạn. Ký lại với
     * `exp` trong quá khứ là cách duy nhất để hỏi đúng câu "token quá hạn có bị từ chối không" mà
     * không đổi đồng hồ của máy.
     *
     * `$withUser = false` dựng token không có `sub` người dùng (hình dạng của một token
     * `client_credentials`) để hỏi "token không gắn người nào có qua được `/mcp` không".
     *
     * `$entity` là lớp entity dùng để ký. Mặc định là lớp Passport đang dùng để cấp token
     * (`Passport::$accessTokenEntity`; từ Task 2 là `App\Support\Mcp\McpAccessToken`, `aud` = id
     * client + URL MCP). Truyền `Laravel\Passport\Bridge\AccessToken` để dựng một token Passport GỐC
     * (`aud` chỉ có id client), cho câu hỏi "token không mang URL MCP trong `aud` có qua được `/mcp`
     * không".
     *
     * @param  class-string<AccessTokenEntity>|null  $entity
     */
    public static function resign(string $tokenId, DateTimeImmutable $expiresAt, bool $withUser = true, ?string $entity = null): string
    {
        $row = Passport::token()->newQuery()->findOrFail($tokenId);
        $entity ??= Passport::$accessTokenEntity;

        $entity = new $entity(
            $withUser ? (string) $row->user_id : null,
            array_map(fn (string $scope) => new Scope($scope), $row->scopes),
            new ClientEntity((string) $row->client_id, 'Client thử', [self::REDIRECT_URI]),
        );
        $entity->setIdentifier($tokenId);
        $entity->setExpiryDateTime($expiresAt);
        $entity->setPrivateKey(new CryptKey((string) config('passport.private_key'), null, false));

        return $entity->toString();
    }

    /**
     * Route thăm dò `POST /_probe/mcp-guard`, đứng sau ĐÚNG `auth:mcp`, KHÔNG có
     * `RequireBearerToken`; trả `users.id` mà guard `mcp` nhận ra.
     *
     * Đây là vế đối chứng của các test cookie `laravel_token`: cùng cookie và cùng mã CSRF mà `/mcp`
     * từ chối thì ở route này phải được nhận. Thiếu vế này, một cookie hỏng (mã hoá sai, CSRF lệch)
     * cũng cho ra 401 ở `/mcp`, và test xanh vì một lý do không liên quan.
     */
    public static function registerGuardProbe(): string
    {
        Route::post('/_probe/mcp-guard', fn () => ['user_id' => request()->user()?->getKey()])
            ->middleware('auth:mcp');

        return '/_probe/mcp-guard';
    }

    /** `jti` của một access token JWT (không kiểm chữ ký — chỉ để tra dòng trong CSDL). */
    public static function tokenId(string $jwt): string
    {
        return (string) self::claims($jwt)['jti'];
    }

    /** @return array<string, mixed> */
    public static function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';

        return (array) json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    }
}
