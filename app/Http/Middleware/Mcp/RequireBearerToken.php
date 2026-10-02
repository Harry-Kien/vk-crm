<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R1 — `/mcp` chỉ nhận token ở header `Authorization: Bearer …`. Không phiên, không cookie,
 * không query string.
 *
 * Vì sao cần lớp này khi đã có `auth:mcp`: `Laravel\Passport\Guards\TokenGuard` có HAI đường xác
 * thực. Ngoài bearer, nó còn nhận cookie `laravel_token` (`TokenGuard::user()`, rà soát Task 0 mục
 * 7) và gắn cho người đó một `TransientToken` có MỌI scope, nên `CheckToken mcp:use` cũng qua. Cookie
 * ấy là một JWT ký bằng `APP_KEY`, kèm mã CSRF của phiên, và route `POST /oauth/token/refresh` của
 * Passport phát nó cho BẤT KỲ phiên `web` nào, kể cả phiên chưa qua 2FA. Không chặn ở đây thì một
 * phiên `/admin` đi vòng được qua màn hình đồng ý OAuth (Task 4) để vào `/mcp`.
 *
 * Lớp này làm hai việc:
 *
 * 1. Xoá cookie `laravel_token` khỏi request trước khi chuyển tiếp. `TokenGuard` đọc chính đối tượng
 *    request này, nên không bao giờ đi được đường cookie. Đây là phần đóng lỗ hổng. Chỉ đòi "có
 *    bearer" thì không đủ: `TokenGuard::user()` rẽ nhánh theo giá trị đúng/sai của `bearerToken()`,
 *    không theo `null`. `Authorization: Bearer 0` cho ra `"0"`, `Authorization: Bearer ,` cho ra
 *    `""`; cả hai là "sai" trong PHP, nên guard bỏ qua bearer và đọc cookie (rà soát Task 1, C1).
 * 2. Chặn bearer trống theo `blank()` (không có, rỗng, hoặc chỉ khoảng trắng) bằng
 *    `AuthenticationException`, trước `auth:mcp`. Bearer chỉ có khoảng trắng là "đúng" trong PHP;
 *    để nó đi tiếp thì `TokenGuard` đem nó đi kiểm chữ ký, thất bại, và `report()` một lỗi vào log.
 *    `"0"` không trống theo `blank()`: nó đi tiếp, và vì cookie đã bị xoá, `auth:mcp` trả 401.
 *
 * Request bị chặn ở cả hai chỗ nhận cùng phản hồi 401 kèm `WWW-Authenticate` như mọi request chưa
 * xác thực khác ({@see AddWwwAuthenticateHeader}).
 */
class RequireBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->cookies->remove(Passport::cookie());

        if (blank($request->bearerToken())) {
            throw new AuthenticationException('Unauthenticated.', ['mcp']);
        }

        return $next($request);
    }
}
