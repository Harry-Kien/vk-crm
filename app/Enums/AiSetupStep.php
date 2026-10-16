<?php

namespace App\Enums;

use App\Actions\Mcp\AiSetupSummary;

/**
 * Sáu bước "Các bước để bắt đầu dùng" của trang "Kết nối AI" (làn ai, 2026-10-15), theo đúng thứ tự
 * văn phòng làm. Mỗi bước ✓ hay ○ theo trạng thái THẬT của hệ thống ({@see AiSetupSummary::isDone()}),
 * không có ô tick tay:
 *
 *  1. {@see self::Legal} — chủ văn phòng làm các việc pháp lý (R12 mục 3) và ghi ngày nộp hồ sơ đánh
 *     giá tác động;
 *  2. {@see self::Server} — công tắc đọc `mcp.enabled` bật;
 *  3. {@see self::StaffEnabled} — ít nhất một nhân sự được bật chế độ khác "Tắt";
 *  4. {@see self::StaffAcknowledged} — MỌI nhân sự được bật đã cam kết đúng phiên bản chính sách;
 *  5. {@see self::StaffConnected} — ít nhất một nhân sự được bật đang có kết nối;
 *  6. {@see self::Matters} — ít nhất một vụ việc mang cờ "cho phép AI" (`matters.ai_access`, R9).
 */
enum AiSetupStep: string
{
    case Legal = 'legal';
    case Server = 'server';
    case StaffEnabled = 'staff_enabled';
    case StaffAcknowledged = 'staff_acknowledged';
    case StaffConnected = 'staff_connected';
    case Matters = 'matters';

    public function label(): string
    {
        return __('ai_connections.setup.steps.'.$this->value);
    }
}
