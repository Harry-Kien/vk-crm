<?php

namespace App\Actions\Mcp\Read\Concerns;

use App\Actions\Mcp\McpMatterScope;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * "Vụ này, nếu người gọi được thấy nó qua MCP" cho các tool nhận một `matter_id` (Task 11:
 * `list_matter_updates`, `get_checklist`, `list_documents`, và bộ lọc theo vụ của `list_deadlines`,
 * `list_client_requests`). Cùng hai bước với `ReadMatter` của Task 10: vụ trong tập `McpMatterScope`
 * (R3), RỒI `Gate::forUser($actor)->allows('view', $matter)` — đúng ability của trang vụ việc trên web.
 * Mọi nhánh không thấy (id không tồn tại, đội khác, hạn chế, `denied`, đã xoá, Gate từ chối) cho
 * `null`; tool đổi nó thành MỘT "Không tìm thấy".
 *
 * Vụ trả về đã nạp `team`: Gate trên vụ trả lời từ bộ nhớ (`Matter::isListableBy()`), và Action gắn
 * vụ này vào từng bản ghi con (`setRelation('matter', …)`) nên policy `view` của bản ghi con —
 * `canSeeMatter($user, $child->matter)` — cũng không truy vấn lại, không lazy-load dưới
 * `ClientPortalScope` của một phiên cổng lạ.
 */
trait FindsVisibleMatter
{
    protected function visibleMatter(McpMatterScope $scope, User $actor, int $matterId): ?Matter
    {
        /** @var Matter|null $matter */
        $matter = $scope->query($actor)->whereKey($matterId)->with('team')->first();

        return $matter !== null && Gate::forUser($actor)->allows('view', $matter) ? $matter : null;
    }
}
