<?php

namespace App\Enums;

/**
 * Kết cục của MỘT lần `tools/call` trên máy chủ MCP, ghi ở `properties.outcome` của dòng
 * `mcp_tool_called` (M11 R8, Task 8). Bước gọi tool chung đặt giá trị này
 * (`App\Mcp\Methods\CallCrmTool`, `App\Mcp\Methods\CrmToolInvoker`), không tool nào tự đặt.
 *
 * - `ok`: tool chạy và trả kết quả không lỗi;
 * - `not_found`: tool trả đúng thông điệp "Không tìm thấy" duy nhất của R3 — id không tồn tại, vụ
 *   ngoài tập MCP thấy được, không có quyền: cùng một kết cục, như cùng một thông điệp;
 * - `denied`: bước gọi tool từ chối trước khi tool chạy (tool ghi với người không ghi được — R13,
 *   tool không kế thừa `CrmTool`), hoặc tool ném lỗi xác thực/phân quyền;
 * - `invalid`: tham số hay tên tool không dùng được (thiếu `name`, tên không có trên máy chủ, lỗi
 *   kiểm tra tham số), hoặc tool trả một thông điệp lỗi khác "Không tìm thấy";
 * - `rate_limited`: vượt một giới hạn của R8 (`App\Support\Mcp\McpRateLimits`), tool không chạy;
 * - `error`: lỗi không lường trước (tool ném một exception khác, hoặc máy chủ trả 5xx).
 */
enum McpToolOutcome: string
{
    case Ok = 'ok';
    case NotFound = 'not_found';
    case Denied = 'denied';
    case Invalid = 'invalid';
    case RateLimited = 'rate_limited';
    case Error = 'error';

    public function label(): string
    {
        return __('mcp_audit.outcomes.'.$this->value);
    }
}
