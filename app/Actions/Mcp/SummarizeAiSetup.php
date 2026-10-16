<?php

namespace App\Actions\Mcp;

use App\Actions\Matter\SetMatterAiAccess;
use App\Enums\AiAccessMode;
use App\Enums\AiOfficeStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpSwitches;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Thẻ trạng thái tổng và danh sách "Các bước để bắt đầu dùng" của trang "Kết nối AI" (làn ai,
 * 2026-10-15): trợ lý AI đang bật hay tắt cho văn phòng, và văn phòng còn thiếu bước nào. Chỉ ĐỌC, trả
 * DTO {@see AiSetupSummary}; không quyết định truy cập nào — quyết định vẫn ở {@see McpAccess}.
 *
 * # Định nghĩa (dùng chung với bộ lọc của bảng nhân sự, để số trên thẻ và danh sách lọc không lệch)
 *
 *  - **được bật** ({@see self::whereEnabled()}): tài khoản đang hoạt động (không xoá mềm) có chế độ
 *    khác "Tắt". Không hỏi lại `matter.view` từng người: {@see SetUserAiAccess} không bật được cho
 *    người thiếu nó, và đổi vai hạ chế độ về "Tắt" (`RevokeAiConnections`);
 *  - **đã cam kết** ({@see self::whereAcknowledgedCurrent()}): có dòng `ai_acknowledgements` của ĐÚNG
 *    phiên bản chính sách hiện hành — cùng câu hỏi với {@see McpAccess::hasAcknowledgedCurrentPolicy()}
 *    (bỏ `ClientPortalScope` như ở đó). Cấu hình phiên bản hỏng ({@see McpAccess::policyVersion()} là
 *    `null`) thì không ai đã cam kết;
 *  - **đang có kết nối**: {@see ListAiConnections::whereConnected()};
 *  - **lượt dùng 7 ngày**: dòng `mcp_tool_called` từ {@see SummarizeMcpActivity::windowStart()}(7);
 *  - **vụ việc bật cho AI**: `matters.ai_access = allowed` ∧ `confidentiality = normal`, vụ chưa xoá
 *    mềm — chỉ một con số. Vế `= normal` là đúng mệnh đề 2 của {@see McpMatterScope}: vụ hạn chế
 *    vắng mặt khỏi mọi tool với MỌI người (kể cả admin), mà {@see SetMatterAiAccess} không chặn bật
 *    cờ trên nó — đếm nó thì bước "vụ việc" ✓ trong khi AI không thấy vụ nào (fix round 1, I1).
 *
 * # Quyền
 *
 * `viewAny` trên `User` (`settings.manage`), hỏi `Gate::forUser($actor)` ngay đầu.
 */
final class SummarizeAiSetup
{
    public function handle(User $actor): AiSetupSummary
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $staffEnabled = self::whereEnabled(User::query())->count();

        return new AiSetupSummary(
            status: AiOfficeStatus::current(),
            assessmentFiledOn: McpSwitches::transferAssessmentFiledOn(),
            staffEnabled: $staffEnabled,
            staffAcknowledged: $staffEnabled === 0 ? 0 : self::whereAcknowledgedCurrent(self::whereEnabled(User::query()))->count(),
            staffConnected: $staffEnabled === 0 ? 0 : ListAiConnections::whereConnected(self::whereEnabled(User::query()))->count(),
            callsLastWeek: Activity::query()
                ->where('event', 'mcp_tool_called')
                ->where('created_at', '>=', SummarizeMcpActivity::windowStart(7))
                ->count(),
            mattersAllowed: Matter::query()
                ->where('confidentiality', Confidentiality::Normal->value)
                ->where('ai_access', MatterAiAccess::Allowed->value)
                ->count(),
        );
    }

    /**
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public static function whereEnabled(Builder $users): Builder
    {
        return $users
            ->where($users->qualifyColumn('is_active'), true)
            ->where($users->qualifyColumn('ai_access'), '!=', AiAccessMode::Off->value);
    }

    /**
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public static function whereAcknowledgedCurrent(Builder $users): Builder
    {
        $version = McpAccess::policyVersion();

        if ($version === null) {
            return $users->whereRaw('1 = 0');
        }

        return $users->whereHas('aiAcknowledgements', fn (Builder $acknowledgement) => $acknowledgement
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('policy_version', $version));
    }

    /**
     * Phần bù của {@see self::whereAcknowledgedCurrent()}: chưa cam kết lần nào, hay chỉ cam kết
     * phiên bản cũ.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public static function whereNotAcknowledgedCurrent(Builder $users): Builder
    {
        $version = McpAccess::policyVersion();

        if ($version === null) {
            return $users;
        }

        return $users->whereDoesntHave('aiAcknowledgements', fn (Builder $acknowledgement) => $acknowledgement
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('policy_version', $version));
    }
}
