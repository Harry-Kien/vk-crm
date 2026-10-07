<?php

namespace App\Actions\User;

use App\Actions\Push\ForgetPushDevice;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * "Đặt lại 2FA" (R2, kế hoạch M8 Task 2) — con đường DUY NHẤT hợp lệ để xoá secret 2FA của một
 * nhân sự, dùng khi họ mất điện thoại (và mã khôi phục). **Mất điện thoại không phải là tắt 2FA**:
 * hành động này KHÔNG cho người bị đặt lại đăng nhập mà không có 2FA — nó chỉ xoá secret cũ và bắt
 * họ CÀI LẠI ở lần đăng nhập kế tiếp (`EnsureMultiFactorAuthenticationIsEnabled`,
 * `isRequired: true` ở `AdminPanelProvider`, đưa họ tới trang cài đặt bắt buộc).
 *
 * Hai đường vào: nút "Đặt lại 2FA" trên `EditUser` (admin khác bấm, có Gate) và lệnh console
 * `vkcrm:reset-2fa {email}` (`$actor = null`, cho trường hợp admin DUY NHẤT mất cả điện thoại lẫn
 * mã khôi phục — người có quyền vào máy chủ đã ở trong vòng tin cậy).
 *
 * # Ba việc, một transaction, dưới khoá dòng `users` của người bị đặt lại
 *
 * 1. **Xoá secret + mã khôi phục** — qua `saveAppAuthenticationSecret(null)`/
 *    `saveAppAuthenticationRecoveryCodes(null)` ({@see User}), KHÔNG gán thẳng thuộc tính. Đây là
 *    lời gọi DUY NHẤT trong `app/` được phép gọi `saveAppAuthenticationSecret(null)` —
 *    `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php` quét `app/` bằng token để giữ
 *    đúng lời hứa đó.
 * 2. **Tăng `users.session_epoch`** — kết thúc MỌI phiên `web` đang mở của người đó, ở request kế
 *    tiếp của chúng (`RejectStaffSessionsFromBeforeReset`: phiên mang epoch cũ bị đăng xuất, trước
 *    cả cổng 2FA). Đóng đúng lỗ hổng "điện thoại mất kèm trình duyệt đang đăng nhập": nếu không,
 *    kẻ đang cầm trình duyệt đó (đã đăng nhập từ trước, phiên `web` không tự hỏi 2FA lại) sẽ tự CÀI
 *    2FA MỚI trên chính máy của họ — chiếm tài khoản vĩnh viễn. KHÔNG xoá theo `sessions.user_id`
 *    (bản đầu): cột đó do guard mặc định lúc ghi điền, nên một trình duyệt vừa chạm `/portal` để
 *    lại `user_id` của khách, dòng phiên nhân sự sống sót, và phiên của một khách trùng số bị xoá
 *    nhầm — đọc docblock của middleware.
 * 3. **Đổi `remember_token`** — phiên "ghi nhớ đăng nhập" (cookie sống nhiều ngày, không đi qua
 *    bảng `sessions`) mở lại được một phiên `web` mới chỉ từ cookie đó; đổi token vô hiệu hoá nó
 *    cùng lúc với bước 2.
 *
 * `lockForUpdate()` trên dòng `users` của TARGET (không phải actor): hai lượt đặt lại gần như
 * đồng thời cho cùng một người (một admin bấm nút, một admin khác gõ lệnh console cùng lúc) xếp
 * hàng, không phải một cuộc đua hai UPDATE rời rạc.
 *
 * # Sau commit: gỡ mọi máy nhận thông báo đẩy (việc sau gộp M12, làn fu4, mục 2a)
 *
 * Điện thoại mất vẫn đổ chuông với mốc hạn, vụ `restricted`, khoản thu quá hạn nếu đăng ký push của
 * nó còn: bước 2 chỉ đăng xuất phiên ở request KẾ TIẾP của máy đó, mà một máy nằm im không gửi request
 * nào. Nên sau khi transaction trên commit (`DB::afterCommit`, sau transaction ngoài cùng), mọi máy
 * của người bị đặt lại bị gỡ qua {@see ForgetPushDevice::all()} với lý do `two_factor_reset` — mỗi
 * máy một dòng `push_device_removed`, người gây ra là admin đã bấm (lệnh console: không ai). Rollback
 * thì không gỡ gì. Lưới thứ hai, cho mọi lối khác làm trống secret: `PushAlert::shouldSend()` không
 * đẩy cho nhân sự chưa có 2FA (SPEC §10.7).
 */
final class ResetStaffTwoFactor
{
    public function handle(?User $actor, User $target): void
    {
        if ($actor !== null && $actor->is($target)) {
            // "Không có tuỳ chọn tắt" áp cho cả admin: tự đặt lại 2FA của chính mình là một đường
            // tắt trá hình (tự xoá secret, tự cài lại thứ mình muốn). Ném thay vì lặng lẽ không
            // làm gì — cùng thành ngữ `ClientUser::toggleEmailAuthentication()` (M5): một lời gọi
            // tới đây là một chỗ nào đó trong hệ thống đang tin rằng việc này làm được.
            throw new LogicException('Không tự đặt lại 2FA cho chính mình — nhờ một quản trị viên khác.');
        }

        DB::transaction(function () use ($actor, $target): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $locked->saveAppAuthenticationSecret(null);
            $locked->saveAppAuthenticationRecoveryCodes(null);

            $locked->forceFill([
                'session_epoch' => (int) $locked->session_epoch + 1,
                'remember_token' => Str::random(60),
            ])->save();

            Audit::record('staff_two_factor_reset', $locked, [
                'via' => $actor === null ? 'console' : 'admin',
            ], $actor);

            DB::afterCommit(fn () => app(ForgetPushDevice::class)
                ->all($locked, $actor, ForgetPushDevice::REASON_TWO_FACTOR_RESET));
        });
    }
}
