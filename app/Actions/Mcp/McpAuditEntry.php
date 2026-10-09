<?php

namespace App\Actions\Mcp;

use App\Enums\McpPlatform;
use App\Enums\McpToolOutcome;
use Carbon\CarbonImmutable;

/**
 * Một dòng của khối "Nhật ký MCP" trên trang "Kết nối AI" (M11 Task 15) — DTO của
 * {@see ListMcpAuditEntries}, không phải `Activity`.
 *
 *  - `event`: mã sự kiện (`mcp_tool_called`, `ai_connections_revoked`, …); màn hình hiện nhãn của nó;
 *  - `person`: tên người sở hữu token (causer), hoặc nhân sự là chủ thể khi causer vắng (hệ thống);
 *  - `tool`, `outcome`, `platform`, `ip`: chỉ dòng gọi tool và dòng kết nối mới có; IP là IP của nền
 *    tảng gọi thay nhân sự [PL:177], [PL:181];
 *  - `details`: phần còn lại của `properties`, ĐÃ đi qua `SensitivePropertyFilter`.
 */
final readonly class McpAuditEntry
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $id,
        public CarbonImmutable $at,
        public string $event,
        public ?string $person,
        public ?string $tool,
        public ?McpToolOutcome $outcome,
        public ?McpPlatform $platform,
        public ?string $ip,
        public array $details,
    ) {}
}
