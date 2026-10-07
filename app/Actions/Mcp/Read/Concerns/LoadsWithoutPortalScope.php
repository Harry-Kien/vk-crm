<?php

namespace App\Actions\Mcp\Read\Concerns;

use App\Support\Scopes\ClientPortalScope;
use Closure;

/**
 * Nạp quan hệ cho presenter MCP mà KHÔNG mang `ClientPortalScope` của model đích.
 *
 * `withoutGlobalScope()` trên truy vấn cha không lan xuống `with()`/`load()`: mỗi model được nạp kèm
 * (`Client`, `MatterParty`, `ClientRequestReply`…) tự áp scope cổng khách của nó (rà soát Task 9,
 * m2). Request `/mcp` thật không có phiên cổng, nhưng một phiên cổng đang mở trong cùng tiến trình
 * (`ClientPortalScope::isActive()`) sẽ lặng lẽ trả `null` hay một tập thiếu cho khách hàng, các bên,
 * trả lời — đúng loại lệch "im lặng" mà `McpMatterScope` đã gỡ ở truy vấn vụ việc. Quyền đã được hỏi
 * trên `$actor` (tập R3); câu trả lời đó là câu duy nhất quyết định.
 */
trait LoadsWithoutPortalScope
{
    /**
     * `['client' => fn ($query) => …, 'parties' => …]` cho `with()` / `load()`.
     *
     * @return array<string, Closure>
     */
    protected function withoutPortalScope(string ...$relations): array
    {
        $constraint = fn ($query) => $query->withoutGlobalScope(ClientPortalScope::class);

        return array_fill_keys($relations, $constraint);
    }
}
