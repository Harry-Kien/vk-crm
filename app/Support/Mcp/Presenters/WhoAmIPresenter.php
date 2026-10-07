<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\WhoAmI;
use App\Enums\Role;

/**
 * Đầu ra của tool `whoami` (kế hoạch M11, bảng tool 1 [DC:47]): người gọi qua
 * {@see StaffPresenter} (id, tên, chức danh — không email, không số điện thoại), vai trò, số vụ thấy
 * được qua MCP, và các giới hạn đang áp, mỗi giới hạn một mã ổn định cộng một câu tiếng Việt.
 *
 * `mode` là chế độ đang có hiệu lực (`read` / `read_write`, R2) kèm nhãn tiếng Việt của
 * `AiAccessMode`; xem `ReadWhoAmI`.
 *
 * Giới hạn "tên giả cho bên thứ ba" chỉ có mặt khi `MCP_PARTY_NAMES` đang là `pseudonym` (R10): ở
 * chế độ `full` câu đó là một lời hứa sai.
 */
final class WhoAmIPresenter
{
    public const FIELDS = ['user', 'roles', 'mode', 'mode_label', 'matter_count', 'limits'];

    /** Mã các giới hạn, theo thứ tự hiển thị; câu chữ ở `mcp.whoami.limits.<mã>`. */
    public const LIMITS = [
        'no_restricted_matters',
        'consented_matters_only',
        'no_internal_documents',
        'no_identity_numbers',
        'no_internal_notes',
        'third_party_pseudonyms',
        'nothing_reaches_clients',
    ];

    /**
     * @return array{user: array<string, ?string>, roles: list<array{role: string, role_label: string}>, mode: string, mode_label: string, matter_count: int, limits: list<array{code: string, label: string}>}
     */
    public static function present(WhoAmI $whoAmI): array
    {
        $limits = array_values(array_filter(
            self::LIMITS,
            fn (string $code): bool => $code !== 'third_party_pseudonyms' || $whoAmI->partyNamesPseudonymised,
        ));

        return [
            'user' => StaffPresenter::present($whoAmI->user),
            'roles' => array_map(fn (Role $role): array => [
                'role' => $role->value,
                'role_label' => $role->label(),
            ], $whoAmI->roles),
            'mode' => $whoAmI->mode->value,
            'mode_label' => $whoAmI->mode->label(),
            'matter_count' => $whoAmI->matterCount,
            'limits' => array_map(fn (string $code): array => [
                'code' => $code,
                'label' => __('mcp.whoami.limits.'.$code),
            ], $limits),
        ];
    }
}
