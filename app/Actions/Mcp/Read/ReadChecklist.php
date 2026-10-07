<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\FindsVisibleMatter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho tool `get_checklist` (kế hoạch M11, bảng tool 8 [DC:54]): danh mục hồ sơ của MỘT vụ.
 *
 * - **Vụ**: {@see FindsVisibleMatter} (`McpMatterScope` rồi Gate `view`); không thấy thì `null`.
 * - **Mục**: mọi mục chưa xoá mềm của vụ, theo `sort_order` rồi `id` — thứ tự của tab "Danh mục hồ
 *   sơ" (`Matter::checklistItems()`), `id` làm khoá phụ cho hai mục cùng thứ tự. Mỗi mục qua
 *   `MatterChecklistItemPolicy::view` (bảng tool); với nhân sự nó là `canSeeMatter`, hôm nay không bỏ
 *   mục nào. Không phân trang: một danh mục là một phần của MỘT vụ, như các bên hay đội ngũ của
 *   `get_matter`, không phải một danh sách xuyên vụ.
 * - **Tài liệu đã gắn**: chỉ để presenter ĐẾM (nó tự loại nhóm D và nhóm không rõ, R4). Nạp đúng ba
 *   cột `id`, `matter_checklist_item_id`, `group` — tiêu đề và tên tệp không bao giờ được đọc lên, nên
 *   không thể lọt ra. Bỏ scope cổng: dưới một phiên cổng lạ, scope đó chỉ để lại tài liệu đã công bố
 *   và con số sẽ thiếu, im lặng. Tài liệu đã xoá mềm không tính (scope xoá mềm vẫn chạy).
 * - **"Đã nộp X/Y"**: CHÍNH `ChecklistProgress` của web, cổng khách và `get_matter` — một định nghĩa.
 *
 * Mỗi mục được gắn chính vụ đã nạp `team`, nên policy không lazy-load vụ cha dưới scope cổng.
 */
final class ReadChecklist
{
    use FindsVisibleMatter;

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly ChecklistProgress $progress,
    ) {}

    public function handle(User $actor, int $matterId): ?MatterChecklist
    {
        $matter = $this->visibleMatter($this->scope, $actor, $matterId);

        if ($matter === null) {
            return null;
        }

        $items = $this->scope->constrain(MatterChecklistItem::query(), $actor)
            ->where('matter_checklist_items.matter_id', $matter->getKey())
            ->orderBy('matter_checklist_items.sort_order')
            ->orderBy('matter_checklist_items.id')
            ->with(['documents' => fn (HasMany $documents) => $documents
                ->withoutGlobalScope(ClientPortalScope::class)
                ->select(['documents.id', 'documents.matter_checklist_item_id', 'documents.group']),
            ])
            ->get()
            ->each(fn (MatterChecklistItem $item) => $item->setRelation('matter', $matter))
            ->filter(fn (MatterChecklistItem $item): bool => Gate::forUser($actor)->allows('view', $item))
            ->values()
            ->all();

        ['submitted' => $submitted, 'total' => $total] = $this->progress->handle($matter);

        return new MatterChecklist($matter, $items, $submitted, $total);
    }
}
