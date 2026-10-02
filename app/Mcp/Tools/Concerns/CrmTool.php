<?php

namespace App\Mcp\Tools\Concerns;

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
 * và `handle()`.
 */
abstract class CrmTool extends Tool
{
    /** `true` cho bốn tool ghi của R5, `false` cho mọi tool đọc. */
    abstract protected function writes(): bool;

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
