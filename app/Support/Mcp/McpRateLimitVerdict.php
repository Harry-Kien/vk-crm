<?php

namespace App\Support\Mcp;

/**
 * Câu trả lời của {@see McpRateLimits::attempt()} cho MỘT lần gọi tool (M11 R8, Task 8), và là thứ
 * middleware `App\Http\Middleware\Mcp\ThrottleMcp` dịch ra header HTTP:
 *  - `exceeded`: một giới hạn đã hết lượt — HTTP 429, tool không chạy;
 *  - `limit` / `remaining`: giới hạn đang CHẶT nhất (`X-RateLimit-Limit` / `X-RateLimit-Remaining`);
 *  - `retryAfter`: số giây tới khi giới hạn đã hết lượt mở lại (`Retry-After`), 0 khi chưa vượt.
 */
final readonly class McpRateLimitVerdict
{
    public function __construct(
        public bool $exceeded,
        public int $limit,
        public int $remaining,
        public int $retryAfter = 0,
    ) {}

    /**
     * Gộp với câu trả lời của một nhóm giới hạn thứ hai (giới hạn chung rồi giới hạn riêng của tool):
     * nhóm nào đã vượt thì thắng; không nhóm nào vượt thì giữ nhóm còn ít lượt hơn.
     */
    public function tighter(self $other): self
    {
        if ($other->exceeded || $this->exceeded) {
            return $this->exceeded ? $this : $other;
        }

        return $other->remaining < $this->remaining ? $other : $this;
    }
}
