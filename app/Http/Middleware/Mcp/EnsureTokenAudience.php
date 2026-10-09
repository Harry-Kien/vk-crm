<?php

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\McpAccessToken;
use App\Support\Mcp\McpEndpoint;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * M11 R7 — access token phải được cấp CHO máy chủ MCP này: claim `aud` phải chứa đúng URL MCP chuẩn
 * ({@see McpEndpoint::resource()}). Không thì 401, và `WWW-Authenticate` mang
 * `error="invalid_token"` ({@see AddWwwAuthenticateHeader}).
 *
 * league/oauth2-server kiểm chữ ký và thời hạn của JWT nhưng KHÔNG kiểm `aud`, và laravel/mcp cũng
 * không (rà soát Task 0). Entity của app ({@see McpAccessToken}) ghi URL MCP vào
 * `aud` lúc cấp; lớp này đòi nó lúc dùng. Token Passport gốc (`aud` chỉ có id client) và token cấp
 * khi URL chuẩn còn là một giá trị khác (đổi `APP_URL`, `ADMIN_DOMAIN`) đều bị từ chối.
 *
 * **Phải đứng SAU `auth:mcp`** (`routes/ai.php`). Lớp này đọc claim mà không kiểm chữ ký: nó tin rằng
 * guard vừa kiểm chữ ký, hạn và trạng thái thu hồi của CHÍNH chuỗi token này. Vì vậy nó tách token
 * khỏi header đúng như bộ kiểm của league (`BearerTokenValidator`: giá trị ĐẦU của header
 * `Authorization`, bỏ tiền tố `Bearer ` không phân biệt hoa thường, cắt khoảng trắng), không qua
 * `Request::bearerToken()` (cách cắt khác: lấy lần xuất hiện CUỐI của `Bearer ` và cắt ở dấu phẩy).
 * Đặt nhầm lên trước `auth:mcp` cũng không mở được gì: guard vẫn chạy sau và từ chối token giả.
 */
class EnsureTokenAudience
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(McpEndpoint::resource(), self::audiences($request), true)) {
            throw new AuthenticationException('Unauthenticated.', ['mcp']);
        }

        return $next($request);
    }

    /** @return list<string> */
    private static function audiences(Request $request): array
    {
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', (string) $request->headers->get('Authorization', '')));

        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable) {
            return [];
        }

        if (! $token instanceof UnencryptedToken) {
            return [];
        }

        return array_values(array_filter(
            (array) $token->claims()->get(RegisteredClaims::AUDIENCE, []),
            'is_string',
        ));
    }
}
