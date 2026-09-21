<?php

namespace App\Filament\Portal\Auth;

use App\Support\PortalLoginThrottle;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Bộ đếm của bước nhập mã, đặt lại cho đúng SPEC §10.3.
 *
 * Bản của Filament đúng số lần (5) nhưng sai hai chỗ, đã đọc trong `MultiFactorChallenge` bản
 * 5.8 đang cài:
 *
 *  - `hitRateLimiter()` gọi `RateLimiter::hit($key)` **không truyền decay**, nên cửa sổ là 60
 *    giây chứ không phải 15 phút. Kẻ dò mã chỉ cần đợi một phút là được 5 lần nữa; với một mã
 *    6 số sống 5 phút thì đó là một cánh cửa thật.
 *  - khoá là `sha1(guard|class|id)`, tức **chỉ theo tài khoản**. SPEC §10.3 đòi cả chiều IP.
 *
 * Ba phương thức đầu là toàn bộ bề mặt mà cổng ở `Login::isMultiFactorChallengeRateLimited()`
 * gọi tới, nên ghi đè đúng ba cái là đủ để đổi hành vi mà không phải chép lại vòng đời đăng
 * nhập. `getRateLimiterKey()` của lớp cha cố ý KHÔNG được ghi đè: nó trả về một khoá duy nhất,
 * còn ở đây phải là hai, nên đường đúng là thay chính ba phương thức dùng khoá.
 *
 * Bộ đếm này TÁCH khỏi bộ đếm của bước nhập mật khẩu — lý do ở `PortalLoginThrottle`.
 */
class PortalMultiFactorChallenge extends MultiFactorChallenge
{
    public function isRateLimited(Authenticatable $user): bool
    {
        return PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::codeKeys($user));
    }

    public function hitRateLimiter(Authenticatable $user): void
    {
        PortalLoginThrottle::hit(PortalLoginThrottle::codeKeys($user));
    }

    public function getRateLimiterAvailableInSeconds(Authenticatable $user): int
    {
        return PortalLoginThrottle::availableInSeconds(PortalLoginThrottle::codeKeys($user));
    }

    /**
     * Xoá sau một lần đăng nhập thành công. Không có dòng này thì một người gõ nhầm mã bốn lần
     * rồi vào được vẫn để lại 5 lượt trong bộ đếm — cổng đập bộ đếm ở MỌI lần gửi mã, kể cả lần
     * đúng — và lần đăng nhập kế tiếp trong vòng 15 phút bị chặn ngay từ mã đầu tiên.
     */
    public function clearRateLimiter(Authenticatable $user): void
    {
        PortalLoginThrottle::clear(PortalLoginThrottle::codeKeys($user));
    }

    /**
     * `isRateLimited()` ở trên hỏi thẳng `PortalLoginThrottle::MAX_ATTEMPTS`, nên phương thức
     * này không còn nằm trên đường chạy. Giữ lại và cho nó trả về cùng con số để hai câu trả lời
     * không lệch nhau nếu có ai đó — mã của Filament hay mã sau này — hỏi qua đường cũ.
     */
    public function getMaxRateLimiterAttempts(): int
    {
        return PortalLoginThrottle::MAX_ATTEMPTS;
    }
}
