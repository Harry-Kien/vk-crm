<?php

namespace App\Actions\Mcp\Read;

use App\Enums\DeadlineSeverity;

/**
 * Bộ lọc của `list_deadlines` (kế hoạch M11, bảng tool 7 [DC:53]), đã giải từ tham số của tool.
 * `from` và `to` cùng `null` là "mặc định": hạn tới hết {@see ListDeadlines::DEFAULT_WINDOW_DAYS} ngày
 * tới, kể cả quá hạn.
 */
final readonly class DeadlineListFilters
{
    /**
     * @param  int|null  $matterId  chỉ mốc của vụ này (mọi trạng thái của vụ); `null` là mọi vụ ĐANG MỞ trong tập MCP
     * @param  string|null  $from  hạn từ ngày này (`Y-m-d`, gồm cả ngày đó)
     * @param  string|null  $to  hạn tới ngày này (`Y-m-d`, gồm cả ngày đó)
     * @param  int|null  $responsibleId  chỉ mốc người này phụ trách; `null` là mọi người
     * @param  bool  $includeCompleted  `false`: chỉ mốc chưa xong
     */
    public function __construct(
        public ?int $matterId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?DeadlineSeverity $severity = null,
        public ?int $responsibleId = null,
        public bool $includeCompleted = false,
    ) {}

    public function usesDefaultWindow(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * Dạng mảng ổn định — dấu vân tay của cursor (`App\Support\Mcp\McpCursor`).
     *
     * @return array{matter: ?int, from: ?string, to: ?string, severity: ?string, responsible: ?int, include_completed: bool}
     */
    public function toArray(): array
    {
        return [
            'matter' => $this->matterId,
            'from' => $this->from,
            'to' => $this->to,
            'severity' => $this->severity?->value,
            'responsible' => $this->responsibleId,
            'include_completed' => $this->includeCompleted,
        ];
    }
}
