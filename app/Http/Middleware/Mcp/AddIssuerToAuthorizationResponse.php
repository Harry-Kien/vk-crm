<?php

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\McpEndpoint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 — tham số `iss` (RFC 9207) trong MỌI phản hồi uỷ quyền mà `/oauth/authorize` chuyển hướng
 * về client, cả thành công (`code`) lẫn lỗi (`error`), với giá trị đúng bằng `issuer` của AS
 * metadata ({@see McpEndpoint::issuer()}).
 *
 * league/oauth2-server 9.4.1 và Passport 13.8.0 không có `iss` (rà soát Task 0, mục 3). ChatGPT chỉ
 * dùng redirect ổn định `connector_platform_oauth_redirect` khi máy chủ trả `iss` [PL:169]; đặc tả
 * MCP 2026-07-28 đưa RFC 9207 vào, và client phải so `iss` với issuer trước khi đổi mã [PL:163].
 * AS metadata chỉ quảng bá
 * `authorization_response_iss_parameter_supported` khi lớp này đang đứng trong nhóm route
 * ({@see self::isActive()}).
 *
 * Đăng ký ở `config/passport.php` (`middleware`), cùng chỗ với `RestrictOAuthGrantTypes`, và đứng
 * TRƯỚC `ValidateOAuthParameters` để bọc nó: lỗi `invalid_target` / `invalid_request` mà lớp đó
 * chuyển hướng về client cũng mang `iss`. Chỉ hành động ở ba route uỷ quyền: GET (Passport tự duyệt
 * khi người dùng đã có token còn hạn, hoặc lỗi), POST (duyệt), DELETE (từ chối). Phản hồi lỗi mà
 * Passport ném ra (`OAuthServerException`, `HttpResponseException`) đã được router dựng thành phản
 * hồi trước khi quay ra tới đây, nên cũng được gắn.
 *
 * "Phản hồi uỷ quyền" = chuyển hướng 3xx mà query của `Location` có `code` hoặc `error`. Chuyển hướng
 * khác (ví dụ tới trang đăng nhập) không có hai khoá đó và đi qua nguyên vẹn; `Location` đã có `iss`
 * thì không gắn lần hai. `iss` được nối vào cuối query, trước fragment nếu có; phần còn lại của
 * `Location` không bị dựng lại.
 */
class AddIssuerToAuthorizationResponse
{
    /** @var list<string> */
    public const ROUTES = [
        'passport.authorizations.authorize',
        'passport.authorizations.approve',
        'passport.authorizations.deny',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs(...self::ROUTES) || ! $response->isRedirection()) {
            return $response;
        }

        $location = (string) $response->headers->get('Location');

        if (! self::isAuthorizationResponse($location)) {
            return $response;
        }

        $withIssuer = self::appendIssuer($location);

        if ($response instanceof RedirectResponse) {
            $response->setTargetUrl($withIssuer);
        } else {
            $response->headers->set('Location', $withIssuer);
        }

        return $response;
    }

    /** Lớp này có đang đứng trong nhóm route của Passport không (AS metadata đọc để quảng bá `iss`). */
    public static function isActive(): bool
    {
        return in_array(self::class, (array) config('passport.middleware', []), true);
    }

    private static function isAuthorizationResponse(string $location): bool
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (isset($query['code']) || isset($query['error'])) && ! isset($query['iss']);
    }

    private static function appendIssuer(string $location): string
    {
        [$beforeFragment, $fragment] = array_pad(explode('#', $location, 2), 2, null);

        $separator = str_contains($beforeFragment, '?') ? '&' : '?';

        return $beforeFragment.$separator.http_build_query(['iss' => McpEndpoint::issuer()])
            .($fragment === null ? '' : '#'.$fragment);
    }
}
