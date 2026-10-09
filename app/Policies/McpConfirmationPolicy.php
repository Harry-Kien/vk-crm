<?php

namespace App\Policies;

use App\Models\ClientUser;
use App\Models\McpConfirmation;
use App\Models\User;

/**
 * Mã xác nhận hai bước đã dùng của MCP (M11 R6, Task 7). KHÔNG màn hình nào đọc bảng này, kể cả của
 * quản trị: chỉ đường ghi của MCP (Task 13) tra một dòng theo `jti`, dưới đúng người sở hữu token,
 * để trả lại bản ghi đã tạo. Bản ghi đó (mốc hạn, nhật ký liên lạc) có policy và màn hình riêng; dấu
 * vết "ai gọi tool nào" nằm ở nhật ký `mcp_tool_called` (Task 8), không ở đây.
 *
 * Policy tồn tại vì luật M2 (`PortalCoverageTest`): mọi model giới hạn portal đều có policy.
 */
class McpConfirmationPolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return false;
    }

    public function view(User|ClientUser $user, McpConfirmation $confirmation): bool
    {
        return false;
    }
}
