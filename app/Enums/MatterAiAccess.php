<?php

namespace App\Enums;

use App\Actions\Matter\SetMatterAiAccess;
use App\Models\Matter;

/**
 * Cờ cấp vụ việc của M11 R9: vụ việc có được lên máy chủ MCP (AI của nhân sự) hay không.
 *
 * Luật Luật sư Điều 25 cấm tiết lộ thông tin vụ việc trừ khi khách đồng ý BẰNG VĂN BẢN, và Luật 91
 * Điều 9 nói im lặng không phải đồng ý. Nên một vụ chỉ thành `Allowed` theo hai đường:
 *  - lúc MỞ, nếu chủ văn phòng đặt `MCP_MATTER_DEFAULT=allowed` ({@see self::defaultForNewMatter()});
 *  - sau đó, chỉ qua {@see SetMatterAiAccess}, kèm lời xác nhận "khách đã đồng ý bằng văn bản cho
 *    việc này" và một dòng audit (cột không nằm trong `Matter::$fillable`).
 *
 * Vụ `Denied` vắng mặt khỏi mọi tool và mọi số đếm của MCP (R3, điều kiện 3 — `McpMatterScope` của
 * Task 9).
 */
enum MatterAiAccess: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';

    public function label(): string
    {
        return __('enums.matter_ai_access.'.$this->value);
    }

    /**
     * Giá trị cho một vụ việc MỚI: `config('vkcrm.mcp.matter_default')` (`.env` `MCP_MATTER_DEFAULT`),
     * mặc định `denied` — dự án không tự quyết thay luật sư rằng khách đã đồng ý. Chủ văn phòng đổi
     * được (câu hỏi mở 2 của kế hoạch M11).
     *
     * Mọi giá trị không đúng NGUYÊN VĂN `allowed` hay `denied` (trống, chữ hoa, `true`, gõ sai) cho ra
     * `Denied`: một lỗi gõ trong `.env` phải đóng cửa, không mở cửa.
     *
     * Trong `app/`, chỗ DUY NHẤT gọi hàm này là hook `creating` của {@see Matter}.
     */
    public static function defaultForNewMatter(): self
    {
        $configured = config('vkcrm.mcp.matter_default');

        return is_string($configured)
            ? (self::tryFrom($configured) ?? self::Denied)
            : self::Denied;
    }
}
