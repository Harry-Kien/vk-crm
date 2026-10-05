<?php

namespace App\Actions\Mcp\Read;

use App\Models\Matter;
use App\Models\MatterChecklistItem;

/** Kết quả của {@see ReadChecklist}. */
final readonly class MatterChecklist
{
    /**
     * @param  list<MatterChecklistItem>  $items  đã nạp `documents` (chỉ id, mục, nhóm)
     * @param  int  $submitted  tử số "Đã nộp X/Y" (`ChecklistProgress`)
     * @param  int  $total  mẫu số "Đã nộp X/Y"
     */
    public function __construct(
        public Matter $matter,
        public array $items,
        public int $submitted,
        public int $total,
    ) {}
}
