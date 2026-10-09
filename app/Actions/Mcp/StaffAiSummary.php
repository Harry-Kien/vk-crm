<?php

namespace App\Actions\Mcp;

use Carbon\CarbonImmutable;

/**
 * Một dòng của bảng nhân sự trên trang "Kết nối AI" (M11 Task 15) — DTO của
 * {@see ListAiConnections::overview()}. Chế độ (`users.ai_access`) đọc thẳng trên `User`, không ở đây.
 *
 *  - `acknowledgedAt` / `acknowledgedVersion`: lời cam kết R12 — của phiên bản hiện hành nếu có
 *    (`acknowledgedCurrent = true`), không thì lời cam kết mới nhất của một phiên bản cũ; `null` khi
 *    chưa cam kết lần nào;
 *  - `connections`: số client có token còn sống của người này (cùng định nghĩa "kết nối" với
 *    {@see ListAiConnections::forUser()});
 *  - `lastUsedAt`: lần gọi tool gần nhất, qua bất kỳ client nào.
 */
final readonly class StaffAiSummary
{
    public function __construct(
        public ?CarbonImmutable $acknowledgedAt,
        public ?string $acknowledgedVersion,
        public bool $acknowledgedCurrent,
        public int $connections,
        public ?CarbonImmutable $lastUsedAt,
    ) {}
}
