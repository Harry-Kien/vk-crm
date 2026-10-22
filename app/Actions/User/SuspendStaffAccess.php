<?php

namespace App\Actions\User;

use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\Push\ForgetPushDevice;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\AiRevocationReason;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Http\Middleware\EndDisabledStaffSessions;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Jobs\SendSuspendedHolderNotices;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenLeads;
use App\Support\OpenTaskWork;
use App\Support\OpenWork;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Khoá truy cập ngay" (sửa sau kiểm tra nghiệp vụ toàn hệ thống, làn fb, mục A5) — khoá một nhân sự
 * nghỉ đột xuất hoặc bị nghi lộ dữ liệu NGAY, khi người đó còn giữ việc.
 *
 * Trước Action này, tắt "Đang hoạt động" trên trang sửa nhân sự bị chặn cho tới khi bàn giao xong
 * mọi vụ, mốc hạn, yêu cầu khách và lead tiếp nhận đang mở (R7; làn fi A1,
 * {@see GuardsStaffOffboarding::offboardingOpenWorkReason()}); trong lúc admin bàn giao (có thể hàng
 * giờ), người đó vẫn đăng nhập và tải tài liệu được.
 *
 * **Tách "khoá truy cập" khỏi "nghỉ việc".** Action này chỉ cắt QUYỀN VÀO, không chạm việc: vụ,
 * mốc hạn, yêu cầu khách vẫn đứng tên người đó và hiện trên "Theo dõi đội ngũ"/"Bàn giao hàng loạt"
 * để bàn giao sau; lead tiếp nhận đang mở (`OpenLeads`: được chuyển tư vấn, đang phụ trách) cũng giữ
 * nguyên, chuyển sau bằng "Chuyển cho luật sư"/ô "Người phụ trách" trên từng lead — "Bàn giao hàng
 * loạt" không chuyển lead (làn fi, vòng sửa 1, I3). Sau khi khoá (`is_active = false`), công tắc trên
 * form không còn gì để chặn, nên hộp xác nhận và nhật ký đếm cả lead. Luật R7 vẫn nguyên cho bước XOÁ
 * ({@see DeleteStaffMember}) và cho công tắc trên form (đường nghỉ việc thường). Mở lại được: bật
 * "Đang hoạt động" trên form sửa (không có gì chặn chiều bật), rồi bật lại quyền AI nếu cần.
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
 *  5. Nhật ký `staff_access_suspended`: lý do (≥ 20 ký tự) và số việc dở dang lúc khoá (vụ dẫn, mốc
 *     hạn, yêu cầu khách, lead được chuyển tư vấn, lead đang phụ trách).
 *
 * Sau commit: gỡ mọi máy nhận thông báo đẩy ({@see ForgetPushDevice::all()}, lý do
 * {@see ForgetPushDevice::REASON_STAFF_SUSPENDED}) — một máy nằm im không gửi request nào nên bước 1
 * không tới được nó. Người đó còn giữ việc nội bộ (chờ nhận, đang làm): người giao mỗi việc — hay người nhận thay
 * họ — nhận MỘT chuông gộp ({@see SendSuspendedHolderNotices}, kiểm toán 2026-10-21, Q4); trước đó người giao một việc
 * đang làm không hạn hay hạn xa không bao giờ được báo (lời nhắc chỉ đi khi việc chờ nhận lâu, sắp đến hạn, quá hạn).
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
            $openLeads = OpenLeads::forUser($locked);
            // Làn fa2, phán quyết 2: việc nội bộ còn dở cũng vào nhật ký, như mọi loại việc dở khác.
            $openTasks = OpenTaskWork::forUser($locked);

            $locked->forceFill(['is_active' => false])->save();

            // Bước 2, 3 và lần gỡ máy sau commit: một định nghĩa với tắt "Đang hoạt động" và xoá nhân sự.
            app(EndStaffSessions::class)->handle($locked, $actor, ForgetPushDevice::REASON_STAFF_SUSPENDED);

            $event = Audit::record('staff_access_suspended', $locked, [
                'reason' => $reason,
                'open_lead_matters' => $openWork->leadMatters->count(),
                'open_deadlines' => $openWork->deadlines->count(),
                'open_client_requests' => $openWork->clientRequests->count(),
                'open_consulting_leads' => $openLeads->consulting->count(),
                'open_assigned_leads' => $openLeads->assigned->count(),
                'open_held_tasks' => $openTasks->held->count(),
                'open_assigned_tasks' => $openTasks->assigned->count(),
                'pending_seats' => $openTasks->pendingSeats->count(),
            ], $actor);

            app(RevokeAiConnections::class)->handle($locked, AiRevocationReason::Deactivated, $actor);

            // Kiểm toán 2026-10-21 (Q4): người giao các việc người này đang giữ được báo — xem docblock lớp.
            if ($openTasks->held->isNotEmpty()) {
                SendSuspendedHolderNotices::dispatch((int) $locked->getKey(), (int) $actor->getKey(), (int) $event->getKey())->afterCommit();
            }

            return $locked;
        }));
    }
}
