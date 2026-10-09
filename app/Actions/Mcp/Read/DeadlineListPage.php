<?php

namespace App\Actions\Mcp\Read;

use App\Models\Deadline;

/** Kết quả của {@see ListDeadlines}. */
final readonly class DeadlineListPage
{
    /**
     * @param  KeysetPage<Deadline>  $page  mốc đã nạp `matter` và `responsible`
     * @param  string|null  $dueFrom  cận dưới THẬT đã áp (`Y-m-d`), `null` là không có cận dưới
     * @param  string|null  $dueTo  cận trên THẬT đã áp (`Y-m-d`), `null` là không có cận trên
     */
    public function __construct(
        public KeysetPage $page,
        public ?string $dueFrom,
        public ?string $dueTo,
    ) {}
}
