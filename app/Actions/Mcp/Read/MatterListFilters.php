<?php

namespace App\Actions\Mcp\Read;

/**
 * Bộ lọc của `search_matters` (kế hoạch M11, bảng tool 4 [DC:50]). Mọi trường `null`/`false` là
 * "không lọc theo trường đó".
 */
final readonly class MatterListFilters
{
    /**
     * @param  string|null  $query  chữ tìm trên mã, tiêu đề, tên khách, số thụ lý
     * @param  string|null  $matterType  mã (`matter_types.code`) hoặc tên đầy đủ của loại vụ
     * @param  string|null  $stage  mã giai đoạn (`matters.stage`)
     * @param  bool  $mine  chỉ vụ người gọi là luật sư phụ trách
     * @param  bool|null  $open  `true` chỉ vụ đang mở, `false` chỉ vụ đã kết thúc
     */
    public function __construct(
        public ?string $query = null,
        public ?string $matterType = null,
        public ?string $stage = null,
        public bool $mine = false,
        public ?bool $open = null,
    ) {}

    /**
     * Dạng mảng ổn định — dấu vân tay của cursor (`App\Support\Mcp\McpCursor`) dựng trên đây.
     *
     * @return array{query: ?string, matter_type: ?string, stage: ?string, mine: bool, open: ?bool}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'matter_type' => $this->matterType,
            'stage' => $this->stage,
            'mine' => $this->mine,
            'open' => $this->open,
        ];
    }
}
