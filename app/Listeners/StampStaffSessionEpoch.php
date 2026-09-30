<?php

namespace App\Listeners;

use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Ghi `users.session_epoch` hiện hành của nhân sự vào phiên ngay lúc đăng nhập guard `web`, để
 * {@see RejectStaffSessionsFromBeforeReset} nhận ra phiên nào có TRƯỚC lần "Đặt lại 2FA" gần nhất.
 * Đăng ký qua auto-discovery (phương thức `handle`), cùng khuôn {@see RecordStaffLogin}.
 *
 * Nếu một lượt đặt lại xen giữa lúc nạp người dùng và lúc ghi khoá này, phiên mang giá trị CŨ và bị
 * đăng xuất ở request kế tiếp — sai theo hướng an toàn.
 */
class StampStaffSessionEpoch
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! ($event->user instanceof User)) {
            return;
        }

        session()->put(RejectStaffSessionsFromBeforeReset::SESSION_KEY, (int) $event->user->session_epoch);
    }
}
