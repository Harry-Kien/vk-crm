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
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * MỘT định nghĩa của "người này dùng được máy chủ MCP lúc này không, và ghi được không" (M11 R2, R12,
 * R13). Mọi nơi hỏi câu đó hỏi ở đây: {@see EnsureMcpAccess} (mỗi request `/mcp`), lớp tool cơ sở
 * {@see CrmTool} và bước gọi tool (`App\Mcp\Methods\CrmToolInvoker`) cho quyền ghi, màn hình đồng ý
 * OAuth (Task 4, {@see self::consentRefusal()}) và màn hình "Kết nối AI" (Task 15).
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
     *  2. {@see McpAccessRefusal::AiAccessOff} — `users.ai_access` là `off`;
     *  3. {@see McpAccessRefusal::NoMatterView} — `ai_access` còn bật nhưng người đó không còn giữ được
     *     quyền AI ({@see self::canHold()}: thiếu `matter.view`, ví dụ một nhân sự thành kế toán qua một
     *     đường nào đó không đi qua trang sửa nhân sự). Tách khỏi lý do 2 ở Task 4 (rà soát Task 6, m6)
     *     vì màn hình đồng ý hiện nhãn của lý do cho chính người đó, và "quản trị chưa bật" là câu sai
     *     khi quản trị đã bật;
     *  4. {@see McpAccessRefusal::ServerDisabled} — công tắc `mcp.enabled` tắt ({@see McpSwitches});
     *  5. {@see McpAccessRefusal::PolicyNotAcknowledged} — chưa cam kết chính sách dùng AI đúng
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

        if ($user->ai_access === AiAccessMode::Off) {
            return McpAccessRefusal::AiAccessOff;
        }

        if (! self::canHold($user)) {
            return McpAccessRefusal::NoMatterView;
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
     * Lý do ĐẦU TIÊN khiến tài khoản của phiên `web` này không được đồng ý một kết nối AI MỚI ở màn
     * hình `/oauth/authorize` (Task 4), hoặc `null` khi được:
     *
     *  1. {@see McpAccessRefusal::NotStaff} — không phải nhân sự (`User`). Guard `web` mà Passport hỏi
     *     (`config/passport.php`) chỉ nạp được `users`, nên một phiên cổng khách là khách vãng lai ở đây
     *     và bị đưa về trang đăng nhập `/admin`; dòng này đóng mọi đường còn lại (một `ClientUser` lọt
     *     vào guard `web` bằng bất kỳ cách nào), vì Passport gắn mã uỷ quyền theo SỐ id của người đó;
     *  2. {@see McpAccessRefusal::Inactive} — như {@see self::refusal()}, nhưng hỏi trước 2FA;
     *  3. {@see McpAccessRefusal::TwoFactorNotSetUp} — nhân sự CHƯA có secret 2FA (vừa bị "Đặt lại 2FA",
     *     hay chưa cài lần đầu). Cổng `EnsureMultiFactorAuthenticationIsEnabled` của Filament chỉ đứng
     *     trước route của panel; `/oauth/authorize` nằm ngoài panel, nên một phiên chỉ có mật khẩu sẽ
     *     đồng ý được nếu thiếu dòng này. Cùng luật với `DocumentDownloadController::actor()` (M8 R2);
     *  4. còn lại: {@see self::refusal()}, đúng thứ tự R2.
     */
    public static function consentRefusal(?Authenticatable $account): ?McpAccessRefusal
    {
        if (! $account instanceof User) {
            return McpAccessRefusal::NotStaff;
        }

        if (! $account->is_active || $account->trashed()) {
            return McpAccessRefusal::Inactive;
        }

        if (blank($account->getAppAuthenticationSecret())) {
            return McpAccessRefusal::TwoFactorNotSetUp;
        }

        return self::refusal($account);
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

    /**
     * Người này đã cam kết đúng phiên bản chính sách hiện hành (một dòng `ai_acknowledgements`).
     *
     * Bỏ `ClientPortalScope` (`AiAcknowledgement` chặn `1 = 0` khi có phiên cổng khách): đây là câu
     * hỏi có/không về CHÍNH nhân sự này cho một quyết định truy cập, không trả dòng nào ra ngoài.
     * Giữ scope thì một phiên cổng khách đang mở trong cùng tiến trình (test, hàng đợi) làm mọi nhân
     * sự đủ điều kiện bị từ chối `policy_not_acknowledged` (phát hiện khi gộp làn m11b: mười test
     * "phiên cổng khách không cắt …" của tool đọc).
     */
    public static function hasAcknowledgedCurrentPolicy(User $user): bool
    {
        $version = self::policyVersion();

        return $version !== null
            && AiAcknowledgement::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('user_id', $user->getKey())
                ->where('policy_version', $version)
                ->exists();
    }
}
