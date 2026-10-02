<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 1 — các route của Passport nằm NGOÀI panel admin (§10.7, R2 của M8)
|--------------------------------------------------------------------------
| Cổng 2FA của Filament chỉ đứng trước route của panel `admin`. Năm route mà Passport nạp ở Task 1
| nằm ngoài panel, và bốn trong số đó dùng phiên `web`. Mỗi test dưới đây là lý lẽ đứng sau một dòng
| của `outsidePanelRouteReasons()` (`tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php`): một
| phiên nhân sự CHƯA cài 2FA không lấy được mã uỷ quyền, token, hay quyền vào `/mcp` qua chúng.
|
| Màn hình đồng ý và cổng 2FA của chính `/oauth/authorize` là Task 4. Lý lẽ của hai dòng
| `oauth/authorize` ở Task 1 là "chưa có màn hình nào để duyệt", và Task 4 phải viết lại hai dòng đó.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = User::factory()->withRole(Role::Lawyer)->withoutTwoFactor()->create();
});

/** @return array<string, string> */
function authorizeQuery(string $clientId): array
{
    return [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'scope' => 'mcp:use',
        'state' => Str::random(16),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
}

function expectNoAuthorizationCodeIssued(TestResponse $response): void
{
    expect((string) $response->headers->get('Location'))->not->toContain('code=')
        ->and(Passport::authCode()->newQuery()->count())->toBe(0);
}

it('§10.7 GET oauth/authorize: phiên chưa cài 2FA không nhận được mã uỷ quyền (Task 1 chưa có màn hình đồng ý)', function () {
    $client = McpOAuth::client();

    expectNoAuthorizationCodeIssued(
        $this->actingAs($this->staff, 'web')->get('/oauth/authorize?'.http_build_query(authorizeQuery($client->getKey()))),
    );
});

/**
 * Đường nguy hiểm nhất của `AuthorizationController`: khi người dùng ĐÃ có token còn hạn cho client
 * đó với cùng scope (`hasGrantedScopes()`), Passport duyệt luôn mà không hiện màn hình nào. Ở Task 1
 * đường đó cũng không cấp được mã. Task 4 phải giữ test này xanh bằng cổng 2FA của nó.
 */
it('§10.7 GET oauth/authorize: kể cả khi đã có token còn hạn cho client đó, phiên chưa cài 2FA không nhận được mã', function () {
    $client = McpOAuth::client();
    McpOAuth::issueTokens($this, $this->staff, $client);
    $codesBefore = Passport::authCode()->newQuery()->count();

    $response = $this->actingAs($this->staff, 'web')->get('/oauth/authorize?'.http_build_query(authorizeQuery($client->getKey())));

    expect((string) $response->headers->get('Location'))->not->toContain('code=')
        ->and(Passport::authCode()->newQuery()->count())->toBe($codesBefore);
});

it('§10.7 POST oauth/authorize (duyệt): không có yêu cầu uỷ quyền nào trong phiên để duyệt, không mã nào được cấp', function () {
    expectNoAuthorizationCodeIssued(
        $this->actingAs($this->staff, 'web')->post('/oauth/authorize', ['auth_token' => 'doan-bua']),
    );
});

it('§10.7 DELETE oauth/authorize (từ chối): không cấp mã nào', function () {
    expectNoAuthorizationCodeIssued(
        $this->actingAs($this->staff, 'web')->delete('/oauth/authorize', ['auth_token' => 'doan-bua']),
    );
});

it('§10.7 POST oauth/token: phiên web không đổi được gì thành token (chỉ mã uỷ quyền và refresh token, cả hai không sinh từ phiên)', function () {
    $client = McpOAuth::client();

    $this->actingAs($this->staff, 'web')->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'redirect_uri' => McpOAuth::REDIRECT_URI,
        'code' => 'khong-co',
        'code_verifier' => Str::random(64),
    ])->assertStatus(400);

    expect(Passport::token()->newQuery()->count())->toBe(0);
});

/**
 * `POST oauth/token/refresh` phát cookie `laravel_token` (JWT ký bằng `APP_KEY`, mọi scope) cho bất
 * kỳ phiên `web` nào. Chuỗi đầy đủ: lấy cookie bằng phiên chưa cài 2FA, rồi đem cookie đó cùng mã
 * CSRF của chính phiên ấy gọi `/mcp`. Phải là 401 ở mọi dòng: `RequireBearerToken` xoá cookie đó
 * khỏi request trước `auth:mcp` và chặn bearer rỗng.
 *
 * Các dòng `Bearer 0` / `Bearer ,`: `TokenGuard::user()` của Passport chỉ thử bearer khi
 * `bearerToken()` ĐÚNG theo PHP; `"0"` và `""` là sai, nên guard rơi xuống cookie và gắn một
 * `TransientToken` có mọi scope (cả `mcp:use`). Vế đối chứng: đúng cookie và mã CSRF ấy mở được route
 * thăm dò chỉ có `auth:mcp`, nên 401 ở `/mcp` không phải do cookie hỏng.
 */
it('§10.7 POST oauth/token/refresh: cookie laravel_token nó phát không mở được /mcp, kể cả khi kèm bearer rỗng hoặc "0"', function (?string $authorization) {
    $response = $this->actingAs($this->staff, 'web')->post('/oauth/token/refresh');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Passport::cookie());

    expect($cookie)->not->toBeNull();

    $probe = McpOAuth::registerGuardProbe();

    // Giá trị cookie trong phản hồi đã được `EncryptCookies` (nhóm `web`) mã hoá, đúng như trình
    // duyệt nhận và gửi lại, nên gửi nguyên văn, không mã hoá thêm lần nữa.
    // `withCredentials()`: `postJson()` không gửi cookie nếu thiếu nó.
    $this->withCredentials()
        ->withUnencryptedCookie(Passport::cookie(), $cookie->getValue())
        ->withHeader('X-CSRF-TOKEN', session()->token());

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
    ], $authorization === null ? [] : ['Authorization' => $authorization])->assertUnauthorized();

    $this->postJson($probe)->assertOk()->assertJsonPath('user_id', $this->staff->getKey());
})->with([
    'không có header Authorization' => [null],
    'Bearer 0' => ['Bearer 0'],
    'Bearer ,' => ['Bearer ,'],
    'Bearer và hai khoảng trắng' => ['Bearer  '],
]);
