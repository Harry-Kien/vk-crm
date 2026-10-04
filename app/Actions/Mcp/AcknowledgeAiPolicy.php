<?php

namespace App\Actions\Mcp;

use App\Models\AiAcknowledgement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Nhân sự tự cam kết chính sách dùng AI, phiên bản hiện hành (M11 R12 mục 1), trên trang "Kết nối AI
 * của tôi" (Task 15) — trước lần kết nối đầu tiên, và lại sau mỗi lần chính sách đổi phiên bản. Đây
 * là cam kết của NHÂN SỰ (Quy tắc 7.2 [PL:342]), không thay đồng ý của khách (R9).
 *
 * 1. `$acknowledged` phải là `true` — lời tích của chính người đó (ô trên màn hình của Task 15 không
 *    được đánh dấu sẵn). Không tích thì `ValidationException` gắn vào ô `acknowledged`, trước khi chạm
 *    CSDL; màn hình cũng sẽ đòi, đây là lớp phòng thủ cho mọi nơi gọi khác.
 * 2. Phiên bản hiện hành trống hay quá dài trong cấu hình ({@see McpAccess::policyVersion()}):
 *    `LogicException` — một cấu hình hỏng không được sinh ra một lời cam kết cho "phiên bản rỗng".
 * 3. Trong một transaction, câu ĐẦU TIÊN khoá dòng `users` của người đó: hai lần bấm gần như cùng
 *    lúc xếp hàng. Đã có dòng cho đúng phiên bản thì trả lại chính dòng đó (không dòng thứ hai,
 *    không audit thứ hai).
 * 4. Không thì một dòng `ai_acknowledgements` (`user_id`, `policy_version`, `accepted_at`, IP, user
 *    agent cắt ở {@see self::USER_AGENT_MAX_LENGTH} ký tự) — Nghị định 356: đồng ý "lưu lại và kiểm
 *    chứng được" [PL:331] — và một dòng audit `ai_policy_acknowledged` (`policy_version`), causer là
 *    chính người đó, tường minh.
 *
 * IP và user agent do người gọi đọc từ request của màn hình (Action không đọc `request()`).
 */
final class AcknowledgeAiPolicy
{
    /** Độ dài cột `ai_acknowledgements.policy_version`. */
    public const POLICY_VERSION_MAX_LENGTH = 20;

    /** Độ dài cột `ai_acknowledgements.user_agent`. */
    public const USER_AGENT_MAX_LENGTH = 500;

    /** Độ dài cột `ai_acknowledgements.ip_address` (IPv6 dạng dài nhất). */
    public const IP_ADDRESS_MAX_LENGTH = 45;

    public function handle(User $user, bool $acknowledged, ?string $ipAddress, ?string $userAgent): AiAcknowledgement
    {
        if (! $acknowledged) {
            throw ValidationException::withMessages([
                'acknowledged' => __('ai_access.validation.acknowledgement_required'),
            ]);
        }

        $version = McpAccess::policyVersion()
            ?? throw new LogicException('Phiên bản chính sách dùng AI (vkcrm.mcp.policy_version) trống hoặc dài hơn cột: không cam kết được.');

        return DB::transaction(function () use ($user, $version, $ipAddress, $userAgent): AiAcknowledgement {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $existing = AiAcknowledgement::query()
                ->where('user_id', $locked->getKey())
                ->where('policy_version', $version)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $acknowledgement = AiAcknowledgement::query()->forceCreate([
                'user_id' => $locked->getKey(),
                'policy_version' => $version,
                'accepted_at' => now(),
                'ip_address' => filled($ipAddress) ? mb_substr($ipAddress, 0, self::IP_ADDRESS_MAX_LENGTH) : null,
                'user_agent' => filled($userAgent) ? mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH) : null,
            ]);

            Audit::record('ai_policy_acknowledged', $locked, ['policy_version' => $version], $locked);

            return $acknowledgement;
        });
    }
}
