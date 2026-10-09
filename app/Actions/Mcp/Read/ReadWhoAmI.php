<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\PartyLabel;

/**
 * Đọc cho tool `whoami` (kế hoạch M11, bảng tool 1 [DC:47]): người gọi, vai trò, số vụ thấy được qua
 * MCP, và chế độ tên các bên (R10) để presenter nói đúng giới hạn đang áp.
 *
 * Số vụ là `McpMatterScope::query($actor)->count()` — CÙNG tập mà mọi tool khác dùng (R3: "số đếm
 * trong `whoami` … cũng tính trên tập đã thu hẹp"), nên không đếm vụ hạn chế, vụ `denied`, vụ của đội
 * khác hay vụ đã xoá.
 *
 * Chế độ (R2, bảng tool 1) là chế độ ĐANG CÓ HIỆU LỰC, cùng câu hỏi quyết định danh sách tool (R13,
 * `CrmTool::shouldRegister()`): `read_write` chỉ khi {@see McpAccess::canWrite()} — người
 * `read_write` và công tắc `mcp.write_enabled` bật; mọi trường hợp khác là `read`. Người tới được đây
 * đã qua `EnsureMcpAccess`, nên không bao giờ là `off`. Nói `read_write` khi công tắc ghi đang tắt sẽ
 * mời AI gọi tool mà máy chủ không đăng ký cho người này.
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
            mode: McpAccess::canWrite($actor) ? AiAccessMode::ReadWrite : AiAccessMode::Read,
        );
    }
}
