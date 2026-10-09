<?php

namespace App\Support\Mcp\Presenters;

use App\Enums\DocumentGroup;
use App\Models\Document;
use App\Models\MatterChecklistItem;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;
use Illuminate\Support\Collection;

/**
 * Một đầu mục danh mục hồ sơ trong kết quả MCP (kế hoạch M11, tool `get_checklist`): tên, bắt buộc
 * hay không, trạng thái, lý do từ chối (văn phòng viết, khách đã thấy), và SỐ tài liệu đã gắn — chỉ
 * số đếm, không tên tệp khách tải lên. Không có `description`, `reviewed_by`.
 *
 * Số đếm tự loại tài liệu nhóm D (R4: nhóm D "không liệt kê, không đếm") và tài liệu có nhóm không
 * rõ (đóng cửa) — tự đếm trên quan hệ `documents` đã nạp, không tin một con số nơi gọi đưa vào.
 */
final class ChecklistItemPresenter
{
    use ReadsLoadedRelations;

    public const FIELDS = [
        'id', 'name', 'is_required', 'status', 'status_label', 'rejection_reason', 'document_count', 'url',
    ];

    /**
     * Cần nạp sẵn: `documents`.
     *
     * @return array<string, mixed>
     */
    public static function present(MatterChecklistItem $item): array
    {
        /** @var Collection<int, Document> $documents */
        $documents = self::loaded($item, 'documents');

        return [
            'id' => McpIds::encode(McpIds::CHECKLIST_ITEM, (int) $item->getKey()),
            'name' => (string) $item->name,
            'is_required' => (bool) $item->is_required,
            'status' => $item->status?->value,
            'status_label' => $item->status?->label(),
            'rejection_reason' => $item->rejection_reason,
            'document_count' => $documents
                ->filter(fn (Document $document) => $document->group instanceof DocumentGroup && ! $document->group->isInternal())
                ->count(),
            'url' => AdminUrls::checklistItem($item),
        ];
    }
}
