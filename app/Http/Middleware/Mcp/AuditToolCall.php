<?php

namespace App\Http\Middleware\Mcp;

use App\Actions\Mcp\RecordMcpToolCall;
use App\Models\User;
use App\Support\Mcp\ToolCallContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * M11 R8 (Task 8): mỗi `tools/call` tới được máy chủ MCP sinh ĐÚNG MỘT dòng `mcp_tool_called`, kể cả
 * lần bị từ chối hay bị chặn vì rate limit.
 *
 * Đứng sau `EnsureMcpAccess` (`routes/ai.php`): chỉ request đã xác thực, của một người được dùng máy
 * chủ, mới tới đây — request 401 không có người để ghi (và đã có bộ đếm theo IP,
 * {@see ThrottleMcpAuthenticationFailures}). Thân request không phải `tools/call`
 * ({@see ToolCallContext::isToolCall()}: `initialize`, `tools/list`, `ping`, notification) thì đi
 * thẳng, không ghi gì.
 *
 * Với một `tools/call`: dựng một {@see ToolCallContext} mới, gắn vào container cho bước gọi tool
 * (`App\Mcp\Methods\CallCrmTool`) điền, chạy request, gỡ khỏi container dù request ra sao, rồi ghi
 * dòng nhật ký ({@see RecordMcpToolCall}) với:
 *  - causer = người sở hữu token (guard `mcp`), truyền TƯỜNG MINH — trong request `/mcp`, `auth('web')`
 *    và `auth('client')` rỗng hoặc là người khác (Review Focus 4);
 *  - client OAuth của chính token (guard `mcp`);
 *  - IP theo `$request->ip()`, tức sau `TrustProxies` của M8 (`TRUSTED_PROXIES`).
 * Kết cục mà bước gọi tool chưa đặt (máy chủ từ chối request trước khi tới `tools/call`) suy từ mã
 * HTTP ({@see ToolCallContext::settleStatus()}).
 *
 * Ghi SAU khi có phản hồi, ngoài mọi transaction của tool: một dòng nhật ký không bao giờ bị cuộn
 * lại cùng một lần ghi hỏng, và lần gọi ném lỗi vẫn có dòng của nó.
 */
class AuditToolCall
{
    public function __construct(private readonly RecordMcpToolCall $record) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ToolCallContext::isToolCall($request)) {
            return $next($request);
        }

        $call = new ToolCallContext;
        app()->instance(ToolCallContext::class, $call);

        try {
            $response = $next($request);
        } finally {
            app()->forgetInstance(ToolCallContext::class);
        }

        $call->settleStatus($response->getStatusCode());

        $guard = Auth::guard('mcp');
        $user = $guard->user();

        if ($user instanceof User) {
            $client = method_exists($guard, 'client') ? $guard->client() : null;

            $this->record->handle($user, $client instanceof Client ? $client : null, $call, $request->ip());
        }

        return $response;
    }
}
