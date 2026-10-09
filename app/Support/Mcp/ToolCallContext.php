<?php

namespace App\Support\Mcp;

use App\Enums\McpToolOutcome;
use App\Mcp\Tools\Concerns\CrmTool;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Trạng thái của MỘT lần `tools/call` trên máy chủ MCP (M11 R8, Task 8): correlation id, đồng hồ, tool,
 * tham số và kết quả ĐÃ LỌC cho nhật ký ({@see ToolAuditFields}), kết cục ({@see McpToolOutcome}) và
 * câu trả lời của rate limit ({@see McpRateLimitVerdict}).
 *
 * **Một đối tượng mỗi request, không static** (luật của Task 14): middleware
 * `App\Http\Middleware\Mcp\AuditToolCall` dựng nó khi thân request là một `tools/call`
 * ({@see self::isToolCall()}), gắn vào container trong lúc request chạy rồi gỡ ra; bước gọi tool
 * (`App\Mcp\Methods\CallCrmTool`, `CrmToolInvoker`) điền vào; `ThrottleMcp` đọc rate limit để đặt
 * header; `AuditToolCall` ghi đúng một dòng `mcp_tool_called` từ nó
 * (`App\Actions\Mcp\RecordMcpToolCall`). Không có middleware (helper `Server::tool()` của laravel/mcp)
 * thì bước gọi tool dùng một đối tượng rời mà không ai ghi — helper đó không phải bằng chứng.
 *
 * Chỉ giữ thứ được phép vào nhật ký: tên tool chỉ khi tool đó có trên máy chủ (tên lạ chỉ còn độ
 * dài), tham số và kết quả chỉ ở dạng đã lọc. Không giữ request hay response thô.
 */
final class ToolCallContext
{
    /** `properties.channel` của mọi dòng `mcp_tool_called` — trang Nhật ký hệ thống lọc theo giá trị này. */
    public const CHANNEL = 'mcp';

    public readonly string $correlationId;

    private readonly int $startedAt;

    private ?string $tool = null;

    private ?int $toolNameLength = null;

    private bool $writes = false;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private int $unknownArguments = 0;

    private ?McpToolOutcome $outcome = null;

    /** @var array{ids: list<string>, ids_truncated: bool, count: int, fields: list<string>} */
    private array $result = ['ids' => [], 'ids_truncated' => false, 'count' => 0, 'fields' => []];

    private ?McpRateLimitVerdict $rateLimit = null;

    public function __construct()
    {
        $this->correlationId = (string) Str::uuid();
        $this->startedAt = hrtime(true);
    }

    /**
     * Thân request có phải một `tools/call` mà máy chủ sẽ xử lý không — đúng cách `Laravel\Mcp\Server::
     * handle()` đọc nó: JSON object có `id` (không có `id` là notification, không chạy gì) và `method`
     * là `tools/call`. laravel/mcp 1.0.1 không nhận lô (batch), nên một request là nhiều nhất một lần
     * gọi tool.
     */
    public static function isToolCall(Request $request): bool
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) && isset($body['id']) && ($body['method'] ?? null) === 'tools/call';
    }

    /**
     * Tên tool mà client gửi. Ghi nguyên tên chỉ khi máy chủ có khai tool đó (`$declared`, kể cả tool
     * không đăng ký cho người này — R13); tên khác (chuỗi tự do của client) chỉ còn độ dài.
     *
     * @param  list<string>  $declared
     */
    public function requested(mixed $name, array $declared): void
    {
        $this->tool = is_string($name) && in_array($name, $declared, true) ? $name : null;
        $this->toolNameLength = is_string($name) && $this->tool === null ? mb_strlen($name) : null;
    }

    /**
     * Tool đã tìm thấy và tham số của lần gọi, lọc theo `inputSchema` của tool
     * ({@see CrmTool::auditArguments()}).
     */
    public function resolved(CrmTool $tool, mixed $arguments): void
    {
        $this->tool = $tool->name();
        $this->toolNameLength = null;
        $this->writes = $tool->isWriteTool();

        ['arguments' => $this->arguments, 'unknown' => $this->unknownArguments] = $tool->auditArguments(is_array($arguments) ? $arguments : []);
    }

    public function outcome(McpToolOutcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    /**
     * Kết quả `tools/call` (mảng `result` của phản hồi JSON-RPC) khi bước gọi tool chưa tự đặt kết cục:
     *  - không lỗi: `ok`, cùng id, số bản ghi và tên trường của `structuredContent`;
     *  - lỗi mang đúng thông điệp "Không tìm thấy" duy nhất của R3 (`$notFoundMessage`): `not_found`;
     *  - lỗi khác: `invalid`.
     *
     * @param  array<string, mixed>  $result
     */
    public function settleResult(array $result, string $notFoundMessage): void
    {
        if ($this->outcome !== null) {
            return;
        }

        if (($result['isError'] ?? false) !== true) {
            $this->outcome = McpToolOutcome::Ok;
            $this->result = ToolAuditFields::result(is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : []);

            return;
        }

        $text = $result['content'][0]['text'] ?? null;

        $this->outcome = $text === $notFoundMessage ? McpToolOutcome::NotFound : McpToolOutcome::Invalid;
    }

    /**
     * Kết cục cho lần gọi mà bước gọi tool không đặt được (request bị dừng trước khi tới `tools/call`):
     *  - 401, 403: `denied` — `EnsureMcpAccess` từ chối người sở hữu token (công tắc `mcp.enabled` tắt,
     *    chưa cam kết đúng phiên bản chính sách, mất `matter.view`, `ai_access` về off…; rà soát Task 8,
     *    I3);
     *  - 5xx: `error`;
     *  - còn lại (máy chủ MCP từ chối thân request, ví dụ `_meta` sai): `invalid`.
     */
    public function settleStatus(int $status): void
    {
        $this->outcome ??= match (true) {
            $status === 401, $status === 403 => McpToolOutcome::Denied,
            $status >= 500 => McpToolOutcome::Error,
            default => McpToolOutcome::Invalid,
        };
    }

    /** Gộp câu trả lời của một nhóm giới hạn vào câu trả lời đã có ({@see McpRateLimitVerdict::tighter()}). */
    public function limited(?McpRateLimitVerdict $verdict): ?McpRateLimitVerdict
    {
        if ($verdict !== null) {
            $this->rateLimit = $this->rateLimit === null ? $verdict : $this->rateLimit->tighter($verdict);
        }

        if ($this->rateLimit?->exceeded) {
            $this->outcome = McpToolOutcome::RateLimited;
        }

        return $this->rateLimit;
    }

    public function rateLimit(): ?McpRateLimitVerdict
    {
        return $this->rateLimit;
    }

    public function writes(): bool
    {
        return $this->writes;
    }

    public function currentOutcome(): ?McpToolOutcome
    {
        return $this->outcome;
    }

    public function returnedCount(): int
    {
        return $this->result['count'];
    }

    /**
     * `properties` của dòng `mcp_tool_called`, trừ phần của kết nối (client OAuth, nền tảng) và của
     * request HTTP (IP) mà `App\Actions\Mcp\RecordMcpToolCall` thêm. Hai khoá chỉ có khi khác rỗng:
     * `tool_name_length` (tên tool lạ), `unknown_argument_count` (tham số ngoài `inputSchema`), và
     * `returned_ids_truncated` (quá {@see ToolAuditFields::MAX_RETURNED_IDS} id).
     *
     * @return array<string, mixed>
     */
    public function auditProperties(): array
    {
        return array_filter([
            'channel' => self::CHANNEL,
            'tool' => $this->tool,
            'tool_name_length' => $this->toolNameLength,
            'outcome' => ($this->outcome ?? McpToolOutcome::Error)->value,
            'arguments' => $this->arguments,
            'unknown_argument_count' => $this->unknownArguments ?: null,
            'returned_ids' => $this->result['ids'],
            'returned_ids_truncated' => $this->result['ids_truncated'] ?: null,
            'returned_count' => $this->result['count'],
            'returned_fields' => $this->result['fields'],
            'duration_ms' => intdiv(hrtime(true) - $this->startedAt, 1_000_000),
            'correlation_id' => $this->correlationId,
        ], fn (mixed $value, string $key): bool => $value !== null || $key === 'tool', ARRAY_FILTER_USE_BOTH);
    }
}
