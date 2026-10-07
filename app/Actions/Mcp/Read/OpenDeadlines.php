<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Mốc gần nhất" của MCP — MỘT định nghĩa cho `next_deadline` của `search_matters` và năm mốc của
 * `get_matter`: mốc CHƯA hoàn thành, chưa xoá mềm, hạn sớm nhất trước (nên mốc QUÁ HẠN đứng đầu —
 * việc gấp nhất), cùng hạn thì mốc tạo trước đứng trước.
 *
 * Chỉ mốc của vụ trong tập `McpMatterScope` của `$actor` (`constrain()`), kể cả khi nơi gọi đã chỉ
 * đưa vào id vụ lấy từ chính tập đó — hai lớp, không tin nơi gọi. Nhân sự xem được mốc của mọi vụ họ
 * xem được (`DeadlinePolicy::view` → `canSeeMatter`), nên không có điều kiện riêng từng mốc.
 * `responsible` được nạp sẵn cho `DeadlinePresenter`, kể cả tài khoản đã xoá mềm (người đã nghỉ việc
 * vẫn hiện tên, như tab "Mốc thời hạn", widget và `list_deadlines` của Task 11); quan hệ `matter` do
 * nơi gọi gắn (nó đã có vụ).
 */
final class OpenDeadlines
{
    public function __construct(private readonly McpMatterScope $scope) {}

    /**
     * @param  list<int>  $matterIds
     * @return Collection<int, Deadline>
     */
    public function forMatters(User $actor, array $matterIds, ?int $limit = null): Collection
    {
        if ($matterIds === []) {
            return new Collection;
        }

        return $this->scope->constrain(Deadline::query(), $actor)
            ->whereIn('deadlines.matter_id', $matterIds)
            ->where('deadlines.is_completed', false)
            ->orderBy('deadlines.due_date')
            ->orderBy('deadlines.id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->with(['responsible' => fn (BelongsTo $responsible) => $responsible->withTrashed()])
            ->get();
    }
}
