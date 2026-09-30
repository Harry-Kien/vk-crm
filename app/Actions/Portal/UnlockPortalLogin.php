<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ClearsNatSafeIpLocks;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use App\Support\PortalLoginThrottle;

/**
 * "Mở khoá đăng nhập" (SPEC §10.3, phát hiện `portal/portal-4`, phán quyết R12): nhân sự xoá
 * khoá đếm thay mặt một khách đang gọi điện vì bị khoá tạm sau 5 lần sai. Trước Action này, không
 * có đường nào trong panel nội bộ chạm tới `PortalLoginThrottle` — `RateLimiter::clear()` chỉ
 * được gọi trên đường đăng nhập THÀNH CÔNG của chính khách (`Login::recordSuccessfulLogin()`,
 * `PortalMultiFactorChallenge::clearRateLimiter()`) — nên câu "gọi giúp văn phòng theo số :phone"
 * ở `lang/vi/portal.php` (`portal.login.throttled`) từng là một lời hứa văn phòng không giữ được.
 *
 * # Fix round 1 (I2) — hai lỗi của vòng đầu
 *
 * Vòng đầu chỉ xoá chiều TÀI KHOẢN và không bao giờ đụng chiều ĐỊA CHỈ MẠNG. Điều đó làm use case
 * chính không có kết quả: 5 lần sai thật qua form đăng nhập đập CẢ HAI chiều (xem
 * `PortalLoginThrottle::passwordKeys()`), nên xoá một mình chiều tài khoản để lại khách vẫn bị
 * chặn ở chính chiều IP, từ chính máy họ vừa gõ sai. Lỗi thứ hai: bước MÃ không ghi nhật ký
 * `login_failed` nào (`PortalEmailAuthentication` trước bản sửa), nên hàm tra "địa chỉ nào gây ra
 * khoá" không thấy gì và luôn báo "đăng nhập lại được ngay" dù chiều IP của bước mã còn khoá.
 *
 * # Luật xoá chiều IP: chỉ khi NAT-an toàn
 *
 * M8 Task 3: phần này được rút ra thành {@see ClearsNatSafeIpLocks}, dùng chung với
 * `App\Actions\User\UnlockStaffLogin` (cổng nhân sự) — luật, lý lẽ và số phút còn lại nằm ở trait
 * đó, kèm lọc theo guard `client`.
 */
class UnlockPortalLogin
{
    use ClearsNatSafeIpLocks;

    public function handle(ClientUser $account, User $actor): UnlockPortalLoginResult
    {
        PortalLoginThrottle::clearAccountLocks($account);

        [$ipStillLocked, $minutes] = $this->clearSafeIpDimensions($account, PortalLoginThrottle::class, 'client');

        Audit::record('portal_login_unlocked', $account, [
            'guard' => 'client',
            'ip_still_locked' => $ipStillLocked,
        ], $actor);

        return new UnlockPortalLoginResult($ipStillLocked, $minutes);
    }
}
