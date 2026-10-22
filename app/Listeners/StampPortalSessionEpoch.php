<?php

namespace App\Listeners;

use App\Http\Middleware\EnsurePortalAccountIsActive;
use App\Models\ClientUser;
use Illuminate\Auth\Events\Login;

/**
 * Ghi `client_users.session_epoch` hiện hành vào phiên ngay lúc đăng nhập guard `client`, để
 * {@see EnsurePortalAccountIsActive} nhận ra một phiên có từ trước lần văn phòng tắt / xoá tài khoản —
 * cùng khuôn `StampStaffSessionEpoch` của nhân sự (rà soát 2026-10-22, làn portal).
 */
class StampPortalSessionEpoch
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'client' || ! ($event->user instanceof ClientUser)) {
            return;
        }

        session()->put(EnsurePortalAccountIsActive::EPOCH_SESSION_KEY, (int) $event->user->session_epoch);
    }
}
