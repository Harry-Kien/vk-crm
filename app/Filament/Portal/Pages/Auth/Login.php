<?php

namespace App\Filament\Portal\Pages\Auth;

use App\Filament\Portal\Auth\PortalMultiFactorChallenge;
use App\Models\ClientUser;
use App\Support\Audit;
use App\Support\PortalLoginThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Trang đăng nhập cổng khách hàng — SPEC §8.1, §10.3, §10.6, §10.10.
 *
 * Luồng ba bước (email + mật khẩu → mã 6 số qua email → nhập mã) là của Filament 5 và giữ
 * nguyên; lớp này chỉ vá ba chỗ SPEC đòi mà bộ có sẵn không làm, cộng một nghĩa vụ nhật ký.
 *
 * # 1. Giới hạn tần suất (SPEC §10.3)
 *
 * Xem `App\Support\PortalLoginThrottle` để biết ba bộ đếm của Filament lệch SPEC ra sao. Ở đây
 * là hai chỗ cắm:
 *
 *  - `rateLimit()` — lớp cha gọi `$this->rateLimit(5)` ngay đầu `authenticate()`, và bản của
 *    trait `WithRateLimiting` khoá theo `sha1(component|method|ip)` với cửa sổ 60 giây. Ghi đè
 *    chính phương thức đó là cách đổi khoá và cửa sổ mà KHÔNG phải chép lại thân
 *    `authenticate()` — một thân hàm chép ra sẽ lặng lẽ lạc hậu ở bản Filament kế tiếp.
 *  - `isMultiFactorChallengeRateLimited()` — cổng của bước nhập mã.
 *
 * **Đếm lần HỎNG, không đếm lần thử.** Lớp cha đập bộ đếm ở mọi lần gọi; ở đây bộ đếm mật khẩu
 * chỉ bị đập trong `throwFailureValidationException()`, tức đúng một lần cho mỗi lần đăng nhập
 * hỏng, và được xoá khi vào được. Một người gõ đúng ngay từ đầu không bao giờ tiến gần tới trần.
 *
 * `throwFailureValidationException()` là chỗ đập vì nó là NÚT CỔ CHAI duy nhất của mọi nhánh
 * hỏng ở bước mật khẩu: mật khẩu sai, tài khoản không mở được panel, và cả lần kiểm lại
 * credentials sau khi mã đã đúng.
 *
 * # 2. Ba câu lỗi phân biệt (SPEC §8)
 *
 * Mã sai và mã hết hạn: xem `App\Filament\Portal\Auth\PortalEmailAuthentication`. Bị khoá tạm:
 * ở `isMultiFactorChallengeRateLimited()` và `rateLimit()` bên dưới.
 *
 * Cả ba câu hiện **ngay dưới ô đang nhập** chứ không phải bằng một toast trôi qua — `getRateLimitedNotification()`
 * của lớp cha vì thế không còn nơi gọi nào sau khi hai phương thức trên đã ghi đè. Cố ý, và ghi
 * ra ở đây để lần sau không ai đi tìm xem nó hỏng chỗ nào: một khách hàng đang lo, đọc trên điện
 * thoại, cần câu trả lời nằm cạnh chỗ họ vừa gõ.
 *
 * # 3. Không tiết lộ tài khoản có tồn tại hay không (SPEC §10.10)
 *
 * Trước khi mật khẩu đúng, mọi đường đi ra đều giống hệt nhau:
 *
 *  - câu lỗi vẫn là `filament-panels::auth/pages/login.messages.failed` của Filament, chung
 *    chung, không đổi theo email;
 *  - khoá của bộ đếm dựng từ **email vừa gõ**, không từ một bản ghi tra ra được, nên email có
 *    thật và email bịa ra bị khoá theo đúng cùng một nhịp và nhận đúng cùng một câu;
 *  - mọi việc lớp này thêm vào (một lần đập bộ đếm, một dòng nhật ký) nằm TRONG `Timebox` của
 *    lớp cha, thứ đệm mọi nhánh hỏng về cùng một khoảng thời gian, nên không tạo ra chênh lệch
 *    thời gian đo được.
 *
 * Ba câu lỗi phân biệt ở mục 2 chỉ sống ở bước nhập mã, tức sau khi mật khẩu đã đúng.
 *
 * # 4. Nhật ký (SPEC §10.6)
 *
 * Ghi cả đăng nhập thành công lẫn thất bại của guard `client`. Người thực hiện lấy từ
 * `$user` mà chính lớp cha đã tra ra và truyền vào `fireFailedEvent()` — KHÔNG tra lại bằng một
 * truy vấn riêng, vì một truy vấn thêm chỉ chạy khi tài khoản có thật là đúng thứ chênh lệch
 * thời gian mà mục 3 vừa đóng lại.
 *
 * Hệ quả phải nói thẳng: khi email gõ vào không ứng với tài khoản nào, dòng nhật ký thất bại
 * không có người thực hiện — không phải vì thiếu sót, mà vì không có `ClientUser` nào để gán.
 * Dòng vẫn được ghi, kèm email đã gõ và địa chỉ mạng, đủ để văn phòng nhìn ra một đợt dò.
 */
class Login extends BaseLogin
{
    /**
     * Ghi đè bộ đếm 60 giây / theo IP của `WithRateLimiting` bằng bộ đếm SPEC §10.3. Cố ý chỉ
     * KIỂM TRA chứ không đập: lần đập nằm ở `throwFailureValidationException()`.
     *
     * Ném `ValidationException` thay vì `TooManyRequestsException` là một lựa chọn về giao diện,
     * không phải một cách bắt lỗi: lớp cha bắt `TooManyRequestsException` rồi đổi thành một
     * toast, còn ở đây câu trả lời phải nằm ngay dưới ô email.
     *
     * @param  int  $maxAttempts
     * @param  int|null  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        $keys = PortalLoginThrottle::passwordKeys($this->submittedEmail());

        if (! PortalLoginThrottle::tooManyAttempts($keys)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.email' => $this->throttledMessage(PortalLoginThrottle::availableInMinutes($keys)),
        ]);
    }

    /**
     * Cổng của bước nhập mã. Lớp cha trả `true` để dựng lại màn hình kèm một toast; ở đây ném
     * lỗi xác thực gắn thẳng vào ô mã, vì đó là ô khách đang nhìn.
     *
     * Khi chưa chạm trần thì vẫn đập bộ đếm đúng như lớp cha — mỗi lần gửi mã là một lần thử,
     * kể cả lần đúng; lần đúng được xoá sạch ở `recordSuccessfulLogin()`.
     */
    protected function isMultiFactorChallengeRateLimited(Authenticatable $user): bool
    {
        $challenge = $this->getMultiFactorChallenge();

        if ($challenge->isRateLimited($user)) {
            throw ValidationException::withMessages([
                $this->codeFieldStatePath($user) => $this->throttledMessage(
                    max(1, (int) ceil($challenge->getRateLimiterAvailableInSeconds($user) / 60)),
                ),
            ]);
        }

        $challenge->hitRateLimiter($user);

        return false;
    }

    protected function getMultiFactorChallenge(): MultiFactorChallenge
    {
        return PortalMultiFactorChallenge::make();
    }

    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        if ($response !== null) {
            $this->recordSuccessfulLogin();
        }

        return $response;
    }

    /**
     * Nút cổ chai của mọi nhánh hỏng ở bước mật khẩu — xem docblock của lớp.
     */
    protected function throwFailureValidationException(): never
    {
        PortalLoginThrottle::hit(PortalLoginThrottle::passwordKeys($this->submittedEmail()));

        parent::throwFailureValidationException();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function fireFailedEvent(Guard $guard, ?Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        parent::fireFailedEvent($guard, $user, $credentials);

        $clientUser = $user instanceof ClientUser ? $user : null;

        // `$credentials` mang mật khẩu vừa gõ; cố ý không chạm vào nó, email lấy từ state của form.
        Audit::record('login_failed', $clientUser, [
            'guard' => 'client',
            'email' => $this->submittedEmail(),
            'ip' => request()->ip(),
        ], $clientUser);
    }

    /**
     * SPEC §4.3 (`last_login_at`, `last_login_ip`) và §10.6 (dòng nhật ký đăng nhập thành công).
     *
     * `forceFill()->save()` KHÔNG sinh thêm một dòng activity log thứ hai: `getActivitylogOptions()`
     * của `ClientUser` không liệt kê hai cột này, và `dontSubmitEmptyLogs()` bỏ qua lần lưu không
     * có thuộc tính nào được theo dõi thay đổi.
     */
    private function recordSuccessfulLogin(): void
    {
        $user = Filament::auth()->user();

        if (! ($user instanceof ClientUser)) {
            return;
        }

        PortalLoginThrottle::clear(PortalLoginThrottle::passwordKeys($this->submittedEmail()));

        $challenge = $this->getMultiFactorChallenge();

        if ($challenge instanceof PortalMultiFactorChallenge) {
            $challenge->clearRateLimiter($user);
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->save();

        Audit::record('login_success', $user, [
            'guard' => 'client',
            'ip' => request()->ip(),
        ], $user);
    }

    /**
     * Email **vừa gõ vào ô**, chưa qua bất kỳ lần tra cứu nào — xem mục 3 của docblock lớp.
     */
    private function submittedEmail(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * Đường dẫn state của ô nhập mã, hỏi chính provider đang bật thay vì viết cứng `email_code`:
     * id của provider là của Filament, không phải của dự án này.
     */
    private function codeFieldStatePath(Authenticatable $user): string
    {
        $provider = $this->getMultiFactorChallenge()->getFirstEnabledProvider($user);

        return 'data.multiFactor.'.($provider?->getId() ?? 'email_code').'.code';
    }

    private function throttledMessage(int $minutes): string
    {
        return __('portal.login.throttled', [
            'minutes' => $minutes,
            'phone' => config('vkcrm.brand.hotline'),
        ]);
    }
}
