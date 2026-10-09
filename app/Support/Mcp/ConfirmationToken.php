<?php

namespace App\Support\Mcp;

use App\Models\User;
use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;

/**
 * Mã xác nhận hai bước của hai tool ghi nội bộ `create_deadline` và `log_communication` (kế hoạch M11,
 * R6). Không tin client: người dùng có thể đặt "Always allow", nên lời xác nhận phải đi qua SERVER.
 *
 * Lần gọi thứ nhất (không `confirmation_token`) kiểm đủ, không ghi gì, và trả bản xem trước kèm mã
 * {@see self::issue()}. Lần gọi thứ hai, cùng tham số và kèm mã: {@see self::verify()} rồi mới ghi.
 *
 * **Không trạng thái, ký HMAC-SHA256 bằng khoá suy từ `APP_KEY`** (luật `requestState` của MRTR:
 * toàn vẹn, gắn principal, có hạn [DC:787]). Mã gồm hai phần base64url nối bằng dấu chấm:
 * `<dữ liệu>.<chữ ký>`. Dữ liệu là JSON của năm thứ:
 *  - `u` — `users.id` của người sở hữu token OAuth lúc cấp; mã chỉ dùng được dưới đúng người này;
 *  - `t` — tên tool; mã của `log_communication` không dùng được cho `create_deadline`;
 *  - `h` — SHA-256 của tham số ĐÃ CHUẨN HOÁ ({@see self::parametersHash()}); đổi một tham số (hạn
 *    khác, tóm tắt khác, vụ khác) thì mã không khớp;
 *  - `j` — `jti` ngẫu nhiên 128 bit, chữ hex thường. Bảng `mcp_confirmations` (unique `jti`) giữ mã
 *    ĐÃ DÙNG và bản ghi nó tạo, nên gọi lại cùng mã trả đúng bản ghi đó;
 *  - `e` — hạn, Unix giây: {@see self::TTL_SECONDS} sau lúc cấp.
 *
 * Chữ ký được so trên CHUỖI base64url đã mã hoá, bằng `hash_equals`, không trên byte đã giải mã:
 * chữ ký 32 byte là 43 ký tự base64url mà ký tự cuối chỉ mang 4 bit dữ liệu, nên hai ký tự cuối khác
 * nhau có thể giải mã ra cùng byte. So chuỗi thì sửa BẤT KỲ ký tự nào cũng bị từ chối. Chữ ký phủ
 * nguyên chuỗi dữ liệu như client gửi, nên phần dữ liệu cũng vậy.
 *
 * Mọi lý do từ chối — sai định dạng, sai chữ ký, người khác, tool khác, tham số khác, quá hạn — cho
 * cùng một kết quả `null`: tool trả một câu duy nhất, không câu nào giúp dò lý do.
 *
 * `jti` là chữ hex THƯỜNG do chính lớp này sinh và nằm trong phần đã ký, nên hai mã không bao giờ có
 * hai `jti` chỉ khác hoa thường: cột `mcp_confirmations.jti` (collation `utf8mb4_unicode_ci` trên
 * MariaDB, phân biệt hoa thường trên SQLite) cho cùng một câu trả lời ở hai CSDL (rà soát Task 7, m4).
 *
 * Đồng hồ là `CarbonImmutable::now()` của ứng dụng, nên test du hành thời gian được.
 */
final class ConfirmationToken
{
    /** Hạn của mã: 10 phút (R6). */
    public const TTL_SECONDS = 600;

    /** Trần độ dài của tham số `confirmation_token` (mã thật dài khoảng 230 ký tự). */
    public const MAX_LENGTH = 512;

    private const VERSION = 1;

    private const JTI_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * Cấp mã cho lần gọi thứ hai.
     *
     * @param  array<string, mixed>  $parameters  tham số ĐÃ CHUẨN HOÁ của tool
     * @return array{token: string, jti: string, expires_at: CarbonImmutable}
     */
    public static function issue(User $user, string $tool, array $parameters): array
    {
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);
        $jti = bin2hex(random_bytes(16));

        $payload = self::base64url(self::json([
            'v' => self::VERSION,
            'u' => (int) $user->getKey(),
            't' => $tool,
            'h' => self::parametersHash($parameters),
            'j' => $jti,
            'e' => $expiresAt->getTimestamp(),
        ]));

        return [
            'token' => $payload.'.'.self::sign($payload),
            'jti' => $jti,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * `jti` của mã khi mã hợp lệ cho đúng người, đúng tool, đúng tham số và còn hạn; ngược lại `null`.
     *
     * @param  array<string, mixed>  $parameters  tham số ĐÃ CHUẨN HOÁ của lần gọi này
     */
    public static function verify(?string $token, User $user, string $tool, array $parameters): ?string
    {
        if ($token === null || strlen($token) > self::MAX_LENGTH
            || preg_match('/\A([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $token, $parts) !== 1) {
            return null;
        }

        [, $payload, $signature] = $parts;

        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        try {
            $claims = is_string($decoded) ? json_decode($decoded, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        $valid = is_array($claims)
            && ($claims['v'] ?? null) === self::VERSION
            && ($claims['u'] ?? null) === (int) $user->getKey()
            && ($claims['t'] ?? null) === $tool
            && is_string($claims['h'] ?? null) && hash_equals($claims['h'], self::parametersHash($parameters))
            && is_string($claims['j'] ?? null) && preg_match(self::JTI_PATTERN, $claims['j']) === 1
            && is_int($claims['e'] ?? null) && $claims['e'] > CarbonImmutable::now()->getTimestamp();

        return $valid ? $claims['j'] : null;
    }

    /**
     * SHA-256 của tham số chuẩn hoá: khoá xếp theo thứ tự (đệ quy), JSON không thoát Unicode hay `/`.
     * Hai lần gọi gửi cùng tham số theo thứ tự khác nhau cho cùng một băm.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function parametersHash(array $parameters): string
    {
        return hash('sha256', self::json(self::sorted($parameters)));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }

    private static function sign(string $payload): string
    {
        return self::base64url(hash_hmac('sha256', $payload, self::key(), true));
    }

    /**
     * Khoá ký riêng cho mã xác nhận, suy từ `APP_KEY` bằng HMAC với một nhãn cố định: một chữ ký ở
     * đây không bao giờ là chữ ký hợp lệ ở chỗ khác dùng `APP_KEY` (cookie, URL ký, mã hoá).
     */
    private static function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY trống: không ký được mã xác nhận của tool MCP.');
        }

        return hash_hmac('sha256', 'vkcrm.mcp.confirmation-token.v1', $appKey, true);
    }

    /** @param  array<array-key, mixed>  $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
