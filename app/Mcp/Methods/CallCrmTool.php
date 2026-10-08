<?php

namespace App\Mcp\Methods;

use App\Enums\McpToolOutcome;
use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use App\Support\Mcp\McpRateLimits;
use App\Support\Mcp\ToolCallContext;
use Generator;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use ReflectionProperty;

/**
 * `tools/call` của {@see CrmServer} (M11 Task 6; đăng ký ở `CrmServer::boot()`): ĐIỂM DUY NHẤT mọi lần
 * gọi tool đi qua, nên mọi luật áp cho "một lần gọi tool" nằm ở đây hoặc ở {@see CrmToolInvoker}, không
 * ở từng tool — tool của Task 10/11/13 thừa hưởng mà không viết thêm dòng nào.
 *
 * Thứ tự (Task 8 thêm rate limit và phần audit, R8):
 *  1. Ghi tên tool được yêu cầu vào {@see ToolCallContext} (nguyên tên chỉ khi máy chủ có khai tool
 *     đó, kể cả tool không đăng ký cho người này; tên lạ chỉ còn độ dài).
 *  2. Giới hạn CHUNG của mọi lần gọi ({@see McpRateLimits::general()}): hết lượt thì dừng, kể cả với
 *     tên tool không tồn tại — một vòng gọi tên rác không thoát được giới hạn (mỗi lần vẫn là một dòng
 *     nhật ký).
 *  3. Tìm tool giống hệt `CallTool` của laravel/mcp 1.0.1 (thiếu `name`: -32602; tên không có trong
 *     danh sách ĐÃ LỌC theo `shouldRegister()` của request này: -32602 "not found", kết cục
 *     `invalid`). Ngoại lệ (Task 13, rà soát Task 6 m5): tên là một tool GHI máy chủ có khai nhưng không
 *     đăng ký cho người này (người `read`, công tắc ghi tắt — R13) → kết quả tool `isError` mang câu
 *     tiếng Việt `ai_access.tools.write_refused` qua HTTP 200 ({@see CrmToolInvoker::refuseHiddenWrite()}),
 *     kết cục `denied`; tool không chạy.
 *  4. Tham số đã lọc theo allowlist của tool ({@see CrmTool::auditArguments()}).
 *  5. Giới hạn RIÊNG của tool ({@see McpRateLimits::forTool()}: `search`/`fetch`, tool ghi).
 *  6. Gọi qua {@see CrmToolInvoker} thay cho `ToolInvoker` của gói. Lớp cha tự `new ToolInvoker`, nên
 *     không mượn được `parent::handle()`.
 *
 * Vượt giới hạn: phản hồi JSON-RPC lỗi mang câu tiếng Việt, tool không chạy; middleware
 * `App\Http\Middleware\Mcp\ThrottleMcp` đổi mã HTTP thành 429 và đặt `Retry-After`, `X-RateLimit-*`.
 * Dòng `mcp_tool_called` do middleware `App\Http\Middleware\Mcp\AuditToolCall` ghi sau khi có phản
 * hồi, nên MỌI nhánh ở trên — kể cả hai nhánh ném -32602 — có đúng một dòng.
 *
 * Người dùng đọc từ guard `mcp`, tường minh. Không có người (chỉ xảy ra ngoài route `/mcp`, ví dụ
 * helper `Server::tool()` của laravel/mcp) thì không có khoá để đếm, và rate limit không áp.
 */
final class CallCrmTool extends CallTool
{
    /** Mã lỗi JSON-RPC của lần gọi bị chặn (dải "server error" -32000…-32099 của JSON-RPC 2.0). */
    public const RATE_LIMITED_CODE = -32029;

    /**
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $call = app()->bound(ToolCallContext::class) ? app(ToolCallContext::class) : new ToolCallContext;
        $limits = app(McpRateLimits::class);
        $user = Auth::guard('mcp')->user();
        $name = $request->get('name');
        $declaredTools = $this->declaredTools($context);
        $declared = array_keys($declaredTools);

        $call->requested($name, $declared);

        if ($user instanceof User && $call->limited($limits->attempt($limits->general($user)))?->exceeded) {
            return $this->throttled($request, $call);
        }

        if (is_null($name)) {
            $call->outcome(McpToolOutcome::Invalid);

            throw new JsonRpcException('Missing [name] parameter.', -32602, $request->id);
        }

        $tool = $context->tools()->first(fn (Tool $tool): bool => $tool->name() === $name);

        if (! $tool instanceof Tool) {
            $hidden = is_string($name) ? ($declaredTools[$name] ?? null) : null;

            // M11 Task 13 (rà soát Task 6, m5): tool GHI có khai nhưng không đăng ký cho người này
            // (người `read`, công tắc ghi tắt, client còn giữ danh sách cũ) → câu từ chối tiếng Việt,
            // HTTP 200, kết cục `denied`; tool không chạy, không đếm vào giới hạn riêng của tool ghi.
            if ($hidden instanceof CrmTool && $hidden->isWriteTool()) {
                $call->resolved($hidden, $request->get('arguments', []));

                return app()->make(CrmToolInvoker::class, ['call' => $call])->refuseHiddenWrite($hidden, $request);
            }

            $call->outcome(in_array($name, $declared, true) ? McpToolOutcome::Denied : McpToolOutcome::Invalid);

            $shown = is_scalar($name) ? (string) $name : get_debug_type($name);

            throw new JsonRpcException("Tool [{$shown}] not found.", -32602, $request->id);
        }

        if ($tool instanceof CrmTool) {
            $call->resolved($tool, $request->get('arguments', []));

            if ($user instanceof User && $call->limited($limits->attempt($limits->forTool($tool, $user)))?->exceeded) {
                return $this->throttled($request, $call);
            }
        }

        return app()->make(CrmToolInvoker::class, ['call' => $call])->invoke($tool, $request);
    }

    private function throttled(JsonRpcRequest $request, ToolCallContext $call): JsonRpcResponse
    {
        return JsonRpcResponse::error($request->id, self::RATE_LIMITED_CODE, __('mcp_audit.rate_limited'), [
            'retry_after' => $call->rateLimit()?->retryAfter,
        ]);
    }

    /**
     * Mọi tool máy chủ KHAI (`Server::$tools`) theo tên, kể cả tool mà `shouldRegister()` loại khỏi
     * request này. `ServerContext` chỉ trả danh sách đã lọc ({@see ServerContext::tools()}) và giữ danh
     * sách gốc ở một thuộc tính `protected`; đọc nó là cách duy nhất phân biệt "tool ghi bị giấu với
     * người này" (`denied`, câu từ chối tiếng Việt) với "tên không có" (`invalid`) mà không khai lại
     * danh sách tool lần thứ hai.
     *
     * @return array<string, Tool>
     */
    private function declaredTools(ServerContext $context): array
    {
        $declared = (new ReflectionProperty(ServerContext::class, 'tools'))->getValue($context);

        return collect(is_array($declared) ? $declared : [])
            ->flatten()
            ->map(fn (mixed $tool): mixed => is_string($tool) && is_subclass_of($tool, Tool::class) ? app($tool) : $tool)
            ->filter(fn (mixed $tool): bool => $tool instanceof Tool)
            ->keyBy(fn (Tool $tool): string => $tool->name())
            ->all();
    }
}
