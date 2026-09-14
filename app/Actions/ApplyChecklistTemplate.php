<?php

namespace App\Actions;

use App\Enums\ChecklistItemStatus;
use App\Models\ChecklistTemplate;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sao chép danh mục hồ sơ chuẩn vào vụ việc (SPEC §4.10). Sao chép, không tham chiếu.
 * Gọi lại với cùng template không tạo trùng: item đã có (theo tên) được giữ nguyên.
 * Không thiết kế cho hai lời gọi đồng thời trên cùng một vụ việc; gọi trong Action tạo vụ việc.
 */
class ApplyChecklistTemplate
{
    /** @return Collection<int, MatterChecklistItem> */
    public function handle(Matter $matter, ChecklistTemplate $template): Collection
    {
        return DB::transaction(function () use ($matter, $template): Collection {
            $existing = $matter->checklistItems()->withTrashed()->pluck('name')->all();

            return $template->items
                ->reject(fn ($item) => in_array($item->name, $existing, true))
                ->map(fn ($item) => $matter->checklistItems()->create([
                    'name' => $item->name,
                    'description' => $item->description,
                    'is_required' => $item->is_required,
                    'sort_order' => $item->sort_order,
                    'status' => ChecklistItemStatus::Missing,
                ]))
                ->values();
        });
    }
}
