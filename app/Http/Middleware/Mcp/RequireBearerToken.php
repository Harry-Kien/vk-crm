<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R1 — `/mcp` chỉ nhận token ở header `Authorization: Bearer …`. Không phiên, không cookie,
 * không query string.
 *
 * Vì sao cần lớp này khi đã có `auth:mcp`: `Laravel\Passport\Guards\TokenGuard` có HAI đường xác
 * thực. Ngoài bearer, nó còn nhận cookie `laravel_token` (`TokenGuard::user()`, rà soát Task 0 mục
 * 7) và gắn cho người đó một `TransientToken` có MỌI scope. Cookie ấy là một JWT ký bằng `APP_KEY`,
 * kèm mã CSRF của phiên, và route `POST /oauth/token/refresh` của Passport phát nó cho BẤT KỲ phiên
 * `web` nào, kể cả phiên chưa qua 2FA. Không chặn ở đây thì một phiên `/admin` đi vòng được qua
 * màn hình đồng ý OAuth (Task 4) để vào `/mcp`.
 *
 * Khi có header bearer, `TokenGuard` chỉ thử bearer và không bao giờ rơi xuống cookie. Vì vậy chỉ
 * cần đòi có bearer là đóng được đường cookie. Thiếu bearer thì ném `AuthenticationException`, và
 * request nhận cùng phản hồi 401 kèm `WWW-Authenticate` như mọi request chưa xác thực khác
 * ({@see AddWwwAuthenticateHeader}).
 */
class RequireBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            throw new AuthenticationException('Unauthenticated.', ['mcp']);
        }

        return $next($request);
    }
}
