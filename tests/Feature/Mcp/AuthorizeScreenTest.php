<?php

use App\Actions\Mcp\RegisterMcpClient;
use App\Enums\AiAccessMode;
use App\Enums\McpAccessRefusal;
use App\Enums\McpPlatform;
use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\Login;
use App\Http\Middleware\Mcp\RestrictConsentScreenToAdminIps;
use App\Models\AiAcknowledgement;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Mcp\ConsentRequest;
use App\Support\Mcp\McpEndpoint;
use App\Support\Security\ContentSecurityPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 4 — đăng nhập và màn hình đồng ý OAuth (`GET/POST/DELETE /oauth/authorize`)
|--------------------------------------------------------------------------
| Mọi test đi qua HTTP thật: route của Passport, nhóm `web`, middleware của nhóm route Passport
| (`config/passport.php`), màn hình đồng ý THẬT (`resources/views/mcp/authorize.blade.php` — không có
| `McpOAuth::useConsentStandIn()` nào trong tệp này), `/oauth/token`. Chuỗi đăng nhập đi qua trang
| đăng nhập thật của panel `/admin` (Livewire) kèm bước mã 2FA.
|
| Bốn lớp, mỗi lớp có test riêng:
|  1. màn hình (GET): nội dung, chống nhúng, CSP `form-action`, từ chối khi người không đủ điều kiện;
|  2. "Đồng ý" (POST) kiểm lại mọi điều kiện ở chính lúc bấm, phiên phải đúng người đã mở màn hình;
|  3. không bao giờ tự duyệt, kể cả khi người đó đã có token còn hạn cho đúng client đó;
|  4. cửa vào: khách vãng lai về `/admin/login`, allowlist IP, phiên có trước lần đổi mật khẩu.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer();
});

const CONSENT_LOOPBACK = 'http://localhost/callback';

function consentLawyer(AiAccessMode $mode = AiAccessMode::Read): User
{
    return User::factory()->withRole(Role::Lawyer)->withAiAccess($mode)->create(['password' => 'mat-khau-dung']);
}

/**
 * Tham số của một yêu cầu uỷ quyền hợp lệ (PKCE S256, `state` ngẫu nhiên); `$overrides` đổi hoặc
 * (giá trị `null`) bỏ từng tham số.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{query: array<string, mixed>, verifier: string}
 */
function consentQuery(Client $client, ?string $redirectUri = null, array $overrides = []): array
{
    $pkce = McpOAuth::pkce();

    $query = array_filter(array_merge([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => $redirectUri ?? $client->redirect_uris[0],
        'scope' => 'mcp:use',
        'state' => 'trang-thai-'.Str::random(12),
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
    ], $overrides), fn ($value) => $value !== null);

    return ['query' => $query, 'verifier' => $pkce['verifier']];
}

/** @param  array<string, mixed>  $query */
function consentUrl(array $query): string
{
    return '/oauth/authorize?'.http_build_query($query);
}

/**
 * `GET /oauth/authorize` dưới phiên của `$account` (guard `web` mặc định).
 *
 * @param  array<string, mixed>  $overrides
 * @return array{response: TestResponse, query: array<string, mixed>, verifier: string}
 */
function consentScreen(User|ClientUser $account, Client $client, ?string $redirectUri = null, array $overrides = [], string $guard = 'web'): array
{
    ['query' => $query, 'verifier' => $verifier] = consentQuery($client, $redirectUri, $overrides);

    return [
        'response' => test()->actingAs($account, $guard)->get(consentUrl($query)),
        'query' => $query,
        'verifier' => $verifier,
    ];
}

/** `auth_token` mà màn hình đặt vào form (đọc từ HTML, như trình duyệt gửi lại). */
function consentAuthToken(TestResponse $response): ?string
{
    preg_match('/name="auth_token" value="([^"]+)"/', (string) $response->getContent(), $match);

    return $match[1] ?? null;
}

function consentApprove(User|ClientUser $account, ?string $authToken): TestResponse
{
    return test()->actingAs($account, 'web')->post('/oauth/authorize', ['auth_token' => $authToken]);
}

function consentDeny(User|ClientUser $account, ?string $authToken): TestResponse
{
    return test()->actingAs($account, 'web')->delete('/oauth/authorize', ['auth_token' => $authToken]);
}

/**
 * Phản hồi phải là chuyển hướng về đúng `$redirectUri`; trả query của `Location`.
 *
 * @return array<string, string>
 */
function consentRedirectQuery(TestResponse $response, string $redirectUri = McpOAuth::REDIRECT_URI): array
{
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith($redirectUri.'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

function consentNoCodeIssued(TestResponse $response, int $codesBefore = 0): void
{
    expect((string) $response->headers->get('Location'))->not->toContain('code=')
        ->and(Passport::authCode()->newQuery()->count())->toBe($codesBefore);
}

/** @return list<Activity> */
function consentAudit(string $event): array
{
    return Activity::query()->where('event', $event)->orderBy('id')->get()->all();
}

/** Một chỉ thị của header CSP, ví dụ `form-action` → `'self' https://claude.ai`. */
function consentCspDirective(TestResponse $response, string $directive): ?string
{
    foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
        $part = trim($part);

        if (str_starts_with($part, $directive.' ')) {
            return substr($part, strlen($directive) + 1);
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Màn hình: nội dung
|--------------------------------------------------------------------------
*/

it('R7 màn hình đồng ý: nền tảng suy từ host redirect, chính host đó, "AI sẽ hành động với danh nghĩa và quyền của anh/chị", chế độ hiện tại, đường dẫn chính sách, hai nút có CSRF và auth_token', function () {
    $user = consentLawyer();

    ['response' => $response] = consentScreen($user, McpOAuth::client());

    $response->assertOk()
        ->assertSeeText(McpPlatform::Claude->label())
        ->assertSeeText('claude.ai')
        ->assertSeeText('AI sẽ hành động với danh nghĩa và quyền của anh/chị')
        ->assertSeeText(AiAccessMode::Read->label())
        ->assertSee('href="'.e(McpEndpoint::myAiConnectionsUrl()).'"', false)
        ->assertSee('data-consent="approve"', false)
        ->assertSee('data-consent="deny"', false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertSee('name="_token" value="'.session()->token().'"', false)
        ->assertDontSeeText(__('mcp_consent.loopback_warning', ['host' => 'claude.ai']));

    // Cùng mã mà Passport vừa cất vào phiên: hai nút gửi đúng mã đó.
    expect(consentAuthToken($response))->toBe(session('authToken'))
        ->and((string) $response->getContent())->not->toContain('<script');
});

it('R7 nền tảng và host hiện trên màn hình suy từ redirect URI của chính yêu cầu, và cảnh báo riêng khi redirect là loopback', function (string $redirectUri, McpPlatform $platform, string $host, bool $loopback) {
    config(['vkcrm.mcp.extra_redirect_uris' => ['https://copilot.example.com/oauth/cb']]);
    $client = McpOAuth::client([$redirectUri]);

    ['response' => $response] = consentScreen(consentLawyer(), $client, $redirectUri);

    $response->assertOk()->assertSeeText($platform->label())->assertSeeText($host);

    $warning = __('mcp_consent.loopback_warning', ['host' => $host]);
    $loopback ? $response->assertSeeText($warning) : $response->assertDontSeeText($warning);
})->with([
    'Claude' => ['https://claude.ai/api/mcp/auth_callback', McpPlatform::Claude, 'claude.ai', false],
    'ChatGPT, redirect ổn định' => ['https://chatgpt.com/connector_platform_oauth_redirect', McpPlatform::ChatGpt, 'chatgpt.com', false],
    'ChatGPT, theo callback_id' => ['https://chatgpt.com/connector/oauth/cb_9Xk-2fA7', McpPlatform::ChatGpt, 'chatgpt.com', false],
    'VS Code web' => ['https://vscode.dev/redirect', McpPlatform::VsCode, 'vscode.dev', false],
    'Cursor' => ['https://www.cursor.com/agents/mcp/oauth/callback', McpPlatform::Cursor, 'www.cursor.com', false],
    'Antigravity' => ['https://antigravity.google/oauth-callback', McpPlatform::Antigravity, 'antigravity.google', false],
    'loopback localhost (Claude Code)' => [CONSENT_LOOPBACK, McpPlatform::LocalApp, 'localhost', true],
    'loopback 127.0.0.1 có cổng (VS Code)' => ['http://127.0.0.1:33418/', McpPlatform::LocalApp, '127.0.0.1:33418', true],
    'URI thêm qua MCP_EXTRA_REDIRECT_URIS' => ['https://copilot.example.com/oauth/cb', McpPlatform::Other, 'copilot.example.com', false],
]);

it('R7 client_name tự khai "Claude chính chủ" với redirect loopback: màn hình hiện "localhost", không hiện chữ "Claude chính chủ"', function () {
    $client = app(RegisterMcpClient::class)->handle('Claude chính chủ', [CONSENT_LOOPBACK]);

    ['response' => $response] = consentScreen(consentLawyer(), $client, CONSENT_LOOPBACK);

    $response->assertOk()
        ->assertSeeText('localhost')
        ->assertSeeText(__('mcp_consent.loopback_warning', ['host' => 'localhost']))
        ->assertDontSeeText('Claude chính chủ')
        ->assertDontSee('Claude chính chủ', false);
});

it('R7 nền tảng chỉ suy từ host CHÍNH XÁC qua https (hoặc http loopback); host giống tên, tên miền con giả, http, userinfo đều là "Ứng dụng khác"', function (string $uri, McpPlatform $expected) {
    expect(McpPlatform::fromRedirectUri($uri))->toBe($expected);
})->with([
    ['https://claude.ai/api/mcp/auth_callback', McpPlatform::Claude],
    ['https://CLAUDE.AI/api/mcp/auth_callback', McpPlatform::Claude],
    ['https://evil-claude.ai/cb', McpPlatform::Other],
    ['https://claude.ai.evil.example/cb', McpPlatform::Other],
    ['http://claude.ai/cb', McpPlatform::Other],
    ['https://claude.ai@evil.example/cb', McpPlatform::Other],
    ['https://chatgpt.com/connector/oauth/x', McpPlatform::ChatGpt],
    ['https://cursor.com/agents/mcp/oauth/callback', McpPlatform::Other],
    ['http://localhost:53682/callback', McpPlatform::LocalApp],
    ['http://127.0.0.1/callback', McpPlatform::LocalApp],
    ['http://[::1]:5000/callback', McpPlatform::LocalApp],
    ['https://localhost/callback', McpPlatform::Other],
    ['http://localhost.evil.example/callback', McpPlatform::Other],
    ['khong-phai-url', McpPlatform::Other],
]);

/**
 * Rà soát cuối M11, I2 (Task 4 m1): màn hình đồng ý chỉ hứa điều R8 làm — mỗi lần AI dùng một tool
 * (`tools/call`) được ghi nhật ký; `initialize`, `tools/list`, `ping` thì không
 * (`tests/Feature/Mcp/AuditTest.php`). Câu cũ "mọi lần gọi đều được ghi nhật ký" hứa nhiều hơn thế.
 */
it('R8 màn hình đồng ý hứa ghi nhật ký mỗi lần AI dùng một chức năng (tool), không hứa "mọi lần gọi"', function () {
    $promise = 'Nó đọc được những vụ việc anh/chị xem được trên hệ thống (trừ vụ hạn chế, vụ chưa cho phép AI, tài liệu nhóm D, ghi chú nội bộ và số định danh), không gửi hay công bố gì cho khách, và mọi lần nó dùng một chức năng (tool) của hệ thống đều được ghi nhật ký.';

    expect(__('mcp_consent.acts_as_you_detail'))->toBe($promise);

    ['response' => $response] = consentScreen(consentLawyer(), McpOAuth::client());

    $response->assertOk()
        ->assertSeeText($promise)
        ->assertDontSeeText('mọi lần gọi đều được ghi nhật ký');
});

it('R2 chế độ hiện tại: "Đọc và ghi" kèm lời nhắc khi quyền ghi qua AI đang tắt toàn văn phòng; công tắc ghi bật thì không nhắc', function () {
    $writer = consentLawyer(AiAccessMode::ReadWrite);

    ['response' => $writeOff] = consentScreen($writer, McpOAuth::client());
    $writeOff->assertOk()
        ->assertSeeText(AiAccessMode::ReadWrite->label())
        ->assertSeeText(__('mcp_consent.write_switch_off'));

    McpOAuth::openServer(write: true);

    ['response' => $writeOn] = consentScreen($writer, McpOAuth::client());
    $writeOn->assertOk()
        ->assertSeeText(AiAccessMode::ReadWrite->label())
        ->assertDontSeeText(__('mcp_consent.write_switch_off'));
});

/*
|--------------------------------------------------------------------------
| Màn hình: chống nhúng và CSP form-action
|--------------------------------------------------------------------------
*/

it('R7 header chống nhúng có mặt: X-Frame-Options DENY và CSP frame-ancestors none', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);

    ['response' => $response] = consentScreen(consentLawyer(), McpOAuth::client());

    $response->assertOk()->assertHeader('X-Frame-Options', 'DENY');
    expect(consentCspDirective($response, 'frame-ancestors'))->toBe("'none'");
});

it('R7 CSP form-action của màn hình đồng ý mở thêm ĐÚNG origin của redirect URI của yêu cầu (có cổng nếu có), không gì khác; trang khác giữ form-action self', function (string $registered, string $requested, string $origin) {
    config(['vkcrm.security.csp_mode' => 'enforce']);
    $client = McpOAuth::client([$registered]);

    ['response' => $response] = consentScreen(consentLawyer(), $client, $requested);

    $response->assertOk();
    expect(consentCspDirective($response, 'form-action'))->toBe("'self' ".$origin);

    // Request kế tiếp (một trang khác) không mang theo origin đó.
    expect(consentCspDirective(test()->get('/admin/login'), 'form-action'))->toBe("'self'");
})->with([
    'Claude' => [McpOAuth::REDIRECT_URI, McpOAuth::REDIRECT_URI, 'https://claude.ai'],
    'ChatGPT theo callback_id' => ['https://chatgpt.com/connector/oauth/cb_1', 'https://chatgpt.com/connector/oauth/cb_1', 'https://chatgpt.com'],
    'loopback 127.0.0.1, cổng của lần này' => ['http://127.0.0.1/callback', 'http://127.0.0.1:53682/callback', 'http://127.0.0.1:53682'],
]);

it('R7 form-action chỉ nhận một origin http(s)://host[:cổng] đúng dạng nguồn CSP: ký tự đại diện, đường dẫn, chỉ thị chèn thêm, host IPv6 đều bị bỏ, form-action giữ self', function () {
    $request = request();

    foreach (['*', 'https://*.claude.ai', 'https://claude.ai/', 'https://claude.ai/api', "https://claude.ai; script-src 'unsafe-inline'", 'https://claude.ai https://evil.example', 'javascript:alert(1)', 'http://[::1]:5000', 'https://CLAUDE.AI', 'https://claude.ai:0'] as $origin) {
        expect(ContentSecurityPolicy::allowFormActionTo($request, $origin))->toBeFalse($origin);
    }

    expect(ContentSecurityPolicy::formActionOrigins($request))->toBe([])
        ->and(ContentSecurityPolicy::allowFormActionTo($request, 'http://127.0.0.1:53682'))->toBeTrue()
        ->and(ContentSecurityPolicy::allowFormActionTo($request, 'http://127.0.0.1:53682'))->toBeTrue()
        ->and(ContentSecurityPolicy::formActionOrigins($request))->toBe(['http://127.0.0.1:53682']);

    // Giá trị đặt thẳng vào thuộc tính của request (đi vòng hàm trên) cũng bị lọc lúc dựng header.
    $request->attributes->set(ContentSecurityPolicy::FORM_ACTION_ATTRIBUTE, ['https://claude.ai', '*', "x'; script-src *"]);
    expect(ContentSecurityPolicy::formActionOrigins($request))->toBe(['https://claude.ai']);
});

it('R7 redirect IPv6 loopback [::1]: màn hình vẫn hiện, nhưng CSP không biểu diễn được host IPv6 nên form-action giữ self (giới hạn đã ghi)', function () {
    expect(ConsentRequest::origin('http://[::1]:5000/callback'))->toBeNull()
        ->and(ConsentRequest::origin('http://127.0.0.1:5000/callback'))->toBe('http://127.0.0.1:5000')
        ->and(ConsentRequest::origin('https://Claude.AI/api/mcp/auth_callback'))->toBe('https://claude.ai')
        ->and(ConsentRequest::displayHost('http://[::1]:5000/callback'))->toBe('[::1]:5000');
});

it('R7 màn hình từ chối (chỉ còn nút Từ chối) cũng mở form-action tới origin của redirect, vì Từ chối cũng chuyển hướng về client', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);
    $user = consentLawyer();
    $user->aiAcknowledgements()->delete();

    ['response' => $response] = consentScreen($user, McpOAuth::client());

    $response->assertForbidden()->assertSee('data-consent="deny"', false);
    expect(consentCspDirective($response, 'form-action'))->toBe("'self' https://claude.ai");
});

/*
|--------------------------------------------------------------------------
| Từ chối: mỗi điều kiện một dòng (không có nút Đồng ý; POST ép tay không cấp mã)
|--------------------------------------------------------------------------
*/

it('R2/R12 màn hình từ chối, không có nút Đồng ý, khi người không đủ điều kiện; POST "Đồng ý" ép tay cũng không cấp mã; ghi mcp_connection_denied kèm lý do', function (Closure $setUp, McpAccessRefusal $expected) {
    $account = $setUp();
    $client = McpOAuth::client();

    ['response' => $response] = consentScreen($account, $client);

    $response->assertForbidden()
        ->assertSeeText($expected->label())
        ->assertDontSee('data-consent="approve"', false)
        ->assertSee('data-consent="deny"', false);

    $forced = consentApprove($account, (string) session('authToken'));

    $forced->assertForbidden();
    consentNoCodeIssued($forced);

    $rows = consentAudit('mcp_connection_denied');
    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->pluck('properties.reason')->unique()->values()->all())->toBe([$expected->value])
        ->and(consentAudit('mcp_connection_authorized'))->toBe([]);
})->with([
    'ai_access = off' => [fn () => tap(consentLawyer(), fn (User $user) => $user->forceFill(['ai_access' => AiAccessMode::Off])->save()), McpAccessRefusal::AiAccessOff],
    'công tắc toàn hệ thống tắt' => [function () {
        McpOAuth::openServer(enabled: false);

        return consentLawyer();
    }, McpAccessRefusal::ServerDisabled],
    'chưa cam kết R12' => [fn () => tap(consentLawyer(), fn (User $user) => $user->aiAcknowledgements()->delete()), McpAccessRefusal::PolicyNotAcknowledged],
    'cam kết phiên bản cũ (chính sách vừa đổi)' => [function () {
        $user = consentLawyer();
        config(['vkcrm.mcp.policy_version' => '2099-01-01']);

        return $user;
    }, McpAccessRefusal::PolicyNotAcknowledged],
    'chưa cài 2FA (vừa bị Đặt lại 2FA hay chưa cài lần đầu)' => [fn () => User::factory()->withRole(Role::Lawyer)->withoutTwoFactor()->withAiAccess()->create(), McpAccessRefusal::TwoFactorNotSetUp],
    // Hai ca "tài khoản bị vô hiệu hoá" ra khỏi danh sách này khi gộp M11 vào làn nghiệm thu bản 1.0
    // (2026-10-09): `EndDisabledStaffSessions` (SPEC §10.9) cắt phiên trước khi màn hình kịp dựng —
    // test riêng ngay dưới. Thứ tự lý do (vô hiệu hoá trước 2FA) vẫn ở `AccessControlTest`.
    'vai không có matter.view (Kế toán) dù ai_access còn bật' => [fn () => tap(
        User::factory()->withRole(Role::Accountant)->create(),
        function (User $user) {
            $user->forceFill(['ai_access' => AiAccessMode::Read])->save();
            AiAcknowledgement::factory()->for($user)->create();
        },
    ), McpAccessRefusal::NoMatterView],
    'tài khoản là ClientUser (lọt vào guard web)' => [fn () => ClientUser::factory()->activated()->create(), McpAccessRefusal::NotStaff],
]);

/*
 * SPEC §10.9 trên cây đã gộp M11 (rà soát cuối làn v1, vòng sửa 1): phiên của nhân sự bị vô hiệu hoá
 * bị `EndDisabledStaffSessions` đăng xuất ở request kế tiếp, kể cả `GET /oauth/authorize` và "Đồng ý"
 * ép tay — nên người đó về trang đăng nhập, không bao giờ thấy màn hình (kể cả màn hình từ chối), không
 * mã nào được cấp, không dòng nhật ký kết nối nào. Lý do `Inactive` của `McpAccess::consentRefusal()`
 * còn đó làm lớp thứ hai. Cùng lời hứa từ phía lượt quét: `SessionCutSpec109Test`.
 */
it('R2 + §10.9 tài khoản bị vô hiệu hoá: phiên bị cắt, về trang đăng nhập, không màn hình, không mã', function (Closure $setUp) {
    $account = $setUp();

    ['response' => $response] = consentScreen($account, McpOAuth::client());

    $response->assertRedirect(McpEndpoint::staffLoginUrl());
    $this->assertGuest('web');
    consentNoCodeIssued($response);

    $forced = consentApprove($account->fresh(), 'ma-bat-ky');

    $forced->assertRedirect(McpEndpoint::staffLoginUrl());
    $this->assertGuest('web');
    consentNoCodeIssued($forced);
    expect(consentAudit('mcp_connection_authorized'))->toBe([]);
})->with([
    'tài khoản bị vô hiệu hoá' => [fn () => tap(consentLawyer(), fn (User $user) => $user->forceFill(['is_active' => false])->save())],
    'vô hiệu hoá VÀ chưa cài 2FA' => [fn () => User::factory()->withRole(Role::Lawyer)->withoutTwoFactor()->withAiAccess()->create(['is_active' => false])],
]);

it('R1 phiên cổng khách (guard client) không phải phiên nhân sự: /oauth/authorize chuyển tới trang đăng nhập /admin, không màn hình, không mã', function () {
    $clientUser = ClientUser::factory()->activated()->create();

    ['response' => $response] = consentScreen($clientUser, McpOAuth::client(), guard: 'client');

    $response->assertRedirect(McpEndpoint::staffLoginUrl());
    expect(session('authToken'))->toBeNull()
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
});

it('R2 điều kiện đổi giữa lúc mở màn hình và lúc bấm "Đồng ý" (quản trị vừa tắt AI của người này): POST bị từ chối, không mã, không form nào, CSP không mở thêm gì, ghi lý do', function () {
    config(['vkcrm.security.csp_mode' => 'enforce']);
    $user = consentLawyer();

    ['response' => $response] = consentScreen($user, McpOAuth::client());
    $response->assertOk();

    $user->forceFill(['ai_access' => AiAccessMode::Off])->save();

    $approve = consentApprove($user, consentAuthToken($response));

    $approve->assertForbidden()->assertSeeText(McpAccessRefusal::AiAccessOff->label())->assertDontSee('data-consent', false);
    consentNoCodeIssued($approve);
    expect(consentCspDirective($approve, 'form-action'))->toBe("'self'")
        ->and(collect(consentAudit('mcp_connection_denied'))->pluck('properties.reason')->all())->toBe([McpAccessRefusal::AiAccessOff->value]);
});

/*
|--------------------------------------------------------------------------
| Đồng ý / Từ chối và nhật ký
|--------------------------------------------------------------------------
*/

it('R8 Đồng ý: chuyển hướng về client với mã và đúng state ban đầu, mã đổi được token; ghi ĐÚNG một dòng mcp_connection_authorized kèm nền tảng, không tên client tự khai', function () {
    $user = consentLawyer();
    $client = app(RegisterMcpClient::class)->handle('Claude chính chủ', [McpOAuth::REDIRECT_URI]);

    ['response' => $screen, 'query' => $query, 'verifier' => $verifier] = consentScreen($user, $client);
    $screen->assertOk();

    $redirect = consentRedirectQuery(consentApprove($user, consentAuthToken($screen)));

    expect($redirect['state'])->toBe($query['state'])
        ->and($redirect)->toHaveKey('code');

    Auth::forgetGuards();
    Once::flush();

    test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => $redirect['code'],
        'code_verifier' => $verifier,
    ])->assertOk();

    $rows = consentAudit('mcp_connection_authorized');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->causer_id)->toBe($user->getKey())
        ->and($rows[0]->causer_type)->toBe('user')
        ->and($rows[0]->subject_id)->toBe($user->getKey())
        ->and($rows[0]->properties->all())->toBe([
            'oauth_client_id' => $client->getKey(),
            'platform' => McpPlatform::Claude->value,
            'redirect_host' => 'claude.ai',
            'mode' => AiAccessMode::Read->value,
        ])
        ->and(json_encode($rows[0]->properties))->not->toContain('Claude chính chủ')
        ->and(consentAudit('mcp_connection_denied'))->toBe([]);
});

it('R8 Từ chối: chuyển hướng về client với error=access_denied và state, không mã; ghi một dòng mcp_connection_denied lý do "user" kèm nền tảng', function () {
    $user = consentLawyer();
    $client = McpOAuth::client([CONSENT_LOOPBACK]);

    ['response' => $screen, 'query' => $query] = consentScreen($user, $client, CONSENT_LOOPBACK);
    $screen->assertOk();

    $deny = consentDeny($user, consentAuthToken($screen));
    $redirect = consentRedirectQuery($deny, CONSENT_LOOPBACK);

    expect($redirect['error'])->toBe('access_denied')
        ->and($redirect['state'])->toBe($query['state'])
        ->and($redirect)->not->toHaveKey('code');
    consentNoCodeIssued($deny);

    $rows = consentAudit('mcp_connection_denied');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->causer_id)->toBe($user->getKey())
        ->and($rows[0]->properties->all())->toBe([
            'oauth_client_id' => $client->getKey(),
            'platform' => McpPlatform::LocalApp->value,
            'redirect_host' => 'localhost',
            'reason' => 'user',
        ]);
});

it('R7 auth_token của màn hình dùng một lần: bấm "Đồng ý" lần hai với cùng mã không cấp mã thứ hai', function () {
    $user = consentLawyer();

    ['response' => $screen] = consentScreen($user, McpOAuth::client());
    $token = consentAuthToken($screen);

    consentRedirectQuery(consentApprove($user, $token));
    expect(Passport::authCode()->newQuery()->count())->toBe(1);

    $again = consentApprove($user, $token);

    $again->assertForbidden();
    consentNoCodeIssued($again, 1);
    expect(consentAudit('mcp_connection_authorized'))->toHaveCount(1);
});

it('R7 yêu cầu uỷ quyền trong phiên phải thuộc ĐÚNG người đang bấm: phiên đổi sang người khác (cùng auth_token) không cấp mã, không từ chối thay, không ghi gì dưới tên người kia', function (string $method) {
    $opener = consentLawyer();
    $other = consentLawyer();

    ['response' => $screen] = consentScreen($opener, McpOAuth::client());
    $screen->assertOk();

    // Phiên chưa cất dấu mật khẩu nào (như request đầu tiên sau một lần đăng nhập), để
    // `AuthenticateSession` không đăng xuất trước: test hỏi đúng phép so người của lớp "Đồng ý"/"Từ chối".
    session()->forget('password_hash_web');

    $response = test()->actingAs($other, 'web')->call($method, '/oauth/authorize', ['auth_token' => consentAuthToken($screen)]);

    $response->assertForbidden();
    consentNoCodeIssued($response);
    expect(consentAudit('mcp_connection_authorized'))->toBe([])
        ->and(consentAudit('mcp_connection_denied'))->toBe([]);
})->with(['POST "Đồng ý"' => 'POST', 'DELETE "Từ chối"' => 'DELETE']);

it('R8 "Đồng ý" và dòng nhật ký là MỘT transaction: ghi nhật ký hỏng thì mã uỷ quyền vừa lưu cũng không còn, không chuyển hướng nào mang mã', function () {
    // Dòng nhật ký "đồng ý" không ghi được (CSDL từ chối, đĩa đầy…): ném ngay lúc dựng dòng.
    Activity::creating(function (Activity $activity): void {
        if ($activity->event === 'mcp_connection_authorized') {
            throw new RuntimeException('Ghi nhật ký hỏng (thử).');
        }
    });

    $user = consentLawyer();

    ['response' => $screen] = consentScreen($user, McpOAuth::client());
    $screen->assertOk();

    $approve = consentApprove($user, consentAuthToken($screen));

    $approve->assertServerError();
    consentNoCodeIssued($approve);
});

it('R7 CSRF: POST "Đồng ý" thiếu mã CSRF của phiên nhận 419 và không cấp mã; kèm đúng mã thì được', function () {
    // Bật lại kiểm CSRF mà Laravel tắt khi chạy test (`runningUnitTests()`).
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $user = consentLawyer();

    ['response' => $screen] = consentScreen($user, McpOAuth::client());
    $token = consentAuthToken($screen);

    $withoutCsrf = consentApprove($user, $token);
    $withoutCsrf->assertStatus(419);
    consentNoCodeIssued($withoutCsrf);

    ['response' => $again] = consentScreen($user, McpOAuth::client());

    $withCsrf = test()->actingAs($user, 'web')->post('/oauth/authorize', [
        '_token' => session()->token(),
        'auth_token' => consentAuthToken($again),
    ]);

    expect(consentRedirectQuery($withCsrf))->toHaveKey('code');
});

/*
|--------------------------------------------------------------------------
| Không bao giờ tự duyệt
|--------------------------------------------------------------------------
*/

it('R7 không bao giờ tự duyệt: người đã có token còn hạn cho ĐÚNG client đó (DCR) vẫn thấy màn hình đồng ý, không mã nào được cấp ở GET', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();
    McpOAuth::issueTokens($this, $user, $client);
    $codes = Passport::authCode()->newQuery()->count();

    ['response' => $response] = consentScreen($user, $client);

    $response->assertOk()->assertSee('data-consent="approve"', false);
    consentNoCodeIssued($response, $codes);
});

it('R7 prompt=none không bao giờ cấp mã im lặng: người đã có token còn hạn nhận error=consent_required (kèm iss), không mã', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();
    McpOAuth::issueTokens($this, $user, $client);
    $codes = Passport::authCode()->newQuery()->count();

    ['response' => $response] = consentScreen($user, $client, overrides: ['prompt' => 'none']);

    $redirect = consentRedirectQuery($response);
    expect($redirect['error'])->toBe('consent_required')
        ->and($redirect['iss'])->toBe(McpEndpoint::issuer());
    consentNoCodeIssued($response, $codes);
});

it('R12 người có token còn hạn nhưng chính sách vừa đổi phiên bản: /oauth/authorize hiện màn hình từ chối, không mã, có dòng nhật ký', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();
    McpOAuth::issueTokens($this, $user, $client);
    $codes = Passport::authCode()->newQuery()->count();
    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    ['response' => $response] = consentScreen($user, $client);

    $response->assertForbidden()->assertSeeText(McpAccessRefusal::PolicyNotAcknowledged->label());
    consentNoCodeIssued($response, $codes);
    expect(consentAudit('mcp_connection_denied'))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Cửa vào: khách vãng lai, đăng nhập + 2FA, tên miền tách
|--------------------------------------------------------------------------
*/

it('R7 khách vãng lai: /oauth/authorize → /admin/login → mật khẩu → mã 2FA → quay lại đúng URL ban đầu, giữ code_challenge và state; đồng ý xong đổi được token', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();
    ['query' => $query, 'verifier' => $verifier] = consentQuery($client);

    $this->get(consentUrl($query))->assertRedirect(McpEndpoint::staffLoginUrl());
    expect(McpEndpoint::staffLoginUrl())->toBe(url('/admin/login'));

    Filament::setCurrentPanel('admin');

    $login = $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('web')->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($user->two_factor_secret))
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $back = (string) $login->effects['redirect'];
    parse_str((string) parse_url($back, PHP_URL_QUERY), $backQuery);

    expect(parse_url($back, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and($backQuery)->toEqual($query)
        ->and($backQuery['code_challenge'])->toBe($query['code_challenge'])
        ->and($backQuery['state'])->toBe($query['state'])
        ->and(auth('web')->id())->toBe($user->getKey());

    $screen = $this->get($back);
    $screen->assertOk()->assertSee('data-consent="approve"', false);

    $redirect = consentRedirectQuery($this->post('/oauth/authorize', ['auth_token' => consentAuthToken($screen)]));
    expect($redirect['state'])->toBe($query['state']);

    Auth::forgetGuards();
    Once::flush();

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => $redirect['code'],
        'code_verifier' => $verifier,
    ])->assertOk();
});

it('R7 khách vãng lai bấm thẳng "Đồng ý" / "Từ chối" (POST, DELETE): chuyển tới trang đăng nhập /admin, không lỗi 500, không mã', function (string $method) {
    $response = $this->call($method, '/oauth/authorize', ['auth_token' => 'doan-bua']);

    $response->assertRedirect(McpEndpoint::staffLoginUrl());
    consentNoCodeIssued($response);
})->with(['POST', 'DELETE']);

it('R7 tách tên miền (ADMIN_DOMAIN): khách vãng lai về /admin/login trên tên miền quản trị — cùng host với authorization_endpoint mà metadata quảng bá, nên cookie phiên tới được màn hình đồng ý', function () {
    config([
        'app.url' => 'https://khachhang.luatvukhang.test',
        'vkcrm.admin_domain' => 'quantri.luatvukhang.test',
        'vkcrm.portal_domain' => 'khachhang.luatvukhang.test',
    ]);
    ['query' => $query] = consentQuery(McpOAuth::client());

    $endpoint = $this->getJson('https://khachhang.luatvukhang.test/.well-known/oauth-authorization-server')->json('authorization_endpoint');

    $this->get($endpoint.'?'.http_build_query($query))->assertRedirect('https://quantri.luatvukhang.test/admin/login');
    expect(parse_url($endpoint, PHP_URL_HOST))->toBe('quantri.luatvukhang.test');

    // Một client gọi nhầm host cổng khách: trang đăng nhập (và trang chính sách) vẫn ở tên miền quản trị,
    // nơi duy nhất panel `/admin` trả lời khi tách tên miền — không phải host của request.
    $this->get('https://khachhang.luatvukhang.test/oauth/authorize?'.http_build_query($query))
        ->assertRedirect('https://quantri.luatvukhang.test/admin/login');
    expect(McpEndpoint::myAiConnectionsUrl())->toBe('https://quantri.luatvukhang.test/admin/'.McpEndpoint::MY_AI_CONNECTIONS_SLUG);
});

it('R7 ADMIN_DOMAIN trống: trang đăng nhập và trang chính sách ở chính host của request', function () {
    config(['vkcrm.admin_domain' => null]);

    expect(McpEndpoint::staffLoginUrl())->toBe(url('/admin/login'))
        ->and(McpEndpoint::myAiConnectionsUrl())->toBe(url('/admin/'.McpEndpoint::MY_AI_CONNECTIONS_SLUG));
});

/*
|--------------------------------------------------------------------------
| Allowlist IP của /admin phủ cả màn hình đồng ý (không phủ /oauth/token)
|--------------------------------------------------------------------------
*/

it('R7 ADMIN_IP_ALLOWLIST phủ GET/POST/DELETE /oauth/authorize: IP ngoài danh sách nhận 404, IP trong danh sách thấy màn hình', function () {
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.0/8']);
    $user = consentLawyer();
    ['query' => $query] = consentQuery(McpOAuth::client());

    $outside = ['REMOTE_ADDR' => '203.0.113.9'];

    $this->actingAs($user, 'web')->withServerVariables($outside)->get(consentUrl($query))->assertNotFound();
    $this->actingAs($user, 'web')->withServerVariables($outside)->post('/oauth/authorize', ['auth_token' => 'x'])->assertNotFound();
    $this->actingAs($user, 'web')->withServerVariables($outside)->delete('/oauth/authorize', ['auth_token' => 'x'])->assertNotFound();
    expect(session('authToken'))->toBeNull();

    $this->actingAs($user, 'web')->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get(consentUrl($query))
        ->assertOk()->assertSee('data-consent="approve"', false);
});

it('R7 ADMIN_IP_ALLOWLIST KHÔNG phủ /oauth/token: Claude và ChatGPT đổi mã từ hạ tầng của họ', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();
    ['code' => $code, 'verifier' => $verifier] = McpOAuth::authorizationCode($user, $client);
    config(['vkcrm.security.admin_ip_allowlist' => '10.0.0.0/8']);

    $this->withServerVariables(['REMOTE_ADDR' => '160.79.104.10'])->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => $code,
        'code_verifier' => $verifier,
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Phiên có trước lần đổi mật khẩu (rà soát Task 6, m1)
|--------------------------------------------------------------------------
*/

/*
 * Phiên "cũ" ở đây là phiên test đã mở màn hình một lần (lúc đó `AuthenticateSession` cất dấu của
 * mật khẩu hiện hành vào phiên, như mọi lần mở trang `/admin`). Sau khi mật khẩu đổi, `actingAs()`
 * đưa đúng người đó (đối tượng mang mật khẩu MỚI, như guard nạp lại từ CSDL ở một request thật) vào
 * request kế tiếp của cùng phiên — đúng hình dạng của một cookie phiên bị lấy cắp trước lần đổi.
 */
it('R8 phiên có trước lần đổi mật khẩu bị đăng xuất ở GET /oauth/authorize: không màn hình, không mã', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();

    consentScreen($user, $client)['response']->assertOk();

    // Quản trị đặt mật khẩu mới (EditUser): mọi kết nối AI đã bị thu hồi (Task 6).
    $user->forceFill(['password' => 'mat-khau-moi-hoan-toan'])->save();

    ['response' => $again] = consentScreen($user, $client);

    $again->assertRedirect(McpEndpoint::staffLoginUrl());
    $this->assertGuest('web');
    consentNoCodeIssued($again);
});

it('R8 phiên có trước lần đổi mật khẩu bị đăng xuất ở POST "Đồng ý" với auth_token đã có: không mã', function () {
    $user = consentLawyer();

    ['response' => $first] = consentScreen($user, McpOAuth::client());
    $first->assertOk();

    $user->forceFill(['password' => 'mat-khau-moi-hoan-toan'])->save();

    $approve = consentApprove($user, consentAuthToken($first));

    $approve->assertRedirect(McpEndpoint::staffLoginUrl());
    $this->assertGuest('web');
    consentNoCodeIssued($approve);
    expect(consentAudit('mcp_connection_authorized'))->toBe([]);
});

it('R8 phiên KHÔNG đổi mật khẩu thì hai lần mở màn hình liên tiếp đều thấy màn hình (vế đối chứng của test trên)', function () {
    $user = consentLawyer();
    $client = McpOAuth::client();

    consentScreen($user, $client)['response']->assertOk();
    consentScreen($user, $client)['response']->assertOk()->assertSee('data-consent="approve"', false);
});

it('§10.7 thứ tự middleware thật của ba route /oauth/authorize: allowlist IP trước mọi bước khác; AuthenticateSession sau StartSession', function (string $method) {
    $route = Route::getRoutes()->match(request()->create('/oauth/authorize', $method));
    $middleware = array_values(app('router')->gatherRouteMiddleware($route));

    $ip = array_search(RestrictConsentScreenToAdminIps::class, $middleware, true);
    $session = array_search(StartSession::class, $middleware, true);
    $authSession = array_search(AuthenticateSession::class, $middleware, true);

    expect($ip)->toBe(0)
        ->and($session)->not->toBeFalse()
        ->and($authSession)->toBeGreaterThan($session);
})->with(['GET', 'POST', 'DELETE']);
