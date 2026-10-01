<?php

namespace App\Support;

use App\Actions\User\UnlockStaffLogin;
use App\Filament\Admin\Auth\StaffMultiFactorChallenge;
use App\Filament\Admin\Pages\Auth\Login;
use App\Models\User;

/**
 * Giới hạn tần suất đăng nhập CỔNG NHÂN SỰ (guard `web`, panel `/admin`) — SPEC §10.3, M8 Task 3.
 *
 * Trước task này panel `admin` dùng bộ đếm mặc định của Filament (5 lần / 60 giây, chỉ theo IP ở
 * bước mật khẩu; chỉ theo tài khoản ở bước mã — xem docblock {@see LoginThrottle}) nên SPEC §10.3
 * chỉ đúng ở cổng khách. Luật giữ nguyên, chỉ đổi model ({@see User}) và tiền tố khoá
 * `staff-login`, để rổ đếm của nhân sự không bao giờ chung với rổ của khách hàng.
 *
 * Nơi cắm: {@see Login} (bước mật khẩu) và
 * {@see StaffMultiFactorChallenge} (bước mã — TOTP hoặc mã khôi phục).
 * Đường mở khoá: {@see UnlockStaffLogin}.
 */
final class StaffLoginThrottle extends LoginThrottle
{
    protected static function accountModel(): string
    {
        return User::class;
    }

    protected static function keyPrefix(): string
    {
        return 'staff-login';
    }
}
