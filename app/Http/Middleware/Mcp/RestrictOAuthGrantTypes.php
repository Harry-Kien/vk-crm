<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R1 — `/oauth/token` chỉ cấp token bằng `authorization_code` (kèm PKCE) và `refresh_token`.
 *
 * Mỗi grant ngoài hai grant đó được tắt ở đúng một chỗ:
 * - password grant và implicit: Passport 13 TẮT sẵn (`Passport::$passwordGrantEnabled`,
 *   `$implicitGrantEnabled`). Không chỗ nào bật lại.
 * - device code: tắt ở `AppServiceProvider::register()`, kèm route `/oauth/device*`.
 * - personal access token: `AppServiceProvider::register()` làm `PersonalAccessTokenFactory` không
 *   phân giải được (grant này không đi qua `/oauth/token`).
 * - `client_credentials`: **lớp này**. `PassportServiceProvider` LUÔN đăng ký
 *   `ClientCredentialsGrant` vào `AuthorizationServer` (rà soát Task 0 mục 4), và Passport 13 không có
 *   cờ nào để tắt nó. Một client confidential mang grant đó (`passport:client --client`) sẽ đổi được
 *   token máy-với-máy, mà Claude không hỗ trợ [DC:359] và R1 cấm.
 *
 * Từ chối theo đúng hình dạng lỗi của RFC 6749 §5.2 (`unsupported_grant_type`, HTTP 400), để client
 * OAuth hiểu được, không phải 403 hay 404.
 *
 * Đăng ký ở `config/passport.php` (`middleware`), khoá mà `PassportServiceProvider` đọc làm
 * middleware cho cả nhóm route của nó. Vì vậy lớp này đứng trước MỌI route của Passport, nhưng chỉ
 * hành động ở route `passport.token`. Không sửa route của gói sau khi gói đã đăng ký nó.
 */
class RestrictOAuthGrantTypes
{
    /** @var list<string> */
    public const ALLOWED_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.token')
            && ! in_array($request->input('grant_type'), self::ALLOWED_GRANT_TYPES, true)) {
            return response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => __('mcp.http.unsupported_grant_type'),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}
