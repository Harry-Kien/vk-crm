<?php

namespace App\Actions\Mcp;

use App\Actions\Matter\SetMatterAiAccess;
use App\Enums\AiAccessMode;
use App\Enums\AiOfficeStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\McpToolOutcome;
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
 *  - **lượt gọi 7 ngày**: dòng `mcp_tool_called` từ {@see SummarizeMcpActivity::windowStart()}(7), mọi
 *    kết cục — cùng con số "Lượt gọi" của khối "Hoạt động" kỳ 7 ngày; kèm số dòng `outcome = ok`;
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
            callsLastWeek: self::callsLastWeek()->count(),
            callsOkLastWeek: self::callsLastWeek()->where('properties->outcome', McpToolOutcome::Ok->value)->count(),
            mattersAllowed: Matter::query()
                ->where('confidentiality', Confidentiality::Normal->value)
                ->where('ai_access', MatterAiAccess::Allowed->value)
                ->count(),
        );
    }

    /** @return Builder<Activity> */
    private static function callsLastWeek(): Builder
    {
        return Activity::query()
            ->where('event', 'mcp_tool_called')
            ->where('created_at', '>=', SummarizeMcpActivity::windowStart(7));
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
     * phiên bản cũ. Không tự lọc "được bật": bộ lọc "Được bật, chưa cam kết" của bảng nhân sự ghép nó
     * sau {@see self::whereEnabled()}, để danh sách lọc đúng m − n của thẻ "Đã cam kết chính sách n/m"
     * (task polish vòng sửa 1, n6) — người ở chế độ "Tắt" không cần cam kết nên không nằm trong đó.
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
