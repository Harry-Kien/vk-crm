<?php

namespace App\Actions\Mcp\Read;

use App\Models\Deadline;
use App\Models\Matter;

/**
 * Kết quả của {@see ReadMatter}: vụ việc đã nạp đủ quan hệ cho `MatterPresenter::detail()`, cộng ba
 * thứ không phải cột của vụ — các mốc gần nhất, "Đã nộp X/Y", số yêu cầu đang mở.
 */
final readonly class MatterOverview
{
    /**
     * @param  list<Deadline>  $nextDeadlines  đã nạp `matter`, `responsible`
     */
    public function __construct(
        public Matter $matter,
        public array $nextDeadlines,
        public int $checklistSubmitted,
        public int $checklistTotal,
        public int $openClientRequestCount,
    ) {}
}
