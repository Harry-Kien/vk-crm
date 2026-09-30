<?php

namespace App\Actions\Concerns;

use App\Actions\Portal\UnlockPortalLogin;
use App\Actions\User\UnlockStaffLogin;
use App\Support\LoginThrottle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Luật "xoá chiều IP của khoá đăng nhập, nhưng chỉ khi NAT-an toàn" — dùng chung cho hai đường mở
 * khoá: {@see UnlockPortalLogin} (khách, guard `client`) và
 * {@see UnlockStaffLogin} (nhân sự, guard `web`; M8 Task 3). Được rút ra khỏi
 * `UnlockPortalLogin` để hai cổng không có hai bản chép của cùng một luật an toàn; toàn bộ lý lẽ
 * dưới đây được viết một lần, ở đây.
 *
 * # Vì sao không xoá thẳng chiều IP
 *
 * 5 lần sai thật qua form đăng nhập đập CẢ HAI chiều (xem `LoginThrottle::passwordKeys()`), nên
 * xoá một mình chiều tài khoản để lại người dùng vẫn bị chặn ở chiều IP, từ chính máy họ vừa gõ
 * sai. Nhưng xoá chiều IP vô điều kiện thì một địa chỉ dùng chung (NAT của nhà mạng, wifi văn
 * phòng) mất trần 5 lần của những người khác.
 *
 * # Luật
 *
 * `clearSafeIpDimensions()` tự tra lại nhật ký `login_failed` **của đúng guard này** (cả bước mật
 * khẩu lẫn bước mã, phân biệt bằng `step`) trong TOÀN BỘ cửa sổ còn hiệu lực
 * (`LoginThrottle::DECAY_SECONDS`, không giới hạn ở 5 dòng gần nhất — một địa chỉ dùng chung có
 * thể tích luỹ nhiều hơn 5 dòng từ nhiều tài khoản khác nhau trong cùng cửa sổ). Với mỗi cặp (bước,
 * địa chỉ) mà CHÍNH tài khoản đang mở khoá từng gõ sai:
 *
 *  - nếu MỌI dòng `login_failed` của guard này ở đúng địa chỉ đó, trong đúng cửa sổ đó, đều thuộc về
 *    CHÍNH tài khoản này (không dòng nào của người khác, không dòng nào không rõ ai) — địa chỉ đó
 *    không phải một NAT dùng chung, nên xoá luôn khoá IP của cặp đó ({@see LoginThrottle::clearKey()});
 *  - ngược lại giữ nguyên khoá đó và báo cho người bấm biết, kèm số phút còn lại thật.
 *
 * **Lọc theo guard** (M8 Task 3, thêm khi nhân sự vào cùng luật): hai guard có rổ đếm khác nhau
 * (`portal-login-ip:` vs `staff-login-ip:`), nên một dòng của guard KIA ở cùng địa chỉ không phải
 * bằng chứng rằng rổ của guard NÀY đang dùng chung — nó không tiêu lượt nào trong rổ này.
 */
trait ClearsNatSafeIpLocks
{
    /**
     * @param  class-string<LoginThrottle>  $throttle  Bộ đếm của guard (PortalLoginThrottle / StaffLoginThrottle).
     * @param  string  $guard  Giá trị `properties.guard` của dòng `login_failed` (`client` / `web`).
     * @return array{0: bool, 1: int|null} [chiều IP còn khoá không, số phút còn lại nếu còn]
     */
    private function clearSafeIpDimensions(Model $account, string $throttle, string $guard): array
    {
        $windowStart = now()->subSeconds($throttle::DECAY_SECONDS);

        /** @var Collection<int, Activity> $recentFailures Mọi lần hỏng của guard này, của MỌI tài khoản, trong cửa sổ còn hiệu lực — cần cả tập này để xét NAT-an toàn cho từng địa chỉ, không chỉ tập của riêng $account. */
        $recentFailures = Activity::query()
            ->where('event', 'login_failed')
            ->where('created_at', '>=', $windowStart)
            ->get()
            ->filter(fn (Activity $activity): bool => $activity->properties->get('guard') === $guard);

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
                ? $throttle::codeIpKeyFor($dimension['ip'])
                : $throttle::passwordIpKeyFor($dimension['ip']);

            if (! $throttle::tooManyAttempts([$ipKey])) {
                // Chiều IP của cặp này không (còn) khoá — không có gì để xoá hay để báo.
                continue;
            }

            $sameDimension = $recentFailures->filter(
                fn (Activity $activity): bool => $activity->properties->get('step') === $dimension['step']
                    && $activity->properties->get('ip') === $dimension['ip'],
            );

            $isNatSafe = $sameDimension->isNotEmpty() && $sameDimension->every($isThisAccount);

            if ($isNatSafe) {
                $throttle::clearKey($ipKey);

                continue;
            }

            $ipStillLocked = true;
            $minutes = max($minutes ?? 0, $throttle::availableInMinutes([$ipKey]));
        }

        return [$ipStillLocked, $minutes];
    }
}
