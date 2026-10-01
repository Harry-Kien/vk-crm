<?php

namespace App\Filament\Admin\Auth;

use App\Filament\Portal\Auth\PortalMultiFactorChallenge;
use App\Support\LoginThrottle;
use App\Support\StaffLoginThrottle;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Bộ đếm của bước nhập mã (TOTP hoặc mã khôi phục) ở cổng NHÂN SỰ, đặt lại cho đúng SPEC §10.3 —
 * M8 Task 3. Bản sao của {@see PortalMultiFactorChallenge} cho guard
 * `web`, dùng {@see StaffLoginThrottle}.
 *
 * Bản của Filament đúng số lần (5) nhưng sai hai chỗ, đã đọc trong `MultiFactorChallenge` 5.8:
 * `hitRateLimiter()` gọi `RateLimiter::hit($key)` không truyền decay (60 giây, không phải 15
 * phút), và khoá là `sha1(guard|class|id)` — chỉ theo tài khoản, không có chiều IP. Với một mã
 * TOTP 6 số thì cửa sổ 60 giây là một cánh cửa thật; và mã khôi phục là bí mật thứ hai cùng bị dò
 * qua đúng bộ đếm này (`AppAuthentication::getChallengeFormComponents()` — hai ô, một cổng).
 *
 * Ghi đè đúng ba phương thức mà cổng ở `Login::isMultiFactorChallengeRateLimited()` gọi tới;
 * `getRateLimiterKey()` của lớp cha cố ý KHÔNG được ghi đè (nó trả một khoá, còn ở đây phải là
 * hai). Bộ đếm này TÁCH khỏi bộ đếm của bước mật khẩu — lý do ở docblock của `LoginThrottle`.
 */
class StaffMultiFactorChallenge extends MultiFactorChallenge
{
    public function isRateLimited(Authenticatable $user): bool
    {
        return StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($user));
    }

    public function hitRateLimiter(Authenticatable $user): void
    {
        StaffLoginThrottle::hit(StaffLoginThrottle::codeKeys($user));
    }

    public function getRateLimiterAvailableInSeconds(Authenticatable $user): int
    {
        return StaffLoginThrottle::availableInSeconds(StaffLoginThrottle::codeKeys($user));
    }

    /**
     * Xoá sau một lần đăng nhập thành công — **chỉ chiều TÀI KHOẢN**, cùng lý do với cổng khách
     * ({@see LoginThrottle}: "tôi vào được" không chứng minh gì về những lần hỏng của
     * người ngồi cạnh sau cùng một NAT).
     */
    public function clearRateLimiter(Authenticatable $user): void
    {
        StaffLoginThrottle::clearCodeAccount($user);
    }

    /**
     * `isRateLimited()` hỏi thẳng `StaffLoginThrottle::MAX_ATTEMPTS`, nên phương thức này không
     * còn nằm trên đường chạy; giữ và cho trả cùng con số để hai câu trả lời không lệch nhau.
     */
    public function getMaxRateLimiterAttempts(): int
    {
        return StaffLoginThrottle::MAX_ATTEMPTS;
    }
}
