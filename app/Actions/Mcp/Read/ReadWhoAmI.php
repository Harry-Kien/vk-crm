<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Enums\Role;
use App\Models\User;
use App\Support\Mcp\PartyLabel;

/**
 * Đọc cho tool `whoami` (kế hoạch M11, bảng tool 1 [DC:47]): người gọi, vai trò, số vụ thấy được qua
 * MCP, và chế độ tên các bên (R10) để presenter nói đúng giới hạn đang áp.
 *
 * Số vụ là `McpMatterScope::query($actor)->count()` — CÙNG tập mà mọi tool khác dùng (R3: "số đếm
 * trong `whoami` … cũng tính trên tập đã thu hẹp"), nên không đếm vụ hạn chế, vụ `denied`, vụ của đội
 * khác hay vụ đã xoá.
 *
 * TODO(m11-task6-whoami-mode): chế độ `read` / `read_write` của bảng tool đọc `users.ai_access`
 * (enum `AiAccessMode`), mà Task 6 của làn m11 dựng; làn này không có cột đó. Khi gộp: thêm chế độ
 * vào {@see WhoAmI}, `WhoAmIPresenter`, `outputSchema` của `WhoAmITool`, và bỏ `->todo()` của test
 * "R2 whoami trả chế độ…" trong `tests/Feature/Mcp/Tools/WhoAmIToolTest.php`.
 */
final class ReadWhoAmI
{
    public function __construct(private readonly McpMatterScope $scope) {}

    public function handle(User $actor): WhoAmI
    {
        $names = $actor->getRoleNames()->all();

        // Thứ tự của enum, không thứ tự trong bảng của spatie; tên vai lạ (không có trong enum) bị bỏ.
        $roles = array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => in_array($role->value, $names, true),
        ));

        return new WhoAmI(
            user: $actor,
            roles: $roles,
            matterCount: $this->scope->query($actor)->count(),
            partyNamesPseudonymised: PartyLabel::mode() === PartyLabel::PSEUDONYM,
        );
    }
}
