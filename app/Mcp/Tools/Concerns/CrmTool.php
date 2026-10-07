<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\ToolAuditFields;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Laravel\Mcp\Server\Contracts\Annotation;
use Laravel\Mcp\Server\Tool;
use LogicException;
use ReflectionClass;

/**
 * Lớp cơ sở của mọi tool MCP của VK-CRM (kế hoạch M11, R14).
 *
 * laravel/mcp 1.0.1 chỉ xuất một hint khi tool có attribute tương ứng (`IsReadOnly`,
 * `IsDestructive`, …). Hint nào vắng thì client tự áp mặc định của đặc tả: `readOnlyHint` false,
 * `destructiveHint` true, `openWorldHint` true. ChatGPT coi tool thiếu `readOnlyHint` là tool ghi,
 * và OpenAI xem đó là lỗi chặn khi nộp [DC:32], [DC:636]. Gói cũng không bao giờ xuất
 * `annotations.title` [DC:777]. Lớp này sửa cả hai chỗ:
 *
 * - **Bốn hint luôn tường minh**, suy từ một câu hỏi duy nhất {@see self::writes()}:
 *   - tool đọc: `readOnlyHint` true, `destructiveHint` false, `idempotentHint` true,
 *     `openWorldHint` false;
 *   - tool ghi (bốn tool của R5): `readOnlyHint` false, ba hint còn lại như trên. Không có tool xoá
 *     (R5), và một lần gọi lặp lại trả đúng bản ghi đã tạo (R6, `idempotency_key` /
 *     `mcp_confirmations`).
 *   - Không tool nào `openWorldHint: true`, vì không tool nào đưa dữ liệu ra ngoài [DC:35].
 * - **`title` ở cả hai chỗ**: `Tool.title` và `annotations.title`.
 * - **Tiêu đề và mô tả tiếng Việt qua `lang/vi/mcp.php`**, khoá `mcp.tools.<name>.title` và
 *   `mcp.tools.<name>.description`. Thiếu khoá thì ném lỗi, để khoá dịch thô không bao giờ lọt tới
 *   client như một mô tả tool.
 * - **Không nhận attribute annotation của gói.** Một tool tự gắn `#[IsDestructive]` sẽ bị bỏ qua
 *   im lặng, vì `annotations()` dưới đây không đọc attribute. Ném lỗi thay vì im lặng: nguồn sự
 *   thật duy nhất là `writes()`.
 *
 * Tool con khai `protected string $name` (snake_case `[a-z0-9_]`, ≤ 64 ký tự [DC:30]), `writes()`
 * và `handle()`. Không khai `shouldRegister()`: lớp này quyết định tool ghi đăng ký cho ai (R13,
 * Task 6). Mọi lần gọi tool đi qua `App\Mcp\Methods\CallCrmTool` của `CrmServer` — chỗ duy nhất
 * kiểm lại quyền ghi, áp rate limit và chuẩn bị dòng audit (Task 8) cho mọi tool mà không cần mã
 * riêng trong tool nào; allowlist tham số của nhật ký suy từ `schema()` của tool
 * ({@see self::auditArguments()}).
 */
abstract class CrmTool extends Tool
{
    /** `true` cho bốn tool ghi của R5, `false` cho mọi tool đọc. */
    abstract protected function writes(): bool;

    /** {@see self::writes()} cho bên ngoài lớp (bước gọi tool `CrmToolInvoker`); con không khai lại. */
    final public function isWriteTool(): bool
    {
        return $this->writes();
    }

    /**
     * R13 (Task 6): tool ghi chỉ được ĐĂNG KÝ — có trong `tools/list`, và tìm thấy được ở
     * `tools/call` — cho người ghi được qua MCP ngay lúc này ({@see McpAccess::canWrite()}:
     * `read_write`, công tắc `mcp.write_enabled` bật). Người `read` không được mời gọi tool mà họ
     * không dùng được; gọi thẳng tên tool ghi thì laravel/mcp trả "không tìm thấy". Tool đọc luôn
     * đăng ký (`EnsureMcpAccess` đã chặn người không được dùng máy chủ).
     *
     * laravel/mcp hỏi hàm này ở mỗi request (`Primitive::eligibleForRegistration()`), nên hạ một
     * người về `read` có hiệu lực ngay request kế tiếp dù client còn giữ danh sách tool cũ [DC:87].
     * Người dùng đọc từ guard `mcp`, tường minh. Bước gọi tool vẫn kiểm lại quyền ghi
     * (`App\Mcp\Methods\CrmToolInvoker`): hàm này chỉ quyết định danh sách.
     */
    public function shouldRegister(): bool
    {
        if (! $this->writes()) {
            return true;
        }

        $user = Auth::guard('mcp')->user();

        return $user instanceof User && McpAccess::canWrite($user);
    }

    /**
     * Allowlist tham số của tool cho nhật ký `mcp_tool_called` (R8, Task 8), suy từ CHÍNH
     * `inputSchema` của tool ({@see ToolAuditFields::arguments()}): chỉ tham số có khai; id có tiền tố,
     * giá trị `enum` tool tự khai, ngày, số, cờ giữ nguyên; mọi văn bản tự do chỉ còn độ dài. Bước gọi
     * tool (`App\Mcp\Methods\CallCrmTool`) gọi hàm này cho MỌI tool, nên tool mới có allowlist ngay khi
     * khai `schema()`.
     *
     * Tool con có thể ghi đè để THU HẸP thêm (ví dụ thay một tham số enum bằng độ dài), không bao giờ để
     * nới: giá trị trả về đi thẳng vào `activity_log`, giữ ≥ 12 tháng.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array{arguments: array<string, mixed>, unknown: int}
     */
    public function auditArguments(array $arguments): array
    {
        $properties = $this->toArray()['inputSchema']['properties'] ?? [];

        return ToolAuditFields::arguments($arguments, is_array($properties) ? $properties : []);
    }

    public function title(): string
    {
        return $this->translated('title');
    }

    public function description(): string
    {
        return $this->translated('description');
    }

    /**
     * Thay hoàn toàn cách đọc attribute của `HasAnnotations::annotations()`; `Tool::toArray()` đặt
     * mảng này vào khoá `annotations`.
     *
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public function annotations(): array
    {
        foreach ((new ReflectionClass($this))->getAttributes() as $attribute) {
            if (is_a($attribute->getName(), Annotation::class, true)) {
                throw new LogicException(sprintf(
                    'Tool [%s] không được tự gắn attribute annotation [%s]: hint suy từ writes() của %s.',
                    $this->name(), $attribute->getName(), self::class,
                ));
            }
        }

        return [
            'title' => $this->title(),
            'readOnlyHint' => ! $this->writes(),
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    private function translated(string $field): string
    {
        $key = 'mcp.tools.'.$this->name().'.'.$field;

        if (! Lang::has($key)) {
            throw new LogicException("Tool MCP thiếu chuỗi [{$key}] trong lang/vi/mcp.php.");
        }

        return __($key);
    }
}
