<?php

namespace App\Http\Middleware\Mcp;

use App\Http\Middleware\RestrictAdminIpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 Task 4 (phán quyết controller) — `ADMIN_IP_ALLOWLIST` phủ cả màn hình đồng ý OAuth: ba route
 * `/oauth/authorize` (GET màn hình, POST "Đồng ý", DELETE "Từ chối") trả 404 cho IP ngoài danh sách,
 * đúng luật và đúng câu trả lời của panel `/admin` ({@see RestrictAdminIpAllowlist}, gọi lại nguyên
 * lớp đó: danh sách rỗng = tắt).
 *
 * Lý do: màn hình đồng ý là màn hình của NHÂN SỰ, dùng phiên `/admin`, và nó sinh ra thứ mạnh hơn một
 * trang admin — một cặp token sống 30 ngày. Nó nằm ngoài panel nên middleware của panel không phủ nó.
 *
 * KHÔNG phủ `/oauth/token`, `/oauth/register`, `/mcp`, metadata: Claude và ChatGPT gọi chúng từ hạ
 * tầng của họ [PL:177], [PL:181], không từ máy của nhân sự. Đăng ký ĐẦU `passport.middleware`
 * (`config/passport.php`): một IP ngoài danh sách không đi tới bước kiểm OAuth nào.
 */
class RestrictConsentScreenToAdminIps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...AddIssuerToAuthorizationResponse::ROUTES)) {
            return $next($request);
        }

        return app(RestrictAdminIpAllowlist::class)->handle($request, $next);
    }
}
