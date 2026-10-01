<?php

namespace App\Http\Middleware;

use App\Actions\User\ResetStaffTwoFactor;
use App\Listeners\StampStaffSessionEpoch;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đăng xuất mọi phiên `web` của một nhân sự được ĐĂNG NHẬP TRƯỚC lần "Đặt lại 2FA" gần nhất của họ
 * ({@see ResetStaffTwoFactor}) — kế hoạch M8 Task 2, R2, vòng sửa 1.
 *
 * **Vì sao không xoá dòng `sessions` theo `user_id`** (cách của bản đầu, sai): cột `sessions.user_id`
 * do `DatabaseSessionHandler` điền từ guard MẶC ĐỊNH lúc ghi (container `auth.driver`, dựng một lần
 * mỗi tiến trình), và `Filament\Http\Middleware\Authenticate` đổi guard mặc định sang `client` trên
 * route `/portal`. Hai panel dùng chung một cookie phiên: một trình duyệt nhân sự mà request cuối
 * là một request cổng khách để lại dòng `user_id = id của KHÁCH` trong khi payload vẫn giữ
 * `login_web_*`. Xoá theo `user_id` bỏ sót đúng dòng đó (kẻ cầm trình duyệt bị mất tự cài TOTP của
 * mình ở trang cài đặt bắt buộc — chiếm tài khoản vĩnh viễn) và xoá nhầm phiên của một khách có id
 * trùng số với nhân sự. Phiên cũng không nhất thiết nằm trong CSDL (`SESSION_DRIVER=file`).
 *
 * **Cách làm**: `users.session_epoch` tăng mỗi lần đặt lại; {@see StampStaffSessionEpoch}
 * ghi giá trị hiện hành vào phiên lúc đăng nhập guard `web`; middleware này so hai giá trị. Lệch →
 * `logout()` guard `web` (chỉ guard `web`: cùng cookie có thể mang phiên cổng khách của người đó và
 * nó không liên quan). Phiên đã có từ trước khi có cột không mang khoá → coi là 0, khớp giá trị
 * mặc định, nên triển khai không đăng xuất ai.
 *
 * Đăng ký ở HAI nơi vì panel Filament không dùng nhóm `web`: `bootstrap/app.php` (nhóm `web` — phủ
 * request cập nhật Livewire, `documents.download`, mọi route ngoài panel) và
 * `AdminPanelProvider::middleware()` (route trang panel `/admin`), luôn SAU `StartSession` và TRƯỚC
 * `Authenticate`/cổng 2FA — người bị đăng xuất phải nhận trang đăng nhập, không phải trang cài 2FA.
 */
final class RejectStaffSessionsFromBeforeReset
{
    /** Khoá trong phiên mang epoch của nhân sự tại lúc đăng nhập. */
    public const SESSION_KEY = 'staff_session_epoch';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($request->hasSession() && $guard->check()) {
            $user = $guard->user();

            if ($user instanceof User
                && (int) $request->session()->get(self::SESSION_KEY, 0) !== (int) $user->session_epoch) {
                $guard->logout();
                $request->session()->forget(self::SESSION_KEY);
            }
        }

        return $next($request);
    }
}
