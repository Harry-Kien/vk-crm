<?php

namespace App\Support\Mcp\Presenters;

use App\Models\Client;
use App\Support\Mcp\PhoneMask;

/**
 * Khách hàng của một vụ việc trong kết quả MCP (tool `get_matter`): tên, loại, số điện thoại ĐÃ CHE
 * (`***678`). Không bao giờ `id_number` (CCCD/MST, kể cả che), email, địa chỉ, người đại diện hay
 * ghi chú nội bộ `note` (kế hoạch M11, R4; bảng tool: `get_matter` loại email khách). Không có id:
 * MCP không có tool nào nhận id khách (`search_clients`/`get_client` ngoài phạm vi M11).
 */
final class ClientPresenter
{
    public const FIELDS = ['name', 'type', 'type_label', 'phone_masked'];

    /**
     * @return array{name: string, type: ?string, type_label: ?string, phone_masked: ?string}
     */
    public static function present(Client $client): array
    {
        return [
            'name' => (string) $client->name,
            'type' => $client->type?->value,
            'type_label' => $client->type?->label(),
            'phone_masked' => PhoneMask::mask($client->phone),
        ];
    }
}
