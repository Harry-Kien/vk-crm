<?php

namespace App\Enums;

use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\Mcp\SetUserAiAccess;
use App\Support\Mcp\McpAccess;

/**
 * Công tắc truy cập qua AI THEO NGƯỜI của M11 R2 (`users.ai_access`): quản trị chọn đích danh ai được
 * dùng máy chủ MCP, và dùng tới đâu. Không phải quyền theo vai trò (không thêm quyền spatie nào).
 *
 *  - `Off` (mặc định của cột): `/mcp` trả 401 cho mọi token của người này ({@see McpAccess::refusal()});
 *  - `Read`: chỉ các tool đọc; bốn tool ghi không được đăng ký cho người này (R13);
 *  - `ReadWrite`: thêm bốn tool ghi, khi công tắc `mcp.write_enabled` cũng bật
 *    ({@see McpAccess::canWrite()}).
 *
 * Đổi qua {@see SetUserAiAccess} (quản trị bật/tắt) và {@see RevokeAiConnections} (hệ thống hạ về
 * `Off` khi vô hiệu hoá, đổi vai, xoá); cột không nằm trong `User::$fillable`.
 */
enum AiAccessMode: string
{
    case Off = 'off';
    case Read = 'read';
    case ReadWrite = 'read_write';

    public function label(): string
    {
        return __('enums.ai_access_mode.'.$this->value);
    }
}
