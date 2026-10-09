<?php

namespace App\Mcp\Methods;

use App\Enums\McpToolOutcome;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\ToolCallContext;
use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * Chạy MỘT tool đã tìm thấy cho {@see CallCrmTool} (M11 Task 6), với hai lần kiểm trước khi gọi
 * `handle()` của tool. Không đạt thì trả một kết quả tool `isError: true` mang câu tiếng Việt, và
 * `handle()` không chạy:
 *
 * 1. **Tool phải kế thừa {@see CrmTool}.** Chỉ lớp đó nói được tool đọc hay ghi (`writes()`) và mang
 *    các luật R13/R14; một tool không qua nó thì không biết nó làm gì, nên không chạy.
 * 2. **R13 — handler vẫn kiểm lại quyền ghi.** Tool ghi chỉ chạy khi người sở hữu token ghi được qua
 *    MCP ở chính request này ({@see McpAccess::canWriteInRequest()}: `read_write`, công tắc
 *    `mcp.write_enabled` — câu trả lời `EnsureMcpAccess` đã tính một lần cho request này).
 *    `CrmTool::shouldRegister()` đã giấu tool ghi khỏi người không ghi được, nên bình thường lần gọi
 *    dừng ở `CallCrmTool` trước khi tới đây (câu từ chối tiếng Việt cho một tên tool ghi đã ẩn, Task
 *    13); lần kiểm này là lớp thứ hai, cho một tool lỡ tự nới `shouldRegister()`.
 *
 * Task 8 (R8): điền kết cục của lần gọi vào {@see ToolCallContext} của request —
 *  - hai lần từ chối ở trên: `denied`;
 *  - tool ném lỗi: kiểm tra tham số `invalid`, xác thực/phân quyền `denied`, còn lại `error` (thông
 *    điệp của lỗi không vào nhật ký);
 *  - tool trả kết quả: `ok` (kèm id, số bản ghi, tên trường), `not_found` khi kết quả là lỗi mang
 *    đúng thông điệp "Không tìm thấy" duy nhất của R3 ({@see self::NOT_FOUND_MESSAGE}), `invalid` với
 *    mọi lỗi khác ({@see ToolCallContext::settleResult()}).
 *
 * Người dùng đọc từ request HTTP `/mcp` đang chạy với guard `mcp` gọi tên (`request()->user('mcp')`),
 * tường minh, như {@see CallCrmTool}.
 */
class CrmToolInvoker extends ToolInvoker
{
    /**
     * Khoá dịch của thông điệp "Không tìm thấy" mà MỌI tool trả khi id không tồn tại, vụ ngoài tập MCP
     * thấy được, hay không có quyền (R3, SPEC §10.10). Khoá do Task 10 (`lang/vi/mcp.php`,
     * `tool_errors.not_found`) khai; bước gọi tool so thông điệp của kết quả với bản dịch của khoá này
     * để ghi `outcome = not_found` mà tool không phải tự khai gì.
     */
    public const NOT_FOUND_MESSAGE = 'mcp.tool_errors.not_found';

    public function __construct(private readonly ToolCallContext $call = new ToolCallContext) {}

    public function invoke(Tool $tool, JsonRpcRequest $request): Generator|JsonRpcResponse
    {
        if (! $tool instanceof CrmTool) {
            return $this->refuse($tool, $request, __('ai_access.tools.unavailable'));
        }

        if ($tool->isWriteTool()) {
            $user = request()->user('mcp');

            if (! $user instanceof User || ! McpAccess::canWriteInRequest($user)) {
                return $this->refuse($tool, $request, __('ai_access.tools.write_refused'));
            }
        }

        $response = parent::invoke($tool, $request);

        if ($response instanceof JsonRpcResponse) {
            $result = $response->content['result'] ?? [];

            $this->call->settleResult(is_array($result) ? $result : [], __(self::NOT_FOUND_MESSAGE));
        } else {
            // Tool trả Generator (không tool nào được làm vậy: SSE qua PHP-FPM bị đệm [PL:108]).
            $this->call->outcome(McpToolOutcome::Ok);
        }

        return $response;
    }

    /**
     * Như `InteractsWithResponses::callHandler()` của gói (lỗi của tool thành kết quả `isError`), cộng
     * kết cục của lỗi cho nhật ký.
     */
    protected function callHandler(callable $handler, JsonRpcRequest $request): mixed
    {
        try {
            return $handler();
        } catch (Throwable $throwable) {
            $this->call->outcome(match (true) {
                $throwable instanceof ValidationException => McpToolOutcome::Invalid,
                $throwable instanceof AuthenticationException,
                $throwable instanceof AuthorizationException => McpToolOutcome::Denied,
                default => McpToolOutcome::Error,
            });

            return $this->toErrorResponse($throwable);
        }
    }

    /**
     * Từ chối một tool ghi mà máy chủ có khai nhưng KHÔNG đăng ký cho người này ở request này (người
     * `read`, công tắc ghi tắt — R13): kết quả tool `isError` mang câu tiếng Việt
     * `ai_access.tools.write_refused` qua HTTP 200, kết cục `denied`; tool không chạy. Gọi từ
     * {@see CallCrmTool} (M11 Task 13, rà soát Task 6 m5): client còn giữ danh sách tool cũ [DC:87] và
     * gọi tên tool ghi thì nhận một câu nói rõ lý do và rằng thử lại vô ích, thay cho "Tool not found"
     * tiếng Anh qua HTTP 400 mà vài client coi là lỗi kết nối.
     */
    public function refuseHiddenWrite(CrmTool $tool, JsonRpcRequest $request): JsonRpcResponse
    {
        return $this->refuse($tool, $request, __('ai_access.tools.write_refused'));
    }

    private function refuse(Tool $tool, JsonRpcRequest $request, string $message): JsonRpcResponse
    {
        $this->call->outcome(McpToolOutcome::Denied);

        return $this->toJsonRpcResponse($request, Response::error($message), $this->serializable($tool));
    }
}
