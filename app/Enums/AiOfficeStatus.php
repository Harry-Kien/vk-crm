<?php

namespace App\Enums;

use App\Support\Mcp\McpSwitches;

/**
 * Trạng thái TỔNG của trợ lý AI cho cả văn phòng, như thẻ đầu trang "Kết nối AI" nói ra (làn ai,
 * 2026-10-15). Không phải một cột: suy từ hai công tắc toàn hệ thống của M11 R2 ({@see McpSwitches}),
 * đúng như máy chủ MCP đọc chúng ở mỗi request:
 *
 *  - {@see self::Off}: `mcp.enabled` tắt — mọi request `/mcp` nhận 401, kể cả khi công tắc ghi đã
 *    lưu bật (ghi chỉ có tác dụng khi đọc bật);
 *  - {@see self::ReadOnly}: `mcp.enabled` bật, `mcp.write_enabled` tắt — không ai ghi được qua AI;
 *  - {@see self::ReadWrite}: cả hai bật ({@see McpSwitches::writeEnabled()}) — người ở chế độ "Đọc
 *    và ghi" có bốn tool ghi.
 *
 * Chỉ để TRÌNH BÀY: không nơi nào quyết định truy cập dựa vào enum này; quyết định vẫn ở
 * `App\Support\Mcp\McpAccess`.
 */
enum AiOfficeStatus: string
{
    case Off = 'off';
    case ReadOnly = 'read';
    case ReadWrite = 'read_write';

    /** Trạng thái theo hai công tắc đang lưu, đọc lại CSDL ở mỗi lần hỏi. */
    public static function current(): self
    {
        if (! McpSwitches::enabled()) {
            return self::Off;
        }

        return McpSwitches::writeEnabled() ? self::ReadWrite : self::ReadOnly;
    }

    public function label(): string
    {
        return __('ai_connections.status.'.$this->value);
    }

    /** Một câu nói trạng thái này nghĩa là gì với nhân sự. */
    public function hint(): string
    {
        return __('ai_connections.status.hints.'.$this->value);
    }
}
