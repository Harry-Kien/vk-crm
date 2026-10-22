<?php

namespace App\Actions\Portal;

use App\Actions\Push\ForgetPushDevice;
use App\Http\Middleware\EnsurePortalAccountIsActive;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cắt mọi lối vào ĐANG MỞ của một tài khoản cổng vừa bị văn phòng tắt hay xoá — cùng khuôn
 * `EndStaffSessions` của nhân sự. Rà soát toàn hệ thống 2026-10-22 (làn portal, việc chuyển từ làn
 * security): trước bản này hai đường đó chỉ dựa vào {@see EnsurePortalAccountIsActive}, vốn chỉ đăng xuất ở
 * request KẾ TIẾP khi tài khoản CÒN tắt — văn phòng tắt (khách báo mất điện thoại) rồi bật lại / "Khôi phục"
 * trước request đó thì phiên cũ vào lại không cần mật khẩu, và máy cũ vẫn nhận thông báo đẩy về hồ sơ.
 *
 * Ba việc, gọi TRONG transaction của người gọi, trên dòng `client_users` người gọi đã khoá hay vừa ghi:
 *  1. tăng `session_epoch` — phiên cũ chết ở request kế tiếp của nó ({@see EnsurePortalAccountIsActive}),
 *     kể cả khi tài khoản đã được bật lại;
 *  2. đổi `remember_token` — một cookie "ghi nhớ đăng nhập" cũ (nếu còn sót) hết hiệu lực;
 *  3. SAU commit: gỡ mọi máy nhận thông báo đẩy ({@see ForgetPushDevice::all()}) — một máy nằm im không gửi
 *     request nào nên bước 1 không tới được nó. Lần lưu rollback thì máy còn nguyên.
 */
final class EndPortalSessions
{
    public function handle(ClientUser $account, ?User $actor, string $pushReason): void
    {
        $account->forceFill([
            'session_epoch' => (int) $account->session_epoch + 1,
            'remember_token' => Str::random(60),
        ])->save();

        DB::afterCommit(fn () => app(ForgetPushDevice::class)->all($account, $actor, $pushReason));
    }
}
