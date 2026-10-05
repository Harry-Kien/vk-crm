<?php

namespace App\Actions\Mcp\Read;

/**
 * Bộ lọc của `list_client_requests` (kế hoạch M11, bảng tool 10 [DC:56]): "đang mở, giao cho tôi,
 * theo vụ". Mọi trường `null`/`false` là "không lọc theo trường đó".
 */
final readonly class ClientRequestListFilters
{
    /**
     * @param  int|null  $matterId  chỉ yêu cầu của vụ này
     * @param  bool|null  $open  `true`: chưa đóng; `false`: đã đóng
     * @param  bool  $mine  chỉ yêu cầu đang giao cho người gọi
     */
    public function __construct(
        public ?int $matterId = null,
        public ?bool $open = null,
        public bool $mine = false,
    ) {}

    /**
     * Dạng mảng ổn định — dấu vân tay của cursor (`App\Support\Mcp\McpCursor`).
     *
     * @return array{matter: ?int, open: ?bool, mine: bool}
     */
    public function toArray(): array
    {
        return [
            'matter' => $this->matterId,
            'open' => $this->open,
            'mine' => $this->mine,
        ];
    }
}
