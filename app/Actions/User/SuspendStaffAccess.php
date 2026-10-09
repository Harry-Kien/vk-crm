<?php

namespace App\Actions\User;

use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\Push\ForgetPushDevice;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\AiRevocationReason;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Http\Middleware\EndDisabledStaffSessions;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenWork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Khoá truy cập ngay" (sửa sau kiểm tra nghiệp vụ toàn hệ thống, làn fb, mục A5) — khoá một nhân sự
 * nghỉ đột xuất hoặc bị nghi lộ dữ liệu NGAY, khi người đó còn giữ việc.
 *
 * Trước Action này, tắt "Đang hoạt động" trên trang sửa nhân sự bị chặn cho tới khi bàn giao xong
 * mọi vụ, mốc hạn và yêu cầu khách (R7, {@see GuardsStaffOffboarding::offboardingOpenWorkReason()});
 * trong lúc admin bàn giao (có thể hàng giờ), người đó vẫn đăng nhập và tải tài liệu được.
 *
 * **Tách "khoá truy cập" khỏi "nghỉ việc".** Action này chỉ cắt QUYỀN VÀO, không chạm việc: vụ,
 * mốc hạn, yêu cầu khách vẫn đứng tên người đó và hiện trên "Theo dõi đội ngũ"/"Bàn giao hàng loạt"
 * để bàn giao sau. Luật R7 vẫn nguyên cho bước XOÁ ({@see DeleteStaffMember}) và cho công tắc trên
 * form (đường nghỉ việc thường). Mở lại được: bật "Đang hoạt động" trên form sửa (không có gì chặn
 * chiều bật), rồi bật lại quyền AI nếu cần.
 *
 * Năm việc, một transaction, dưới khoá dòng `users` của người bị khoá (và `Cache::lock` của
 * {@see DeleteStaffMember}/{@see EditUser} để luật "quản trị
 * viên đang hoạt động cuối cùng" không lọt qua một cuộc đua hai hàng):
 *
 *  1. `is_active = false` — mọi phiên `web` đang mở bị đăng xuất ở request kế tiếp
 *     ({@see EndDisabledStaffSessions}), panel và mọi đường tải trả 404, MCP từ chối người đó.
 *  2. Tăng `session_epoch` ({@see RejectStaffSessionsFromBeforeReset}) — phiên cũ không quay lại
 *     được kể cả khi tài khoản được bật lại sau đó.
 *  3. Đổi `remember_token` — cookie "ghi nhớ đăng nhập" cũ (nếu còn) hết hiệu lực.
 *  4. Thu hồi mọi kết nối AI và hạ `ai_access` về `off` ({@see RevokeAiConnections}, lý do
 *     `deactivated`).
 *  5. Nhật ký `staff_access_suspended`: lý do (≥ 20 ký tự) và số việc dở dang lúc khoá.
 *
 * Sau commit: gỡ mọi máy nhận thông báo đẩy ({@see ForgetPushDevice::all()}, lý do
 * {@see ForgetPushDevice::REASON_STAFF_SUSPENDED}) — một máy nằm im không gửi request nào nên bước 1
 * không tới được nó.
 *
 * Quyền: `UserPolicy::suspendAccess` — chỉ quản trị viên, không tự khoá chính mình. Không khoá được
 * quản trị viên đang hoạt động cuối cùng.
 */
final class SuspendStaffAccess
{
    use GuardsStaffOffboarding;

    public const MIN_REASON_LENGTH = 20;

    public function handle(User $actor, User $target, string $reason): User
    {
        Gate::forUser($actor)->authorize('suspendAccess', $target);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => [__('staff_access.suspend.reason_too_short', ['min' => self::MIN_REASON_LENGTH])],
            ]);
        }

        return Cache::lock('staff-admin-headcount', 10)->block(5, fn (): User => DB::transaction(function () use ($actor, $target, $reason): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'reason' => [__('staff_access.suspend.already_inactive', ['name' => $locked->name])],
                ]);
            }

            if ($this->wouldLeaveNoActiveAdmin($locked, remainsActiveAdmin: false)) {
                throw ValidationException::withMessages(['reason' => [$this->lastActiveAdminReason()]]);
            }

            $openWork = OpenWork::forUser($locked);

            $locked->forceFill([
                'is_active' => false,
                'session_epoch' => (int) $locked->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();

            Audit::record('staff_access_suspended', $locked, [
                'reason' => $reason,
                'open_lead_matters' => $openWork->leadMatters->count(),
                'open_deadlines' => $openWork->deadlines->count(),
                'open_client_requests' => $openWork->clientRequests->count(),
            ], $actor);

            app(RevokeAiConnections::class)->handle($locked, AiRevocationReason::Deactivated, $actor);

            DB::afterCommit(fn () => app(ForgetPushDevice::class)
                ->all($locked, $actor, ForgetPushDevice::REASON_STAFF_SUSPENDED));

            return $locked;
        }));
    }
}
