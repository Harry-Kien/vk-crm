<?php

namespace App\Actions\Mcp\Read;

use App\Models\Deadline;
use App\Models\Matter;

/** Một trang của {@see ListMatters}. */
final readonly class MatterListPage
{
    /**
     * @param  list<Matter>  $matters  đã nạp `client`, `leadLawyer`, `matterType.stages`
     * @param  array<int, Deadline>  $nextDeadlines  theo id vụ; vụ không còn mốc chưa xong thì vắng
     * @param  int|null  $lastId  id vụ cuối trang khi còn trang sau, ngược lại `null`
     */
    public function __construct(
        public array $matters,
        public array $nextDeadlines,
        public ?int $lastId,
    ) {}
}
