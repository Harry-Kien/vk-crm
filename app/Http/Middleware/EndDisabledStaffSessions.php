<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nhân sự bị vô hiệu hoá (`users.is_active = false`, nút "Hoạt động" trên trang sửa nhân sự) mất
 * phiên `web` ở request KẾ TIẾP — lượt quét §10 trước bản 1.0 (M8 Task 6, R5), cùng lời hứa SPEC §10.9
 * nói cho tài khoản cổng mà `EnsurePortalAccountIsActive` giữ cho khách.
 *
 * Trước lượt quét: `User::canAccessPanel()` từ chối nên mọi trang `/admin` trả 404
 * (`AnswerDeniedPanelRequestsWithNotFound`), nhưng phiên VẪN đăng nhập — người đó không tới được cả
 * trang đăng nhập (trang đăng nhập đẩy người đã đăng nhập về bảng tin, bảng tin 404), và một lần bật
 * lại tài khoản trả lại đúng phiên cũ trên một máy có thể đã không còn trong tay họ.
 *
 * Chỉ `logout()` guard `web`, KHÔNG `invalidate()` cả phiên — cùng lý do với
 * {@see RejectStaffSessionsFromBeforeReset}: hai panel dùng chung một cookie phiên, và phiên cổng khách
 * (nếu có) trong cùng trình duyệt không liên quan. `Logout` phát ra nên máy nhận thông báo đẩy của
 * trình duyệt này được gỡ (`ForgetPushDeviceOnLogout`).
 *
 * Đăng ký ở HAI nơi như middleware kia: nhóm `web` (`bootstrap/app.php` — request cập nhật Livewire,
 * route tải tài liệu, mọi route ngoài panel) và `AdminPanelProvider::middleware()` (route trang panel),
 * SAU `StartSession`, TRƯỚC `Authenticate` — người bị đăng xuất nhận trang đăng nhập, không phải 404.
 * Test: `tests/Feature/Security/SessionCutSpec109Test.php`.
 */
final class EndDisabledStaffSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($request->hasSession() && $guard->check()) {
            $user = $guard->user();

            if ($user instanceof User && ! $user->is_active) {
                $guard->logout();
                $request->session()->forget(RejectStaffSessionsFromBeforeReset::SESSION_KEY);
            }
        }

        return $next($request);
    }
}
