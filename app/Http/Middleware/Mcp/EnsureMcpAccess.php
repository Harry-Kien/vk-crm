<?php

namespace App\Http\Middleware\Mcp;

use App\Models\User;
use App\Support\Mcp\McpAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R2, R12 (Task 6) — người sở hữu token phải được dùng máy chủ MCP ở CHÍNH request này: tài khoản
 * đang hoạt động, quản trị đã bật `ai_access` (và người đó còn giữ được nó), công tắc toàn hệ thống
 * `mcp.enabled` bật, đã cam kết chính sách dùng AI đúng phiên bản hiện hành. Định nghĩa và thứ tự ở
 * {@see McpAccess::refusal()}. Không đạt điều nào thì 401, và `WWW-Authenticate` mang
 * `error="invalid_token"` ({@see AddWwwAuthenticateHeader}) — client hiện lại nút kết nối, và màn hình
 * đồng ý (Task 4) nói lý do bằng tiếng Việt.
 *
 * Kiểm ở MỖI request, không lúc cấp token [DC:149]: quản trị tắt một người, hạ công tắc, hay đổi phiên
 * bản chính sách thì request kế tiếp của MỌI token đang sống bị chặn, không phải chờ token hết hạn.
 *
 * Hai điều kiện còn lại của R2 đứng ngay trước lớp này: client mang cờ `is_mcp`
 * ({@see EnsureMcpClient}) và scope `mcp:use` (`CheckToken`). **Phải đứng SAU `auth:mcp`**
 * (`routes/ai.php`): người dùng đọc từ guard `mcp`, tường minh — không từ `auth('web')` hay
 * `auth('client')`, cả hai đều rỗng trong một request MCP.
 */
class EnsureMcpAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('mcp')->user();

        if (! $user instanceof User || McpAccess::refusal($user) !== null) {
            throw new AuthenticationException('Unauthenticated.', ['mcp']);
        }

        return $next($request);
    }
}
