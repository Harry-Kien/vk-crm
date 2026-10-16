<?php

namespace App\Enums;

use App\Support\Mcp\McpAccess;

/**
 * Dòng trạng thái lớn của trang "Kết nối AI của tôi" (làn ai, 2026-10-15): CHÍNH người đang xem đang
 * ở đâu trên đường tới một trợ lý AI dùng được. Không phải một cột: suy từ lý do đầu tiên của
 * {@see McpAccess::refusal()} (đúng thứ tự R2 kiểm) và số kết nối đang sống của người đó.
 *
 *  - {@see self::NotEnabled}: tài khoản bị vô hiệu hoá, chế độ "Tắt", hay vai không còn `matter.view`
 *    — việc của quản trị;
 *  - {@see self::ServerOff}: công tắc đọc của văn phòng đang tắt;
 *  - {@see self::NotAcknowledged}: chưa cam kết đúng phiên bản chính sách hiện hành;
 *  - {@see self::NotConnected}: dùng được, chưa nối ứng dụng AI nào;
 *  - {@see self::Connected}: dùng được và có ít nhất một kết nối đang sống.
 *
 * Chỉ để TRÌNH BÀY; quyết định truy cập vẫn ở {@see McpAccess}.
 */
enum MyAiConnectionStatus: string
{
    case NotEnabled = 'not_enabled';
    case ServerOff = 'server_off';
    case NotAcknowledged = 'not_acknowledged';
    case NotConnected = 'not_connected';
    case Connected = 'connected';

    public static function for(?McpAccessRefusal $refusal, int $connections): self
    {
        return match ($refusal) {
            null => $connections > 0 ? self::Connected : self::NotConnected,
            McpAccessRefusal::ServerDisabled => self::ServerOff,
            McpAccessRefusal::PolicyNotAcknowledged => self::NotAcknowledged,
            // Inactive, AiAccessOff, NoMatterView — và hai lý do chỉ màn hình đồng ý hỏi, không bao
            // giờ do `refusal()` trả: đều là "chưa được bật".
            default => self::NotEnabled,
        };
    }

    public function label(): string
    {
        return __('ai_connections.guide.status.'.$this->value);
    }
}
