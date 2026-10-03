<?php

use App\Http\Middleware\Mcp\AddIssuerToAuthorizationResponse;
use App\Http\Middleware\Mcp\RequireConsentForMetadataDocumentClients;
use App\Http\Middleware\Mcp\RestrictOAuthGrantTypes;
use App\Http\Middleware\Mcp\ValidateOAuthParameters;

/**
 * `config/mcp.php` và `config/passport.php` — hai tệp cấu hình M11 Task 1 phát hành từ gói.
 *
 * Cùng lý lẽ với `LivewireUploadConfigTest`: cả hai gói gộp cấu hình bằng `mergeConfigFrom()`, thứ
 * gộp NÔNG, nên tệp phát hành phải giữ ĐỦ khoá cấp một của nhà cung cấp, và chỉ đổi đúng những khoá
 * task này cố ý đổi. Test đối chiếu với chính tệp còn nằm trong `vendor/`.
 */
dataset('published package configs', [
    'laravel/mcp' => ['mcp', 'vendor/laravel/mcp/config/mcp.php', ['redirect_domains']],
    'laravel/passport' => ['passport', 'vendor/laravel/passport/config/passport.php', ['middleware']],
]);

it('giữ đủ khoá cấp một của tệp nhà cung cấp và chỉ đổi đúng các khoá đã kể', function (string $name, string $vendorPath, array $changed) {
    $vendor = require base_path($vendorPath);
    $published = require config_path($name.'.php');

    expect(array_keys($published))->toBe(array_keys($vendor));

    foreach ($vendor as $key => $value) {
        if (in_array($key, $changed, true)) {
            expect($published[$key])->not->toEqual($value, "{$name}.{$key} phải khác mặc định");

            continue;
        }

        expect($published[$key])->toEqual($value, "{$name}.{$key} phải giữ mặc định");
    }
})->with('published package configs');

/*
 * R7: `redirect_domains` mặc định của laravel/mcp là `['*']` — mọi redirect URI. Task 1 khoá về
 * rỗng (DCR, nếu có ai bật route của nó trước Task 3, từ chối MỌI redirect); Task 3 thay bằng
 * allowlist so khớp chính xác. `custom_schemes` ghim rỗng: `OAuthRegisterController` cho một scheme
 * riêng có trong danh sách này đi thẳng qua, bỏ qua `redirect_domains` (rà soát Task 0, M4a).
 */
it('R7 laravel/mcp: redirect_domains không có "*", custom_schemes rỗng', function () {
    expect(config('mcp.redirect_domains'))->toBe([])
        ->and(config('mcp.custom_schemes'))->toBe([]);
});

/*
 * Thứ tự là một phần của luật: `AddIssuerToAuthorizationResponse` (Task 2) phải bọc NGOÀI
 * `ValidateOAuthParameters` và `RequireConsentForMetadataDocumentClients` (Task 5), để chính phản hồi
 * lỗi `invalid_target` / `invalid_request` / `consent_required` mà hai lớp sau chuyển hướng về client
 * cũng mang `iss` (RFC 9207 đòi `iss` ở cả phản hồi lỗi).
 */
it('R1/R7 laravel/passport: guard đăng nhập là web (nhân sự); middleware của nhóm route: chặn grant, gắn iss, kiểm PKCE và resource, ép đồng ý cho client CIMD, đúng thứ tự', function () {
    expect(config('passport.guard'))->toBe('web')
        ->and(config('passport.middleware'))->toBe([
            RestrictOAuthGrantTypes::class,
            AddIssuerToAuthorizationResponse::class,
            ValidateOAuthParameters::class,
            RequireConsentForMetadataDocumentClients::class,
        ]);
});

it('R1 guard mcp: driver passport, provider users — không bao giờ client_users', function () {
    expect(config('auth.guards.mcp'))->toBe(['driver' => 'passport', 'provider' => 'users']);
});
