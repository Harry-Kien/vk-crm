<?php

namespace App\Actions\Mcp;

use Carbon\CarbonImmutable;

/**
 * Một dòng của khối "Hoạt động của trợ lý AI" trên trang "Kết nối AI" (làn ai, 2026-10-15) — DTO của
 * {@see SummarizeMcpActivity}, cho cả ba cách chia: theo ngày, theo chức năng (tool), theo người.
 *
 *  - `key`: ngày `Y-m-d`, tên tool (chuỗi rỗng cho dòng không mang tên tool đã khai), hay `users.id`;
 *  - `label`: chữ hiện trên màn hình (ngày `d/m`, tên tool, tên người kèm "đã nghỉ việc" nếu có);
 *  - `calls`: số lần gọi tool; `refused`: trong đó, số lần bị từ chối (`denied`) hay vượt giới hạn
 *    tần suất (`rate_limited`);
 *  - `lastAt`: lần gọi gần nhất — chỉ dòng theo người có.
 */
final readonly class McpActivityRow
{
    public function __construct(
        public string $key,
        public string $label,
        public int $calls,
        public int $refused,
        public ?CarbonImmutable $lastAt = null,
    ) {}
}
