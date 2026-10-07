<?php

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\McpRateLimits;
use App\Support\Mcp\ToolCallContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R8 (Task 8): nửa HTTP của rate limit `/mcp` [DC:187]. Quyết định "còn lượt hay không" nằm ở bước
 * gọi tool (`App\Mcp\Methods\CallCrmTool`, {@see McpRateLimits}), vì chỉ ở đó mới biết tool nào được
 * gọi (giới hạn riêng của `search`/`fetch` và tool ghi). Middleware này đọc câu trả lời ấy trong
 * {@see ToolCallContext} của request và dịch ra HTTP:
 *  - mọi `tools/call` có câu trả lời: `X-RateLimit-Limit`, `X-RateLimit-Remaining` của giới hạn đang
 *    chặt nhất;
 *  - đã vượt: mã HTTP 429 (thân vẫn là phản hồi JSON-RPC lỗi tiếng Việt của bước gọi tool),
 *    `Retry-After` và `X-RateLimit-Reset`.
 *
 * Đứng NGAY SAU `AuditToolCall` (`routes/ai.php`), nên chạy khi {@see ToolCallContext} của request còn
 * gắn trong container. Request không phải `tools/call` không có đối tượng đó và đi qua nguyên vẹn.
 */
class ThrottleMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $verdict = app()->bound(ToolCallContext::class) ? app(ToolCallContext::class)->rateLimit() : null;

        if ($verdict === null) {
            return $response;
        }

        $response->headers->set('X-RateLimit-Limit', (string) $verdict->limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $verdict->remaining);

        if ($verdict->exceeded) {
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $verdict->retryAfter);
            $response->headers->set('X-RateLimit-Reset', (string) (now()->getTimestamp() + $verdict->retryAfter));
        }

        return $response;
    }
}
