<?php

namespace App\Support\Mcp;

use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Laravel\Passport\AccessToken;

/**
 * Giới hạn số lần gọi tool của máy chủ MCP (M11 R8, Task 8; SPEC §10.3 "API 60 request/phút", chuyển
 * từ kế hoạch M8 dòng 155) — MỘT chỗ khai con số và khoá, cho bước gọi tool chung
 * (`App\Mcp\Methods\CallCrmTool`). Tool không tự khai giới hạn: tool mới của Task 10/11/13 thừa hưởng.
 *
 * Mỗi giới hạn có HAI khoá độc lập, theo NGƯỜI (`users.id`) và theo ACCESS TOKEN (`jti`) [DC:182-185]:
 *  - mọi `tools/call` (kể cả tên tool không tồn tại): {@see self::PER_MINUTE} lần một phút;
 *  - `search`, `fetch` ({@see self::SEARCH_TOOLS}), mỗi tool: {@see self::SEARCH_PER_MINUTE} lần một phút;
 *  - tool ghi (`CrmTool::isWriteTool()`), chung cho cả bốn: {@see self::WRITE_PER_MINUTE} lần một phút
 *    và {@see self::WRITE_PER_DAY} lần một ngày.
 * Hai token của cùng một người dùng chung khoá theo người, nên đổi token (làm mới, kết nối thêm một
 * nền tảng) không cho thêm lượt. Với cùng con số, khoá theo token không bao giờ chặt hơn khoá theo
 * người; nó ở đây vì R8 đòi hai loại khoá, và để giới hạn theo token còn đúng nếu sau này hai con số
 * tách nhau.
 *
 * Bộ đếm là `Illuminate\Cache\RateLimiter` của app, trên cache MẶC ĐỊNH (`cache.limiter`, không có thì
 * `cache.default`): store `database` ở máy thật (`.env.example` `CACHE_STORE=database`). Đừng đổi sang
 * `array` ở máy thật: dưới PHP-FPM bộ đếm về 0 ở mỗi request, và test vẫn xanh.
 *
 * Lần bị chặn KHÔNG tính vào lượt: {@see self::attempt()} hỏi mọi khoá trước, rồi mới đếm, và chỉ đếm
 * khi không khoá nào đã hết lượt.
 */
final class McpRateLimits
{
    public const PER_MINUTE = 60;

    public const SEARCH_PER_MINUTE = 30;

    public const SEARCH_TOOLS = ['search', 'fetch'];

    public const WRITE_PER_MINUTE = 10;

    public const WRITE_PER_DAY = 100;

    private const MINUTE = 60;

    private const DAY = 86400;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Giới hạn chung của mọi `tools/call`.
     *
     * @return list<array{key: string, max: int, decay: int}>
     */
    public function general(User $user): array
    {
        return $this->buckets('all', self::PER_MINUTE, self::MINUTE, $user);
    }

    /**
     * Giới hạn riêng của MỘT tool, chồng lên {@see self::general()}: rỗng với tool đọc thường.
     *
     * @return list<array{key: string, max: int, decay: int}>
     */
    public function forTool(CrmTool $tool, User $user): array
    {
        if ($tool->isWriteTool()) {
            return [
                ...$this->buckets('write', self::WRITE_PER_MINUTE, self::MINUTE, $user),
                ...$this->buckets('write-day', self::WRITE_PER_DAY, self::DAY, $user),
            ];
        }

        if (in_array($tool->name(), self::SEARCH_TOOLS, true)) {
            return $this->buckets('tool-'.$tool->name(), self::SEARCH_PER_MINUTE, self::MINUTE, $user);
        }

        return [];
    }

    /**
     * Hỏi mọi khoá; khoá nào đã hết lượt thì trả "đã vượt" (giữ khoá phải chờ lâu nhất) và không đếm
     * gì. Không khoá nào hết lượt thì đếm một lần ở MỌI khoá, rồi trả khoá còn ít lượt nhất. Danh sách
     * rỗng: `null`.
     *
     * @param  list<array{key: string, max: int, decay: int}>  $buckets
     */
    public function attempt(array $buckets): ?McpRateLimitVerdict
    {
        if ($buckets === []) {
            return null;
        }

        $blocked = null;

        foreach ($buckets as $bucket) {
            if (! $this->limiter->tooManyAttempts($bucket['key'], $bucket['max'])) {
                continue;
            }

            $verdict = new McpRateLimitVerdict(true, $bucket['max'], 0, max(1, $this->limiter->availableIn($bucket['key'])));

            if ($blocked === null || $verdict->retryAfter > $blocked->retryAfter) {
                $blocked = $verdict;
            }
        }

        if ($blocked !== null) {
            return $blocked;
        }

        $tightest = null;

        foreach ($buckets as $bucket) {
            $this->limiter->hit($bucket['key'], $bucket['decay']);

            $verdict = new McpRateLimitVerdict(false, $bucket['max'], $this->limiter->remaining($bucket['key'], $bucket['max']));
            $tightest = $tightest === null ? $verdict : $tightest->tighter($verdict);
        }

        return $tightest;
    }

    /** @return list<array{key: string, max: int, decay: int}> */
    private function buckets(string $name, int $max, int $decay, User $user): array
    {
        $buckets = [['key' => "mcp:{$name}:user:{$user->getKey()}", 'max' => $max, 'decay' => $decay]];

        $token = $user->currentAccessToken();

        if ($token instanceof AccessToken && is_string($token->oauth_access_token_id) && $token->oauth_access_token_id !== '') {
            $buckets[] = ['key' => "mcp:{$name}:token:{$token->oauth_access_token_id}", 'max' => $max, 'decay' => $decay];
        }

        return $buckets;
    }
}
