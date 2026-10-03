<?php

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\McpAccessToken;
use App\Support\Mcp\McpEndpoint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R7 — hai luật mà league/oauth2-server 9.4.1 không tự áp, kiểm trước khi Passport xử lý:
 *
 * 1. **PKCE chỉ S256, bắt buộc với MỌI client** (`GET /oauth/authorize`). `AuthCodeGrant` của league
 *    nhận cả `plain`, coi `code_challenge_method` vắng mặt là `plain` (đúng RFC 7636 §4.3), và chỉ
 *    bắt buộc PKCE với client công khai. AS metadata khai `code_challenge_methods_supported: ["S256"]`
 *    và ChatGPT đòi đúng như vậy [PL:70]; OAuth 2.1 đòi PKCE cả với client confidential (ví dụ client
 *    tạo bằng `passport:client`). Thiếu `code_challenge`, hoặc method không phải đúng chuỗi `S256` →
 *    `invalid_request`.
 * 2. **`resource` (RFC 8707) chỉ được là URL MCP chuẩn** ({@see McpEndpoint::isResource()}), ở cả
 *    `GET /oauth/authorize` lẫn `POST /oauth/token` (`authorization_code` và `refresh_token`): ChatGPT
 *    gửi `resource` ở cả hai chỗ [PL:169]. Giá trị khác → `invalid_target` (RFC 8707 §2). Gửi nhiều
 *    `resource` thì mọi giá trị phải khớp. KHÔNG gửi `resource` thì được: app chỉ có một máy chủ tài
 *    nguyên, và token luôn mang `aud` của nó ({@see McpAccessToken}).
 *
 * **Cách báo lỗi ở `/oauth/authorize`: chỉ chuyển hướng về redirect URI ĐÃ ĐƯỢC KIỂM.** RFC 6749
 * §4.1.2.1: client hay redirect URI không hợp lệ thì KHÔNG được tự chuyển hướng. Khi có vi phạm, lớp
 * này cho chính `AuthorizationServer` của Passport kiểm yêu cầu trước (client, redirect URI, scope).
 * Nếu league từ chối thì yêu cầu đi tiếp vào Passport, và Passport trả lỗi của nó cho đúng lỗi ấy
 * (không chuyển hướng tới URI chưa kiểm). Nếu league chấp nhận thì lớp này chuyển hướng về redirect
 * URI đó với `error`, `error_description` và `state` (RFC 6749 §4.1.2.1); `iss` do
 * {@see AddIssuerToAuthorizationResponse} (đứng ngoài lớp này) gắn thêm. Không có gì được lưu vào
 * phiên và màn hình đồng ý không hiện.
 *
 * Ở `/oauth/token`: 400 JSON `{"error":"invalid_target"}` (RFC 6749 §5.2 / RFC 8707 §2), trước khi
 * Passport đụng tới mã hay refresh token, nên mã/refresh token đó vẫn dùng được với `resource` đúng.
 *
 * Đăng ký ở `config/passport.php` (`middleware`); chỉ hành động ở `passport.authorizations.authorize`
 * và `passport.token`.
 */
class ValidateOAuthParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('passport.authorizations.authorize')) {
            $violation = self::authorizationViolation($request);

            return $violation === null ? $next($request) : $this->rejectAuthorization($request, $next, ...$violation);
        }

        if ($request->routeIs('passport.token') && ! self::resourceIsAcceptable($request->input('resource'))) {
            return response()->json([
                'error' => 'invalid_target',
                'error_description' => __('mcp.http.invalid_target'),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    /** @return array{0: string, 1: string}|null [mã lỗi OAuth, mô tả] */
    private static function authorizationViolation(Request $request): ?array
    {
        if (blank($request->query('code_challenge')) || $request->query('code_challenge_method') !== 'S256') {
            return ['invalid_request', __('mcp.http.pkce_s256_required')];
        }

        if (! self::resourceIsAcceptable($request->query('resource'))) {
            return ['invalid_target', __('mcp.http.invalid_target')];
        }

        return null;
    }

    /** Không gửi `resource` thì được; gửi một hay nhiều giá trị thì mọi giá trị phải là URL MCP chuẩn. */
    private static function resourceIsAcceptable(mixed $resource): bool
    {
        if ($resource === null) {
            return true;
        }

        $values = Arr::wrap($resource);

        if ($values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! McpEndpoint::isResource($value)) {
                return false;
            }
        }

        return true;
    }

    private function rejectAuthorization(Request $request, Closure $next, string $error, string $description): Response
    {
        try {
            $authRequest = app(AuthorizationServer::class)->validateAuthorizationRequest(
                (new PsrHttpFactory)->createRequest($request),
            );
        } catch (OAuthServerException) {
            return $next($request);
        }

        $redirectUri = $authRequest->getRedirectUri() ?? Arr::wrap($authRequest->getClient()->getRedirectUri())[0];

        $query = http_build_query(array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $authRequest->getState(),
        ], fn ($value) => $value !== null));

        return redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query);
    }
}
