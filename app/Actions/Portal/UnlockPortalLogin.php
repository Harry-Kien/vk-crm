<?php

namespace App\Actions\Portal;

use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\PortalLoginThrottle;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * "Mở khoá đăng nhập" (SPEC §10.3, phát hiện `portal/portal-4`, phán quyết R12): nhân sự xoá
 * khoá đếm CỦA TÀI KHOẢN thay mặt một khách đang gọi điện vì bị khoá tạm sau 5 lần sai. Trước
 * Action này, không có đường nào trong panel nội bộ chạm tới `PortalLoginThrottle` —
 * `RateLimiter::clear()` chỉ được gọi trên đường đăng nhập THÀNH CÔNG của chính khách
 * (`Login::recordSuccessfulLogin()`, `PortalMultiFactorChallenge::clearRateLimiter()`) — nên câu
 * "gọi giúp văn phòng theo số :phone" ở `lang/vi/portal.php` (`portal.login.throttled`) từng là
 * một lời hứa văn phòng không giữ được.
 *
 * CHỈ xoá chiều TÀI KHOẢN (cả hai bước: mật khẩu và mã) — xem
 * `PortalLoginThrottle::clearAccountLocks()`. Chiều ĐỊA CHỈ MẠNG cố ý không đụng tới, đúng lý do
 * đã ghi ở `PortalLoginThrottle::clearPasswordAccount()`: một lần "đăng nhập lại được" do nhân
 * sự thay mặt khách bấm không chứng minh gì về những lần hỏng của người khác trên cùng đường
 * truyền (wifi văn phòng khách, mạng di động NAT). Vì vậy hàm này còn phải TRẢ LỜI cho nhân sự
 * biết chiều IP có còn khoá hay không, để câu thông báo không hứa suông một cánh cổng chỉ mở một
 * nửa — đọc địa chỉ mạng từ chính những dòng nhật ký `login_failed` gần nhất của tài khoản
 * (`Login::auditFailedLogin()`), vì đó là (những) địa chỉ THẬT đã gây ra lần khoá này.
 */
class UnlockPortalLogin
{
    /** Số dòng nhật ký gần nhất để dò địa chỉ mạng — bằng đúng trần SPEC §10.3, đủ phủ một đợt khoá. */
    private const RECENT_FAILURES_TO_CHECK = PortalLoginThrottle::MAX_ATTEMPTS;

    /**
     * @return bool true khi CHIỀU IP của (những) lần hỏng gần nhất vẫn còn khoá — trang gọi hàm
     *              này dùng giá trị trả về để chọn đúng câu báo cho nhân sự.
     */
    public function handle(ClientUser $account, User $actor): bool
    {
        PortalLoginThrottle::clearAccountLocks($account);

        $ipStillLocked = $this->recentFailureIpStillLocked($account);

        Audit::record('portal_login_unlocked', $account, [
            'guard' => 'client',
            'ip_still_locked' => $ipStillLocked,
        ], $actor);

        return $ipStillLocked;
    }

    private function recentFailureIpStillLocked(ClientUser $account): bool
    {
        /** @var Collection<int, string> $ips */
        $ips = Activity::query()
            ->where('event', 'login_failed')
            ->where('causer_type', $account->getMorphClass())
            ->where('causer_id', $account->getKey())
            ->latest('id')
            ->limit(self::RECENT_FAILURES_TO_CHECK)
            ->get()
            ->map(fn (Activity $activity): ?string => $activity->properties->get('ip'))
            ->filter()
            ->unique();

        foreach ($ips as $ip) {
            if (PortalLoginThrottle::tooManyAttempts([
                PortalLoginThrottle::passwordIpKeyFor($ip),
                PortalLoginThrottle::codeIpKeyFor($ip),
            ])) {
                return true;
            }
        }

        return false;
    }
}
