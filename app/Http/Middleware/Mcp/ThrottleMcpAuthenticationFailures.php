<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R8 (Task 8; rà soát Task 2, m2; rà soát Task 8, I2): một bearer hỏng không còn là một vòng lặp
 * miễn phí trên `/mcp`.
 *
 * Giới hạn của R8 tính theo người và theo token ({@see ThrottleMcp}), nên chỉ áp được SAU khi xác
 * thực. Middleware này đếm những request MANG BEARER mà bị từ chối 401, theo cặp (IP sau
 * `TrustProxies`, dấu băm sha256 của chính bearer đó): một bearer đã nhận 401 {@see self::MAX_FAILURES}
 * lần trong {@see self::DECAY_SECONDS} giây từ một IP thì chính bearer đó, từ IP đó, nhận 429 kèm
 * `Retry-After` ngay, trước bước kiểm token — tới khi hết cửa sổ một phút tính từ lần 401 đầu.
 *
 * Không bao giờ chặn:
 *  - một bearer KHÁC từ cùng IP. claude.ai và ChatGPT gọi thay mọi người dùng của họ từ chung một dải
 *    IP [PL:177], [PL:181]; người lạ bơm 401 từ IP đó (thêm `/mcp` làm connector, hay gửi bearer rác
 *    qua API của nền tảng) không làm token hợp lệ của nhân sự nhận 429;
 *  - request KHÔNG mang bearer (`initialize` đầu tiên của mọi connector, trước khi có token): nó dừng ở
 *    `RequireBearerToken` mà không kiểm chữ ký JWT nào và không ghi log, nên chặn nó không tiết kiệm
 *    được gì, còn người lạ thì khoá được bước kết nối của mọi người dùng chung IP nền tảng;
 *  - request xác thực được (không phải 401).
 * Một 401 của `EnsureMcpAccess` (token hợp lệ, người bị từ chối) cũng được đếm cho chính bearer đó:
 * connector lặp lại một token đã bị từ chối thì chính nó nhận 429.
 *
 * Bộ đếm của một IP là MỘT mục cache ({@see self::cacheKey()}), giữ tối đa
 * {@see self::MAX_TRACKED_BEARERS} bearer, chỉ dấu băm, không bearer thô. Đầy thì bỏ bearer ít lần hỏng
 * nhất (cùng số lần thì bỏ bearer cũ hơn): một loạt bearer rác mỗi cái một lần không đẩy được ra một
 * bearer đang lặp đã bị chặn, và người gửi bearer rác không tạo được một dòng cache mới cho mỗi bearer
 * (cache mặc định là bảng `cache` của CSDL, không tự dọn). Đọc rồi ghi không nguyên tử: hai request
 * cùng lúc có thể làm mất một lần đếm, tức chặn chậm hơn một chút, không bao giờ chặn nhầm bearer khác.
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

    public const MAX_TRACKED_BEARERS = 20;

    public function __construct(private readonly Cache $cache) {}

    /** Khoá cache giữ bộ đếm của một IP. */
    public static function cacheKey(?string $ip): string
    {
        return 'mcp:auth-failures:'.$ip;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (blank($bearer)) {
            return $next($request);
        }

        $key = self::cacheKey($request->ip());
        $hash = hash('sha256', $bearer);
        $now = now()->getTimestamp();
        $tracked = $this->tracked($key, $now);

        if (isset($tracked[$hash]) && $tracked[$hash]['count'] >= self::MAX_FAILURES) {
            return response()->json(['message' => __('mcp_audit.too_many_failures')], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) max(1, $tracked[$hash]['reset'] - $now),
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            $this->remember($key, $this->tracked($key, $now), $hash, $now);
        }

        return $response;
    }

    /**
     * Các bearer còn trong cửa sổ đếm của IP: dấu băm => [số lần 401, thời điểm hết cửa sổ].
     *
     * @return array<string, array{count: int, reset: int}>
     */
    private function tracked(string $key, int $now): array
    {
        $stored = $this->cache->get($key);

        return array_filter(
            is_array($stored) ? $stored : [],
            fn (mixed $entry): bool => is_array($entry)
                && is_int($entry['count'] ?? null)
                && is_int($entry['reset'] ?? null)
                && $entry['reset'] > $now,
        );
    }

    /** @param  array<string, array{count: int, reset: int}>  $tracked */
    private function remember(string $key, array $tracked, string $hash, int $now): void
    {
        $entry = $tracked[$hash] ?? ['count' => 0, 'reset' => $now + self::DECAY_SECONDS];
        $entry['count']++;
        unset($tracked[$hash]);

        // Bearer vừa hỏng đứng đầu, rồi sắp ổn định theo số lần giảm dần: cùng số lần thì bearer vừa
        // hỏng giữ chỗ, bearer cũ hơn bị bỏ trước.
        $tracked = [$hash => $entry] + $tracked;
        uasort($tracked, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $tracked = array_slice($tracked, 0, self::MAX_TRACKED_BEARERS, true);

        $this->cache->put($key, $tracked, max(1, max(array_column($tracked, 'reset')) - $now));
    }
}
