<?php

namespace App\Actions\Mcp\Read;

use App\Models\Matter;
use App\Models\StageLog;

/** Kết quả của {@see ListMatterUpdates}. */
final readonly class MatterUpdatesPage
{
    /**
     * @param  KeysetPage<StageLog>  $page  dòng đã nạp `views` và `matter`
     * @param  array<string, string>  $stageLabels  khoá giai đoạn → nhãn nội bộ (gồm giai đoạn đã xoá mềm)
     */
    public function __construct(
        public Matter $matter,
        public KeysetPage $page,
        public array $stageLabels,
    ) {}
}
