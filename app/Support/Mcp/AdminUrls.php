<?php

namespace App\Support\Mcp;

use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * URL TUYỆT ĐỐI về trang `/admin` tương ứng cho mỗi kết quả của tool MCP (kế hoạch M11, "Quy ước
 * chung"; hợp đồng `search`/`fetch` của ChatGPT đòi `url` tuyệt đối, không rỗng [DC:644]). Trang
 * đích tự kiểm quyền — URL không mở thêm gì cho ai.
 *
 * Mốc, yêu cầu từ khách, tài liệu, danh mục hồ sơ và dòng tiến độ không có resource riêng: chúng là
 * TAB của trang vụ việc, chọn bằng `?relation=<khoá của tab>` (Filament 5). Khoá đó là VỊ TRÍ của
 * relation manager trong `MatterResource::getRelations()` và được tra ngược ở mỗi lần gọi — cùng cách
 * với `App\Mail\Staff\InstalmentOverdue` — nên một tab mới chèn vào giữa không làm liên kết trỏ sai
 * tab. Tab biến mất thì ném lỗi, không âm thầm trả URL không có tab.
 *
 * Tài liệu trỏ về TAB Tài liệu, không bao giờ về route tải tệp `documents.download` hay một URL ký:
 * nội dung tệp không rời hệ thống qua MCP (R4).
 *
 * Lớp này ở `App\Support\Mcp`, không ở `App\Actions\Mcp`: nó dùng `MatterResource` (Filament), mà
 * `App\Actions` không được phụ thuộc Filament (`ArchitectureTest`). Action đọc trả DTO không có URL;
 * presenter gắn URL ở đây.
 */
final class AdminUrls
{
    public static function matter(Matter|int $matter): string
    {
        return MatterResource::getUrl('view', ['record' => self::key($matter)], panel: 'admin');
    }

    public static function stageLog(StageLog $log): string
    {
        return self::matterTab($log, StageLogsRelationManager::class);
    }

    public static function checklistItem(MatterChecklistItem $item): string
    {
        return self::matterTab($item, ChecklistRelationManager::class);
    }

    public static function document(Document $document): string
    {
        return self::matterTab($document, DocumentsRelationManager::class);
    }

    public static function clientRequest(ClientRequest $request): string
    {
        return self::matterTab($request, ClientRequestsRelationManager::class);
    }

    public static function deadline(Deadline $deadline): string
    {
        return self::matterTab($deadline, DeadlinesRelationManager::class);
    }

    /**
     * @param  class-string  $relationManager
     */
    private static function matterTab(Model $child, string $relationManager): string
    {
        $matterId = $child->getAttribute('matter_id');

        if ($matterId === null) {
            throw new LogicException('AdminUrls: bản ghi '.$child::class.' không mang matter_id.');
        }

        $tab = array_search($relationManager, MatterResource::getRelations(), true);

        if ($tab === false) {
            throw new LogicException("AdminUrls: trang vụ việc không còn tab {$relationManager}.");
        }

        return MatterResource::getUrl('view', ['record' => (int) $matterId, 'relation' => $tab], panel: 'admin');
    }

    private static function key(Matter|int $matter): int
    {
        return $matter instanceof Matter ? (int) $matter->getKey() : $matter;
    }
}
