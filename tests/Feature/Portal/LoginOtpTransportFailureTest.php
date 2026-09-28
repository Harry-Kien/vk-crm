<?php

use App\Filament\Portal\Pages\Auth\Login;
use App\Models\ClientUser;
use App\Support\PortalLoginThrottle;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Task 20 (phát hiện "mã OTP cổng không gửi được vì máy chủ thư lỗi thì trang đăng nhập ném
 * exception"): SendLoginCode gửi THẲNG, không qua hàng đợi (xem docblock của chính lớp đó), nên
 * một transport hỏng ném `Symfony\Component\Mailer\Exception\TransportException` (implements
 * `TransportExceptionInterface`) THẲNG lên `PortalEmailAuthentication::beforeChallenge()` — gọi
 * từ NGAY TRONG `Filament\Auth\Pages\Login::authenticate()` (lớp cha), tức trước bản sửa này,
 * ngoại lệ đó xuyên qua `App\Filament\Portal\Pages\Auth\Login::authenticate()` không bị bắt và
 * trở thành một lỗi 500 tiếng Anh trên trang đăng nhập của khách hàng.
 *
 * `ClosedDoorTransport` của `tests/Feature/Mail/OutboundLedgerTest.php` không dùng lại được ở
 * đây: hai tệp Pest cùng nạp vào một tiến trình, và một `class` toàn cục trùng tên là một lỗi
 * "Cannot redeclare class" — nên lớp transport hỏng ở đây có tên riêng.
 */
class DeadOtpTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP cửa đóng (giả lập Task 20)');
    }

    public function __toString(): string
    {
        return 'dead-otp://';
    }
}

/** Cắm `DeadOtpTransport` vào một mailer có tên, đi đúng đường cấu hình thật của Laravel. */
function deadOtpMailer(): string
{
    config()->set('mail.mailers.dead_otp', ['transport' => 'dead_otp']);
    Mail::extend('dead_otp', fn (): TransportInterface => new DeadOtpTransport);

    return 'dead_otp';
}

beforeEach(function () {
    Filament::setCurrentPanel('portal');
});

it('shows a Vietnamese notice instead of a 500 when the mail transport dies sending the OTP, and does not touch the password lock counter', function () {
    config()->set('mail.default', deadOtpMailer());

    $user = ClientUser::factory()->activated()->create();

    $response = $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    // Không lỗi 500: Livewire không ném ngoại lệ ra ngoài — `authenticate()` trả về êm.
    $response->assertHasNoErrors();

    $response->assertNotified(__('portal.login.code.send_failed'));

    // Bộ đếm khoá của BƯỚC MẬT KHẨU không tăng — khác một lần gõ sai mật khẩu thật,
    // `PortalLoginThrottle::hit()` không được gọi ở nhánh này.
    $keys = PortalLoginThrottle::passwordKeys($user->email);

    foreach ($keys as $key) {
        expect(RateLimiter::attempts($key))->toBe(0);
    }
});

/**
 * Fix round 1 (C1, critical): nút "Gửi lại mã" — `PortalEmailAuthentication::getChallengeFormComponents()`
 * — gọi `sendCode()` bằng một action Livewire RIÊNG, không đi qua `Login::authenticate()`, nên
 * cú bắt `TransportExceptionInterface` ở `Login::authenticate()` (test phía trên) không che được
 * nhánh này. Trước bản sửa round 1, một transport hỏng đúng lúc khách đã ở màn hình nhập mã rồi
 * bấm "Gửi lại mã" vẫn ném 500.
 *
 * Tới được màn hình nhập mã bằng mailer THẬT (mặc định `MAIL_MAILER=array` của `phpunit.xml`, xem
 * `sendLoginCode` — gửi thành công, không qua hàng đợi), rồi mới hỏng transport và bấm lại.
 */
it('shows a Vietnamese notice instead of a 500 when clicking resend during a mail outage, and does not lock the account', function () {
    $user = ClientUser::factory()->activated()->create();

    $component = $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate');

    $component->assertHasNoErrors();

    config()->set('mail.default', deadOtpMailer());

    $component->callAction(TestAction::make('resend')->schemaComponent('email_code.code', 'multiFactorChallengeForm'))
        ->assertHasNoErrors();

    $component->assertNotified(__('portal.login.code.send_failed'));

    // Bộ đếm bước MÃ (theo tài khoản) không tăng — một lần transport hỏng không phải một lần gõ
    // sai mã, và không được bị chặn như thể nó là một lần thử.
    expect(RateLimiter::attempts(PortalLoginThrottle::codeAccountKey($user)))->toBe(0);
});
