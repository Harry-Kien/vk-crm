<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\MatterChecklist;
use App\Models\MatterChecklistItem;

/**
 * Đầu ra của tool `get_checklist` (kế hoạch M11, bảng tool 8): tham chiếu vụ, các mục
 * ({@see ChecklistItemPresenter} — số tài liệu đã gắn chỉ là số đếm, không nhóm D, không tên tệp), và
 * "Đã nộp X/Y" ({@see self::progress()}, cùng hình dạng với `get_matter`).
 */
final class MatterChecklistPresenter
{
    public const FIELDS = ['matter', 'items', 'checklist_progress'];

    /**
     * Cần nạp sẵn: mỗi mục như {@see ChecklistItemPresenter::present()}.
     *
     * @return array{matter: array<string, string>, items: list<array<string, mixed>>, checklist_progress: array{submitted: int, total: int, label: string}}
     */
    public static function present(MatterChecklist $checklist): array
    {
        return [
            'matter' => MatterPresenter::reference($checklist->matter),
            'items' => array_map(fn (MatterChecklistItem $item): array => ChecklistItemPresenter::present($item), $checklist->items),
            'checklist_progress' => self::progress($checklist->submitted, $checklist->total),
        ];
    }

    /**
     * "Đã nộp X/Y" (SPEC §4.10) — MỘT hình dạng cho `get_checklist` và `get_matter`.
     *
     * @return array{submitted: int, total: int, label: string}
     */
    public static function progress(int $submitted, int $total): array
    {
        return [
            'submitted' => $submitted,
            'total' => $total,
            'label' => __('mcp.get_matter.checklist_progress', ['submitted' => $submitted, 'total' => $total]),
        ];
    }
}
