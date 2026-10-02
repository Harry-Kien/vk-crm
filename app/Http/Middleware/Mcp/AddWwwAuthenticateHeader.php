<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader as PackageAddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 — header `WWW-Authenticate` của mọi phản hồi 401 từ `/mcp`:
 * `Bearer realm="mcp", resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/mcp",
 * scope="mcp:use"`.
 *
 * Claude chỉ hiện nút Connect khi nhận đúng HTTP 401 kèm `resource_metadata` trỏ tới PRM
 * (RFC 9728) [DC:628]. Lớp của gói chỉ ghi `resource_metadata` khi route có tên
 * `mcp.oauth.protected-resource.nested` tồn tại, tức khi `Mcp::oauthRoutes()` đã được gọi. Thiếu
 * route đó, nó ghi `error="invalid_token"` và không trỏ đi đâu. Lớp này bỏ sự phụ thuộc ấy: URL
 * luôn dựng từ đường dẫn của chính request (`/mcp` → `…/oauth-protected-resource/mcp`), đúng dạng
 * mà lớp của gói dựng khi có route. Không có `error=`: RFC 6750 §3.1 nói request không mang thông
 * tin xác thực nào thì không nên kèm mã lỗi.
 *
 * **Được bind thay cho lớp của gói** (`AppServiceProvider::register()`), không đăng ký thêm. Gói
 * đẩy lớp của nó vào CẢ middleware toàn cục (`McpServiceProvider::registerGlobalMiddleware()`) LẪN
 * middleware của route `/mcp` (`Registrar::web()`), và cả hai chỗ đều phân giải qua container. Một
 * middleware riêng của app đứng trong route sẽ bị lớp toàn cục của gói ghi đè header khi phản hồi
 * đi ngược ra. Điều kiện "route này có đăng ký middleware của gói không" vì thế vẫn hỏi theo TÊN
 * lớp của gói, đúng như lớp gốc.
 *
 * Route PRM thật do Task 2 dựng; cho tới lúc đó URL trong header chưa trả lời được.
 */
class AddWwwAuthenticateHeader extends PackageAddWwwAuthenticateHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED) {
            return $response;
        }

        $route = $request->route();

        if (! $route instanceof Route || ! in_array(PackageAddWwwAuthenticateHeader::class, app('router')->gatherRouteMiddleware($route), true)) {
            return $response;
        }

        $response->headers->set('WWW-Authenticate', sprintf(
            'Bearer realm="mcp", resource_metadata="%s", scope="%s"',
            url('/.well-known/oauth-protected-resource/'.ltrim($request->path(), '/')),
            Registrar::OAUTH_SCOPE,
        ));

        return $response;
    }
}
