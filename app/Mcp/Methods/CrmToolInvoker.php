<?php

namespace App\Mcp\Methods;

use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use Generator;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Chạy MỘT tool đã tìm thấy cho {@see CallCrmTool} (M11 Task 6), với hai lần kiểm trước khi gọi
 * `handle()` của tool. Không đạt thì trả một kết quả tool `isError: true` mang câu tiếng Việt, và
 * `handle()` không chạy:
 *
 * 1. **Tool phải kế thừa {@see CrmTool}.** Chỉ lớp đó nói được tool đọc hay ghi (`writes()`) và mang
 *    các luật R13/R14; một tool không qua nó thì không biết nó làm gì, nên không chạy.
 * 2. **R13 — handler vẫn kiểm lại quyền ghi.** Tool ghi chỉ chạy khi người sở hữu token ghi được qua
 *    MCP ở chính request này ({@see McpAccess::canWrite()}: `read_write`, công tắc `mcp.write_enabled`).
 *    `CrmTool::shouldRegister()` đã giấu tool ghi khỏi người không ghi được, nên bình thường lần gọi
 *    dừng ở "not found" trước khi tới đây; lần kiểm này là lớp thứ hai, cho một tool lỡ tự nới
 *    `shouldRegister()`.
 *
 * Người dùng đọc từ guard `mcp`, tường minh.
 */
class CrmToolInvoker extends ToolInvoker
{
    public function invoke(Tool $tool, JsonRpcRequest $request): Generator|JsonRpcResponse
    {
        if (! $tool instanceof CrmTool) {
            return $this->refuse($tool, $request, __('ai_access.tools.unavailable'));
        }

        if ($tool->isWriteTool()) {
            $user = Auth::guard('mcp')->user();

            if (! $user instanceof User || ! McpAccess::canWrite($user)) {
                return $this->refuse($tool, $request, __('ai_access.tools.write_refused'));
            }
        }

        return parent::invoke($tool, $request);
    }

    private function refuse(Tool $tool, JsonRpcRequest $request, string $message): JsonRpcResponse
    {
        return $this->toJsonRpcResponse($request, Response::error($message), $this->serializable($tool));
    }
}
