<?php

namespace App\Enums;

use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Support\Mcp\McpAccess;

/**
 * Lý do một tài khoản KHÔNG được dùng máy chủ MCP lúc này (M11 R2, R12), hoặc không được đồng ý một
 * kết nối mới (Task 4).
 *
 * Năm lý do của R2, theo đúng thứ tự R2 kiểm ({@see McpAccess::refusal()}): tài khoản đang hoạt động →
 * quản trị đã bật `ai_access` → vai của người đó còn xem được nội dung vụ việc (`matter.view`) → công
 * tắc toàn hệ thống → lời cam kết đúng phiên bản chính sách hiện hành. {@see EnsureMcpAccess} hỏi chúng
 * ở mỗi request `/mcp` (trả 401).
 *
 * Hai lý do chỉ màn hình đồng ý OAuth hỏi ({@see McpAccess::consentRefusal()}), vì chúng thuộc về PHIÊN
 * `web` đang bấm "Đồng ý", không thuộc về token: {@see self::NotStaff} (người của phiên không phải nhân
 * sự) và {@see self::TwoFactorNotSetUp} (phiên mật khẩu của một nhân sự chưa cài 2FA — cổng 2FA của
 * Filament không đứng trước `/oauth/authorize`).
 *
 * {@see self::label()} là câu tiếng Việt màn hình đồng ý hiện cho chính người đó.
 */
enum McpAccessRefusal: string
{
    case NotStaff = 'not_staff';
    case Inactive = 'inactive';
    case TwoFactorNotSetUp = 'two_factor_not_set_up';
    case AiAccessOff = 'ai_access_off';
    case NoMatterView = 'no_matter_view';
    case ServerDisabled = 'server_disabled';
    case PolicyNotAcknowledged = 'policy_not_acknowledged';

    public function label(): string
    {
        return __('enums.mcp_access_refusal.'.$this->value);
    }
}
