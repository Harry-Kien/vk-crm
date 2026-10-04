<?php

namespace App\Support\Mcp;

use App\Actions\Mcp\AcknowledgeAiPolicy;
use App\Enums\AiAccessMode;
use App\Enums\McpAccessRefusal;
use App\Enums\Permission;
use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\AiAcknowledgement;
use App\Models\User;

/**
 * MỘT định nghĩa của "người này dùng được máy chủ MCP lúc này không, và ghi được không" (M11 R2, R12,
 * R13). Mọi nơi hỏi câu đó hỏi ở đây: {@see EnsureMcpAccess} (mỗi request `/mcp`), lớp tool cơ sở
 * {@see CrmTool} và bước gọi tool (`App\Mcp\Methods\CrmToolInvoker`) cho quyền ghi, màn hình đồng ý
 * OAuth (Task 4) và màn hình "Kết nối AI" (Task 15).
 *
 * Kiểm ở mỗi lần hỏi, không lúc cấp token [DC:149]: công tắc và lời cam kết đọc lại CSDL mỗi lần;
 * trạng thái của người (`is_active`, xoá mềm, `ai_access`, vai) đọc trên đối tượng `User` truyền vào —
 * trong request MCP đó là người guard `mcp` vừa nạp mới từ CSDL. Đổi một điều kiện thì request kế
 * tiếp của MỌI token cũ thấy ngay. Không thuộc tính `static` nào giữ gì qua hai lần hỏi.
 *
 * Không đọc `auth()` nào: người dùng luôn được truyền tường minh (trong request MCP, `auth('web')` và
 * `auth('client')` đều rỗng — Review Focus 4).
 */
final class McpAccess
{
    /**
     * Lý do ĐẦU TIÊN khiến người này không dùng được máy chủ MCP, theo thứ tự R2 kiểm, hoặc `null`
     * khi được dùng:
     *
     *  1. {@see McpAccessRefusal::Inactive} — tài khoản bị vô hiệu hoá hoặc đã xoá mềm;
     *  2. {@see McpAccessRefusal::AiAccessOff} — `users.ai_access` là `off`, HOẶC người đó không còn
     *     giữ được quyền AI ({@see self::canHold()}: thiếu `matter.view`, ví dụ một nhân sự thành kế
     *     toán qua một đường nào đó không đi qua trang sửa nhân sự);
     *  3. {@see McpAccessRefusal::ServerDisabled} — công tắc `mcp.enabled` tắt ({@see McpSwitches});
     *  4. {@see McpAccessRefusal::PolicyNotAcknowledged} — chưa cam kết chính sách dùng AI đúng
     *     phiên bản hiện hành ({@see self::hasAcknowledgedCurrentPolicy()}).
     *
     * Hai điều kiện còn lại của R2 không ở đây vì chúng thuộc về TOKEN, không thuộc về người: client
     * OAuth mang cờ `is_mcp` (`EnsureMcpClient`) và scope `mcp:use` (`CheckToken`).
     */
    public static function refusal(User $user): ?McpAccessRefusal
    {
        if (! $user->is_active || $user->trashed()) {
            return McpAccessRefusal::Inactive;
        }

        if ($user->ai_access === AiAccessMode::Off || ! self::canHold($user)) {
            return McpAccessRefusal::AiAccessOff;
        }

        if (! McpSwitches::enabled()) {
            return McpAccessRefusal::ServerDisabled;
        }

        if (! self::hasAcknowledgedCurrentPolicy($user)) {
            return McpAccessRefusal::PolicyNotAcknowledged;
        }

        return null;
    }

    /**
     * Ghi được qua MCP (bốn tool ghi của R5): dùng được máy chủ ({@see self::refusal()} rỗng), chế độ
     * `read_write`, VÀ công tắc `mcp.write_enabled` bật ({@see McpSwitches::writeEnabled()}).
     */
    public static function canWrite(User $user): bool
    {
        return $user->ai_access === AiAccessMode::ReadWrite
            && self::refusal($user) === null
            && McpSwitches::writeEnabled();
    }

    /**
     * Người này giữ được quyền AI: có `matter.view` (R2 — mọi tool đọc nội dung vụ việc, và tra cứu
     * đề xuất tắt MCP cho kế toán [DC:114]). Hỏi spatie qua `can()`; `User::$guard_name = 'web'` giữ
     * câu trả lời đúng cả dưới guard `mcp`.
     */
    public static function canHold(User $user): bool
    {
        return $user->can(Permission::MatterView->value);
    }

    /**
     * Phiên bản chính sách dùng AI hiện hành (`config('vkcrm.mcp.policy_version')`), hoặc `null` khi
     * cấu hình trống (chỉ khoảng trắng cũng là trống) hay dài hơn cột
     * (`ai_acknowledgements.policy_version`, 20 ký tự). `null` nghĩa là không ai cam kết được, nên
     * `/mcp` đóng với mọi người: một cấu hình hỏng phải đóng cửa, không mở cửa.
     */
    public static function policyVersion(): ?string
    {
        $version = config('vkcrm.mcp.policy_version');

        if (! is_string($version) || trim($version) === '' || mb_strlen($version) > AcknowledgeAiPolicy::POLICY_VERSION_MAX_LENGTH) {
            return null;
        }

        return $version;
    }

    /** Người này đã cam kết đúng phiên bản chính sách hiện hành (một dòng `ai_acknowledgements`). */
    public static function hasAcknowledgedCurrentPolicy(User $user): bool
    {
        $version = self::policyVersion();

        return $version !== null
            && AiAcknowledgement::query()
                ->where('user_id', $user->getKey())
                ->where('policy_version', $version)
                ->exists();
    }
}
