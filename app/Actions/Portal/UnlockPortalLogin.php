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
 * # Luật mới: xoá chiều IP, nhưng chỉ khi NAT-an toàn
 *
 * `clearSafeIpDimensions()` tự tra lại nhật ký `login_failed` (cả bước mật khẩu lẫn bước mã, phân
 * biệt bằng `step` — xem `Login::auditFailedLogin()` và
 * `PortalEmailAuthentication::auditCodeFailure()`) trong TOÀN BỘ cửa sổ còn hiệu lực
 * (`PortalLoginThrottle::DECAY_SECONDS`, không giới hạn ở 5 dòng gần nhất — một địa chỉ dùng
 * chung có thể tích luỹ nhiều hơn 5 dòng từ nhiều tài khoản khác nhau trong cùng cửa sổ). Với mỗi
 * cặp (bước, địa chỉ) mà CHÍNH tài khoản đang mở khoá từng gõ sai:
 *
 *  - nếu MỌI dòng `login_failed` ghi nhận ở đúng địa chỉ đó, trong đúng cửa sổ đó, đều thuộc về
 *    CHÍNH tài khoản này (không có dòng nào của người khác, không có dòng nào không rõ ai) — địa
 *    chỉ đó không phải một NAT dùng chung, nên xoá luôn khoá IP của cặp (bước, địa chỉ) đó
 *    (`PortalLoginThrottle::clearKey()`);
 *  - ngược lại — có ít nhất một dòng của người khác cùng địa chỉ, trong cùng cửa sổ — giữ nguyên
 *    khoá đó (không đụng tới, đúng nguyên tắc NAT-an toàn đã có từ đầu) và báo cho nhân sự biết,
 *    kèm số phút còn lại thật (`PortalLoginThrottle::availableInMinutes()`), để câu trả lời không
 *    hứa suông một cánh cổng chỉ mở một nửa.
 */
class UnlockPortalLogin
{
    public function handle(ClientUser $account, User $actor): UnlockPortalLoginResult
    {
        PortalLoginThrottle::clearAccountLocks($account);

        $result = $this->clearSafeIpDimensions($account);

        Audit::record('portal_login_unlocked', $account, [
            'guard' => 'client',
            'ip_still_locked' => $result->ipStillLocked,
        ], $actor);

        return $result;
    }

    private function clearSafeIpDimensions(ClientUser $account): UnlockPortalLoginResult
    {
        $windowStart = now()->subSeconds(PortalLoginThrottle::DECAY_SECONDS);

        /** @var Collection<int, Activity> $recentFailures Mọi lần hỏng, của MỌI tài khoản, trong cửa sổ còn hiệu lực — cần cả tập này để xét NAT-an toàn cho từng địa chỉ, không chỉ tập của riêng $account. */
        $recentFailures = Activity::query()
            ->where('event', 'login_failed')
            ->where('created_at', '>=', $windowStart)
            ->get();

        $isThisAccount = fn (Activity $activity): bool => $activity->causer_type === $account->getMorphClass()
            && $activity->causer_id !== null
            && (string) $activity->causer_id === (string) $account->getKey();

        /** @var Collection<int, array{step: string, ip: string}> $dimensions Các cặp (bước, địa chỉ) mà CHÍNH tài khoản này từng gõ sai. */
        $dimensions = $recentFailures
            ->filter($isThisAccount)
            ->map(fn (Activity $activity): array => [
                'step' => (string) $activity->properties->get('step'),
                'ip' => (string) $activity->properties->get('ip'),
            ])
            ->filter(fn (array $d): bool => $d['step'] !== '' && $d['ip'] !== '')
            ->unique(fn (array $d): string => $d['step'].'|'.$d['ip']);

        $ipStillLocked = false;
        $minutes = null;

        foreach ($dimensions as $dimension) {
            $ipKey = $dimension['step'] === 'code'
                ? PortalLoginThrottle::codeIpKeyFor($dimension['ip'])
                : PortalLoginThrottle::passwordIpKeyFor($dimension['ip']);

            if (! PortalLoginThrottle::tooManyAttempts([$ipKey])) {
                // Chiều IP của cặp này không (còn) khoá — không có gì để xoá hay để báo.
                continue;
            }

            $sameDimension = $recentFailures->filter(
                fn (Activity $activity): bool => $activity->properties->get('step') === $dimension['step']
                    && $activity->properties->get('ip') === $dimension['ip'],
            );

            $isNatSafe = $sameDimension->isNotEmpty() && $sameDimension->every($isThisAccount);

            if ($isNatSafe) {
                PortalLoginThrottle::clearKey($ipKey);

                continue;
            }

            $ipStillLocked = true;
            $minutes = max($minutes ?? 0, PortalLoginThrottle::availableInMinutes([$ipKey]));
        }

        return new UnlockPortalLoginResult($ipStillLocked, $minutes);
    }
}
