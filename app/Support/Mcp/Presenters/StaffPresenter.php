<?php

namespace App\Support\Mcp\Presenters;

use App\Models\User;
use App\Support\Mcp\McpIds;

/**
 * Một nhân sự trong kết quả MCP: id, tên, chức danh — không email, số điện thoại, số thẻ luật sư
 * hay bất kỳ cột nào khác của `users` (kế hoạch M11, R4; tool `whoami` cũng không trả email và số
 * điện thoại của chính người hỏi). `id` có tiền tố `user_` để `list_deadlines` lọc được theo người
 * phụ trách.
 */
final class StaffPresenter
{
    public const FIELDS = ['id', 'name', 'position', 'position_label'];

    /**
     * @return array{id: string, name: string, position: ?string, position_label: ?string}
     */
    public static function present(User $user): array
    {
        return [
            'id' => McpIds::encode(McpIds::USER, (int) $user->getKey()),
            'name' => (string) $user->name,
            'position' => $user->position?->value,
            'position_label' => $user->position?->label(),
        ];
    }
}
