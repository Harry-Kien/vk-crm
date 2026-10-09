<?php

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\McpEndpoint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader as PackageAddWwwAuthenticateHeader;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Exceptions\MissingScopeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 — header `WWW-Authenticate` của phản hồi 401 và 403-vì-thiếu-scope từ `/mcp`.
 *
 * - **401**: `Bearer realm="mcp", resource_metadata="<PRM>", scope="mcp:use"`, thêm
 *   `, error="invalid_token"` khi request CÓ gửi một bearer (sai chữ ký, hết hạn, bị thu hồi, `aud`
 *   không phải URL MCP này, hoặc `Bearer 0`). Không gửi bearer nào thì không có mã lỗi: RFC 6750
 *   §3.1 nói request không mang thông tin xác thực thì không nên kèm mã lỗi. Claude chỉ hiện nút
 *   Connect khi nhận đúng HTTP 401 kèm `resource_metadata` trỏ tới PRM (RFC 9728) [DC:628].
 * - **403 do `CheckToken mcp:use`** (`MissingScopeException`): `Bearer error="insufficient_scope",
 *   scope="mcp:use", resource_metadata="<PRM>"` (RFC 6750 §3.1, mục "scope challenge" của đặc tả
 *   MCP), để client biết phải xin lại token với scope nào. 403 khác (Origin lạ, `CheckOrigin`) không
 *   có header này: đó không phải chuyện token.
 *
 * `<PRM>` là URL chuẩn ({@see McpEndpoint::protectedResourceMetadataUrl()}), dựng từ cấu hình chứ
 * không từ host của request: khi `ADMIN_DOMAIN`/`PORTAL_DOMAIN` tách hai tên miền, một client gọi qua
 * tên miền cổng khách vẫn được trỏ về MỘT PRM (rà soát Task 1, m6).
 *
 * "Có gửi bearer" được đọc TRƯỚC khi request đi tiếp: khi kiểm token thất bại, `TokenGuard` của
 * Passport xoá header `Authorization` trên chính đối tượng request
 * (`getPsrRequestViaBearerToken()`), nên đọc sau `$next()` luôn thấy rỗng.
 *
 * Lớp của gói chỉ ghi `resource_metadata` khi route `mcp.oauth.protected-resource.nested` tồn tại,
 * tức khi `Mcp::oauthRoutes()` đã được gọi; thiếu route đó, nó ghi `error="invalid_token"` và không
 * trỏ đi đâu. Lớp này bỏ sự phụ thuộc ấy.
 *
 * **Được bind thay cho lớp của gói** (`AppServiceProvider::register()`), không đăng ký thêm. Gói
 * đẩy lớp của nó vào CẢ middleware toàn cục (`McpServiceProvider::registerGlobalMiddleware()`) LẪN
 * middleware của route `/mcp` (`Registrar::web()`), và cả hai chỗ đều phân giải qua container. Một
 * middleware riêng của app đứng trong route sẽ bị lớp toàn cục của gói ghi đè header khi phản hồi
 * đi ngược ra. Điều kiện "route này có đăng ký middleware của gói không" vì thế vẫn hỏi theo TÊN
 * lớp của gói, đúng như lớp gốc.
 */
class AddWwwAuthenticateHeader extends PackageAddWwwAuthenticateHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $presentedBearer = filled($request->bearerToken());

        $response = $next($request);

        $status = $response->getStatusCode();

        if (! in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
            return $response;
        }

        $route = $request->route();

        if (! $route instanceof Route || ! in_array(PackageAddWwwAuthenticateHeader::class, app('router')->gatherRouteMiddleware($route), true)) {
            return $response;
        }

        if ($status === Response::HTTP_FORBIDDEN) {
            if (($response->exception ?? null) instanceof MissingScopeException) {
                $response->headers->set('WWW-Authenticate', sprintf(
                    'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"',
                    Registrar::OAUTH_SCOPE,
                    McpEndpoint::protectedResourceMetadataUrl(),
                ));
            }

            return $response;
        }

        $response->headers->set('WWW-Authenticate', sprintf(
            'Bearer realm="mcp", resource_metadata="%s", scope="%s"%s',
            McpEndpoint::protectedResourceMetadataUrl(),
            Registrar::OAUTH_SCOPE,
            $presentedBearer ? ', error="invalid_token"' : '',
        ));

        return $response;
    }
}
