<?php

namespace App\Actions\Mcp;

use App\Enums\McpToolOutcome;
use Carbon\CarbonImmutable;

/**
 * Số liệu của khối "Hoạt động của trợ lý AI" (làn ai, 2026-10-15) — DTO của {@see SummarizeMcpActivity}.
 *
 *  - `days`, `from`: kỳ (7 hay 30 ngày, tính cả hôm nay) và nửa đêm đầu kỳ;
 *  - `calls`: tổng số lần gọi tool trong kỳ;
 *  - `outcomes`: giá trị {@see McpToolOutcome} → số lần, đủ mọi kết cục (0 khi không có);
 *  - `byDay`: đủ từng ngày của kỳ, cũ trước, kể cả ngày không có lần gọi nào;
 *  - `byTool`, `byPerson`: nhiều lần gọi nhất trước;
 *  - `pendingDrafts`: số nháp do AI soạn đang chờ người dùng hay bỏ (mọi lúc, không theo kỳ).
 */
final readonly class McpActivityReport
{
    /**
     * @param  array<string, int>  $outcomes
     * @param  list<McpActivityRow>  $byDay
     * @param  list<McpActivityRow>  $byTool
     * @param  list<McpActivityRow>  $byPerson
     */
    public function __construct(
        public int $days,
        public CarbonImmutable $from,
        public int $calls,
        public array $outcomes,
        public array $byDay,
        public array $byTool,
        public array $byPerson,
        public int $pendingDrafts,
    ) {}

    /** Số lần gọi lớn nhất của một ngày trong kỳ — để vẽ thanh theo tỉ lệ; ít nhất 1. */
    public function busiestDay(): int
    {
        return max([1, ...array_map(fn (McpActivityRow $row): int => $row->calls, $this->byDay)]);
    }
}
