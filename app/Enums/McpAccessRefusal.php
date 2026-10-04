<?php

namespace App\Enums;

use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Support\Mcp\McpAccess;

/**
 * Lý do một nhân sự KHÔNG được dùng máy chủ MCP lúc này (M11 R2, R12), theo đúng thứ tự R2 kiểm:
 * tài khoản đang hoạt động → quản trị đã bật `ai_access` (và người đó còn giữ được nó) → công tắc toàn
 * hệ thống → lời cam kết đúng phiên bản chính sách hiện hành.
 *
 * Một định nghĩa cho hai nơi hỏi cùng câu: {@see EnsureMcpAccess} (mỗi request `/mcp`, trả 401) và
 * màn hình đồng ý OAuth của Task 4 (từ chối bằng {@see self::label()}). Nguồn: {@see McpAccess::refusal()}.
 */
enum McpAccessRefusal: string
{
    case Inactive = 'inactive';
    case AiAccessOff = 'ai_access_off';
    case ServerDisabled = 'server_disabled';
    case PolicyNotAcknowledged = 'policy_not_acknowledged';

    public function label(): string
    {
        return __('enums.mcp_access_refusal.'.$this->value);
    }
}
