<?php

namespace App\Actions\Mcp\Read;

/**
 * Vị trí của một dòng trong một danh sách MCP xếp theo (cột, `id`) — {@see KeysetOrder}. `sort` là giá
 * trị THÔ của cột sắp xếp đúng như CSDL trả (`2026-10-04`, `2026-10-04 09:30:00`), hoặc `null` khi cột
 * rỗng; so sánh lại với chính cột đó trên cùng CSDL nên không đổi định dạng. Tool dựng nó từ cursor đã
 * giải mã (`App\Support\Mcp\McpCursor::decodePosition()`), Action trả nó cho trang kế.
 */
final readonly class KeysetPosition
{
    public function __construct(
        public ?string $sort,
        public int $id,
    ) {}
}
