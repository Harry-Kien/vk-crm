<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R8 (Task 8; rà soát Task 2, m2): `/mcp` chưa xác thực không còn là một vòng lặp miễn phí.
 *
 * Giới hạn của R8 tính theo người và theo token ({@see ThrottleMcp}), nên chỉ áp được SAU khi xác
 * thực. Request mang bearer sai thì chưa có ai để đếm, và mỗi lần bắt `TokenGuard` của Passport kiểm
 * chữ ký JWT. Middleware này đếm theo IP (`$request->ip()`, sau `TrustProxies`) đúng những request bị
 * TỪ CHỐI 401: quá {@see self::MAX_FAILURES} lần trong {@see self::DECAY_SECONDS} giây thì mọi request
 * `/mcp` từ IP đó nhận 429 kèm `Retry-After` ngay, trước bước kiểm token.
 *
 * Request xác thực được không bao giờ được đếm: một nền tảng AI gọi thay nhiều nhân sự từ cùng một IP
 * [PL:177] không tự khoá mình vì lượt gọi đúng. Một nền tảng chỉ bị chặn khi chính IP đó gửi quá 30
 * token hỏng trong một phút (client thật nhận 401 khi token hết hạn, rồi làm mới: vài lần một giờ).
 *
 * Đứng NGAY SAU `CheckOrigin` (403 của Origin lạ không cần kiểm token, nên không đếm) và TRƯỚC
 * `RequireBearerToken` (`routes/ai.php`). Đi cùng: `bootstrap/app.php` không còn `report()` lỗi
 * `OAuthServerException` của league mà `TokenGuard` ném cho mỗi bearer sai — mỗi lần một dòng log lỗi
 * kèm stack trace là đường lấp đầy `storage/logs` trên hosting chung.
 */
class ThrottleMcpAuthenticationFailures
{
    public const MAX_FAILURES = 30;

    public const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'mcp:auth-failures:'.$request->ip();

        if ($this->limiter->tooManyAttempts($key, self::MAX_FAILURES)) {
            return response()->json(['message' => __('mcp_audit.too_many_failures')], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $this->limiter->availableIn($key)),
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            $this->limiter->hit($key, self::DECAY_SECONDS);
        }

        return $response;
    }
}
