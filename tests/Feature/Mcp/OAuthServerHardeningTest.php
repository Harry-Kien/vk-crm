<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| M11 Task 1 — R1: chỉ authorization_code (+ refresh_token). Không password grant, không
| personal access token, không client_credentials, không device code.
|--------------------------------------------------------------------------
| Đi qua HTTP thật tới `/oauth/token`. Cặp dương của mọi từ chối ở đây là hai grant được phép
| chạy được thật (`McpOAuth::issueTokens()` đổi mã lấy token qua chính endpoint này).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('R1 cặp dương: authorization_code với PKCE S256 cấp được access + refresh token', function () {
    $tokens = McpOAuth::issueTokens($this, User::factory()->withRole(Role::Lawyer)->create());

    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['access_token'])->not->toBeEmpty()
        ->and($tokens['refresh_token'])->not->toBeEmpty();
});

it('R1 cặp dương: refresh_token đổi được token mới', function () {
    $client = McpOAuth::client();
    $tokens = McpOAuth::issueTokens($this, User::factory()->withRole(Role::Lawyer)->create(), $client);

    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'refresh_token' => $tokens['refresh_token'],
    ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
});

it('R1 client_credentials bị từ chối unsupported_grant_type, kể cả với một client confidential mang đúng grant đó', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('May voi may');

    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'scope' => 'mcp:use',
    ])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

    expect(Passport::token()->newQuery()->count())->toBe(0);
});

it('R1 password grant bị từ chối unsupported_grant_type', function () {
    $user = User::factory()->withRole(Role::Lawyer)->create();
    $client = app(ClientRepository::class)->createPasswordGrantClient('Mat khau', 'users', confidential: false);

    $this->post('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->getKey(),
        'username' => $user->email,
        'password' => 'password',
        'scope' => 'mcp:use',
    ])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');

    expect(Passport::token()->newQuery()->count())->toBe(0);
});

it('R1 device code: không route /oauth/device*, grant không bật', function () {
    expect(Passport::$deviceCodeGrantEnabled)->toBeFalse()
        ->and(Route::has('passport.device'))->toBeFalse()
        ->and(Route::has('passport.device.code'))->toBeFalse();

    $this->post('/oauth/device/code', ['client_id' => 'bat-ky'])->assertNotFound();
});

it('R1 personal access token: createToken() không cấp được token nào', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    app(ClientRepository::class)->createPersonalAccessGrantClient('Ca nhan', 'users');

    expect(fn () => $user->createToken('thu', ['mcp:use']))->toThrow(LogicException::class)
        ->and(fn () => app(PersonalAccessTokenFactory::class))->toThrow(LogicException::class);

    expect(Passport::token()->newQuery()->count())->toBe(0);
});

it('R1 Passport không đăng ký route JSON quản lý client/token', function () {
    expect(Route::has('passport.clients.index'))->toBeFalse()
        ->and(Route::has('passport.tokens.index'))->toBeFalse()
        ->and(Route::has('passport.personal.tokens.store'))->toBeFalse();
});
