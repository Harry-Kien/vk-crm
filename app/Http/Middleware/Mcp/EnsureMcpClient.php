<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Guards\TokenGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R2/R7 (Task 3) — token phải thuộc một client OAuth mang cờ `oauth_clients.is_mcp`, tức một
 * client do đăng ký động của máy chủ MCP tạo (`App\Actions\Mcp\RegisterMcpClient`). Không thì 401, và
 * `WWW-Authenticate` mang `error="invalid_token"` ({@see AddWwwAuthenticateHeader}).
 *
 * Lớp ràng buộc bằng CẤU TRÚC của R7: token cấp cho một client khác của Passport (tạo bằng
 * `passport:client`, hay một tích hợp OAuth nào sau này) không mở được `/mcp`, dù chữ ký, hạn, `aud`
 * và scope đều đúng. Đây là điều kiện "client OAuth mang cờ `mcp`" trong danh sách kiểm của
 * `EnsureMcpAccess` (R2, Task 6); Task 6 không cần kiểm lại.
 *
 * Đọc cờ ở MỖI request, không lúc cấp token: gỡ cờ thì token đang sống chết ngay ở request kế tiếp.
 * Client đọc từ guard `mcp` ({@see TokenGuard::client()}): guard vừa nạp nó từ CSDL theo
 * `oauth_client_id` của chính token này, và chỉ khi client chưa bị thu hồi (`findActive`).
 *
 * **Phải đứng SAU `auth:mcp`** (`routes/ai.php`): trước đó guard chưa xác thực token nào. Đặt nhầm lên
 * trước cũng không mở được gì: `client()` khi đó tự kiểm bearer, và `auth:mcp` vẫn chạy sau.
 */
class EnsureMcpClient
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var TokenGuard $guard driver `passport` (`config/auth.php`, ghim ở McpPackageConfigTest) */
        $guard = Auth::guard('mcp');
        $client = $guard->client();

        if (! (bool) $client?->getAttribute('is_mcp')) {
            throw new AuthenticationException('Unauthenticated.', ['mcp']);
        }

        return $next($request);
    }
}
