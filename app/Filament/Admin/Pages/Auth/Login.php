<?php

namespace App\Filament\Admin\Pages\Auth;

use App\Actions\User\UnlockStaffLogin;
use App\Filament\Admin\Auth\StaffMultiFactorChallenge;
use App\Listeners\RecordStaffLoginFailure;
use App\Models\User;
use App\Support\Audit;
use App\Support\LoginThrottle;
use App\Support\StaffLoginThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Trang đăng nhập panel NỘI BỘ — SPEC §10.3 (5 lần / 15 phút theo email VÀ IP), §10.6, M8 Task 3.
 *
 * Trước task này panel `admin` dùng thẳng `Filament\Auth\Pages\Login`, thứ có hai bộ đếm 60 giây
 * (chỉ theo IP ở bước mật khẩu, chỉ theo tài khoản ở bước mã) — xem docblock
 * {@see LoginThrottle} cho phép đo đầy đủ. Lớp này là bản của cổng nhân sự cho đúng
 * khuôn {@see \App\Filament\Portal\Pages\Auth\Login}: cùng hai chỗ cắm (`rateLimit()` và
 * `isMultiFactorChallengeRateLimited()`), cùng nút cổ chai để đập bộ đếm
 * (`throwFailureValidationException()`), và cùng nguyên tắc **đếm lần HỎNG, không đếm lần thử**.
 * Bước mật khẩu và bước mã có bộ đếm riêng, mỗi bộ hai chiều (tài khoản + IP); đăng nhập thành
 * công chỉ xoá chiều tài khoản.
 *
 * # Nhật ký
 *
 * Lỗi ở BƯỚC MẬT KHẨU được ghi bởi {@see RecordStaffLoginFailure} (nghe sự kiện
 * `Failed` của framework; nay kèm `step = password`). Lỗi ở BƯỚC MÃ không bắn `Failed` (đó là một
 * lỗi xác thực form, không phải lỗi của guard) nên {@see self::authenticate()} bắt nó và ghi
 * `login_failed` với `step = code` kèm IP — cùng phán quyết T7 của cổng khách — để đường mở khoá
 * ({@see UnlockStaffLogin}) đọc được nó.
 *
 * Nhân sự không có câu "gọi số văn phòng": người bị khoá nhờ MỘT quản trị viên KHÁC mở khoá (nút
 * "Mở khoá đăng nhập" trên trang sửa nhân sự), hoặc chờ hết cửa sổ.
 */
class Login extends BaseLogin
{
    /**
     * Cổng bước mã vừa ném lỗi "khoá tạm" — để {@see self::authenticate()} không nhầm nó với một
     * lần gõ sai mã và ghi nhật ký `login_failed` cho một lần thử không hề được chấm.
     */
    private bool $codeStepThrottled = false;

    /**
     * Ghi đè bộ đếm 60 giây / theo IP của `WithRateLimiting` bằng bộ đếm SPEC §10.3. Chỉ KIỂM TRA,
     * không đập: lần đập nằm ở `throwFailureValidationException()`. Lý do đầy đủ, kể cả vì sao
     * `$maxAttempts` được kiểm còn `$decaySeconds` thì không, ở docblock của
     * {@see \App\Filament\Portal\Pages\Auth\Login::rateLimit()}.
     *
     * @param  int  $maxAttempts
     * @param  int|null  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        if ((int) $maxAttempts !== StaffLoginThrottle::MAX_ATTEMPTS) {
            throw new LogicException(
                'Nơi gọi rateLimit() yêu cầu '.$maxAttempts.' lần, nhưng SPEC §10.3 nói '
                .StaffLoginThrottle::MAX_ATTEMPTS.'. Filament đã đổi nơi gọi: đọc lại '
                .'App\Support\LoginThrottle trước khi đổi con số ở đây.'
            );
        }

        $keys = StaffLoginThrottle::passwordKeys($this->submittedEmail());

        if (! StaffLoginThrottle::tooManyAttempts($keys)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.email' => $this->throttledMessage(StaffLoginThrottle::availableInMinutes($keys)),
        ]);
    }

    /**
     * Cổng của bước nhập mã. Lớp cha trả `true` để dựng lại màn hình kèm một toast; ở đây ném lỗi
     * xác thực gắn vào ô đang hiện. Chưa chạm trần thì đập bộ đếm như lớp cha — mỗi lần gửi mã là
     * một lần thử, kể cả lần đúng; chiều tài khoản của lần đúng được xoá ở {@see self::authenticate()}.
     */
    protected function isMultiFactorChallengeRateLimited(Authenticatable $user): bool
    {
        $challenge = $this->getMultiFactorChallenge();

        if ($challenge->isRateLimited($user)) {
            $this->codeStepThrottled = true;

            throw ValidationException::withMessages([
                $this->activeCodeFieldStatePath($user) => $this->throttledMessage(
                    max(1, (int) ceil($challenge->getRateLimiterAvailableInSeconds($user) / 60)),
                ),
            ]);
        }

        $challenge->hitRateLimiter($user);

        return false;
    }

    protected function getMultiFactorChallenge(): MultiFactorChallenge
    {
        return StaffMultiFactorChallenge::make();
    }

    public function authenticate(): ?LoginResponse
    {
        $this->codeStepThrottled = false;

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            $this->auditCodeStepFailure($exception);

            throw $exception;
        }

        if ($response !== null) {
            $this->clearAccountThrottles();
        }

        return $response;
    }

    /**
     * Nút cổ chai của mọi nhánh hỏng ở bước mật khẩu: mật khẩu sai, tài khoản không vào được
     * panel, và lần kiểm lại credentials sau khi mã đã đúng.
     */
    protected function throwFailureValidationException(): never
    {
        StaffLoginThrottle::hit(StaffLoginThrottle::passwordKeys($this->submittedEmail()));

        parent::throwFailureValidationException();
    }

    /**
     * Một `ValidationException` mang khoá dưới `data.multiFactor.` là một lần gõ sai (hoặc bỏ
     * trống) mã ở bước nhập mã — nhánh duy nhất của trang này ném lỗi ở đó, trừ lần "khoá tạm"
     * (`$codeStepThrottled`), thứ không chấm mã nên không phải một lần hỏng. Lỗi ở `data.email`
     * (bước mật khẩu) đã được listener `Failed` ghi, nên không ghi lần thứ hai.
     */
    private function auditCodeStepFailure(ValidationException $exception): void
    {
        if ($this->codeStepThrottled) {
            return;
        }

        $isCodeStep = collect(array_keys($exception->errors()))
            ->contains(fn (string $key): bool => str_starts_with($key, 'data.multiFactor.'));

        if (! $isCodeStep) {
            return;
        }

        $user = $this->getUserUndertakingMultiFactorAuthentication();

        if (! ($user instanceof User)) {
            return;
        }

        Audit::record('login_failed', $user, [
            'guard' => 'web',
            'step' => 'code',
            'ip' => request()->ip(),
        ], $user);
    }

    /**
     * Sau một lần đăng nhập thành công: xoá CHIỀU TÀI KHOẢN của cả hai bộ đếm, không đụng chiều
     * địa chỉ mạng — lý do ở docblock {@see LoginThrottle}.
     */
    private function clearAccountThrottles(): void
    {
        $user = Filament::auth()->user();

        if (! ($user instanceof User)) {
            return;
        }

        StaffLoginThrottle::clearPasswordAccount($this->submittedEmail());
        StaffLoginThrottle::clearCodeAccount($user);
    }

    /** Email vừa gõ vào ô, chưa qua bất kỳ lần tra cứu nào. */
    private function submittedEmail(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    /**
     * Đường dẫn state của ô đang hiện ở bước mã: ô mã khôi phục nếu người dùng đã bấm "dùng mã
     * khôi phục", ngược lại ô mã TOTP. Hỏi provider đang bật thay vì viết cứng `app`.
     */
    private function activeCodeFieldStatePath(Authenticatable $user): string
    {
        $providerId = $this->getMultiFactorChallenge()->getFirstEnabledProvider($user)?->getId() ?? 'app';
        $usingRecoveryCode = (bool) data_get($this->data, 'multiFactor.'.$providerId.'.useRecoveryCode');

        return 'data.multiFactor.'.$providerId.'.'.($usingRecoveryCode ? 'recoveryCode' : 'code');
    }

    private function throttledMessage(int $minutes): string
    {
        return __('users.login.throttled', ['minutes' => $minutes]);
    }
}
