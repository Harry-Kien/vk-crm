<?php

use App\Filament\Portal\Auth\PortalEmailAuthentication;
use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Filament\Portal\Pages\Auth\Login;
use App\Filament\Portal\Pages\MyMatters;
use App\Models\ClientUser;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use App\Support\PortalLoginThrottle;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Đăng nhập cổng khách hàng — SPEC §8.1, §10.3, §10.6, §10.9, §10.10.
 *
 * Hai cách lái khác nhau trong tệp này, và sự khác nhau là có lý do chứ không phải thói quen:
 *
 *  - Phần lớn test lái component Livewire thẳng bằng `livewire(Login::class)`, vì thứ cần đo là
 *    hành vi bên trong trang.
 *  - Các test giới hạn tần suất lái bằng **request HTTP thật** tới đường cập nhật của Livewire.
 *    Bắt buộc phải vậy: `Livewire\Features\SupportTesting\RequestBroker` dựng request bằng
 *    `Request::create()` và ghi đè `serverVariables` bằng đúng một header `X-Livewire`, nên
 *    trong `livewire()` thì `request()->ip()` LUÔN là 127.0.0.1 và một test "đổi IP" ở đó sẽ là
 *    một test không đổi gì cả. Đi bằng `postJson` thì `withServerVariables(['REMOTE_ADDR' => …])`
 *    tới được thật — và đó là điều kiện để chứng minh CẢ HAI chiều khoá của SPEC §10.3.
 */
beforeEach(function () {
    Notification::fake();
    Filament::setCurrentPanel('portal');
});

/** Tài khoản đã qua bước đổi mật khẩu lần đầu, để các test khác không phải đi qua cánh cổng đó. */
function portalUser(array $attributes = []): ClientUser
{
    return ClientUser::factory()->activated()->create($attributes);
}

/**
 * Bước một: email + mật khẩu. Trả về component để test đi tiếp sang bước nhập mã.
 */
function submitPortalPassword(ClientUser $user, string $password = 'password'): Testable
{
    return test()->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', $password)
        ->call('authenticate');
}

/**
 * Mọi mã đã thật sự gửi tới hộp thư của một tài khoản, theo thứ tự gửi. Đọc từ chính đối tượng
 * notification chứ không từ một chỗ nào khác, nên nếu mã trong thư khác mã trong phiên thì test
 * đỏ.
 *
 * @return array<int, string>
 */
function portalCodesSentTo(ClientUser $user): array
{
    return Notification::sent($user, SendLoginCode::class)
        ->map(fn (SendLoginCode $notification): string => $notification->code())
        ->values()
        ->all();
}

function portalCodeStatePath(): string
{
    return 'data.multiFactor.email_code.code';
}

/** Snapshot Livewire của một component đã render, để gửi lại bằng request HTTP thật. */
function portalSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("Không có snapshot Livewire của [{$component}] trong HTML đã render.");
}

function portalLoginSnapshot(): string
{
    return portalSnapshot(test()->get('/portal/login')->assertOk()->getContent(), Login::class);
}

/**
 * Một lần gửi form đăng nhập bằng request HTTP thật, từ một địa chỉ mạng cho trước.
 *
 * @param  array<string, mixed>  $updates
 */
function postPortalLogin(string $snapshot, array $updates, string $ip): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
            ]],
        ]);
}

/**
 * **Màn hình nhập mã gọi khách là "bạn" trong khi mọi dòng khác của cổng gọi "anh/chị".**
 *
 * Hai chuỗi ấy đến từ bản `vi` bundled của Filament (`filament-panels::auth/pages/login`:
 * `multi_factor.subheading` và `multi_factor.form.provider.label`), chứ không từ `lang/vi/`. Với
 * một văn phòng luật ở Việt Nam thì đây không phải chuyện văn phong: xưng hô là chuyện lễ độ với
 * một người vừa đem việc của mình tới, và nó lệch ngay ở màn hình ĐẦU TIÊN của toàn hệ thống.
 *
 * # Điểm mù mà chỗ này phơi ra, ghi lại đúng nơi người sau sẽ tìm
 *
 * `LocalizationTest` được viết lại ở mốc này để bắt khoá THIẾU và khoá còn nguyên tiếng Anh: nó
 * đi từ phía `en` của mỗi gói và hỏi bộ dịch xem `vi` trả về gì. Cấu trúc ấy **không thể** nhìn
 * thấy một chuỗi bundled đã có bản `vi`, dịch đúng nghĩa, nhưng SAI XƯNG HÔ — với nó, khoá ấy đã
 * xong. Nên lớp lưới đó không mở rộng ra được bằng cách sửa vài dòng; thứ bắt được loại lỗi này
 * là một test RENDER một màn hình thật rồi đọc chữ trên đó, và test dưới đây là cái đầu tiên.
 * Mọi màn hình cổng khác nên có một dòng như vậy khi ai đó đi qua chúng.
 */
it('speaks to the client as anh/chị on the one time code screen, like every other line of the portal', function () {
    $user = portalUser();

    $html = submitPortalPassword($user)->html();

    // Tiền đề: đây đúng là màn hình nhập mã, chứ không phải bước mật khẩu vẽ lại.
    expect($html)->toContain(__('portal.login.code.label'));

    expect($html)
        ->toContain(__('filament-panels::auth/pages/login.multi_factor.subheading'))
        ->and(__('filament-panels::auth/pages/login.multi_factor.subheading'))->toContain('anh/chị')
        ->and(__('filament-panels::auth/pages/login.multi_factor.form.provider.label'))->toContain('nh/chị');

    // Và không một chữ "bạn" nào còn sót trên chính trang đã render.
    expect(preg_match('/\bbạn\b/iu', $html))->toBe(0, 'màn hình nhập mã vẫn còn xưng hô "bạn"');
});

/**
 * Lỗi xác thực mà component trả về. Livewire đáp 200 và đặt chúng vào `memo.errors` của snapshot
 * chứ không trả 422, nên phải mở snapshot ra đọc.
 *
 * @return array<string, array<int, string>>
 */
function portalLoginErrors(TestResponse $response): array
{
    $response->assertOk();

    $snapshot = json_decode(json_decode($response->getContent(), true)['components'][0]['snapshot'], true);

    return $snapshot['memo']['errors'] ?? [];
}

function portalThrottleMessage(int $minutes): string
{
    return __('portal.login.throttled', [
        'minutes' => $minutes,
        'phone' => config('vkcrm.brand.hotline'),
    ]);
}

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — mã một lần là bắt buộc và không tắt được
|--------------------------------------------------------------------------
*/

it('does not sign a client in on a correct password alone — it sends a code and waits', function () {
    $user = portalUser();

    submitPortalPassword($user)->assertHasNoErrors();

    expect(auth('client')->check())->toBeFalse()
        ->and(portalCodesSentTo($user))->toHaveCount(1)
        ->and(portalCodesSentTo($user)[0])->toMatch('/^\d{6}$/');
});

it('signs the client in once the code from the email is entered', function () {
    $user = portalUser();

    submitPortalPassword($user)
        ->set(portalCodeStatePath(), portalCodesSentTo($user)[0])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth('client')->check())->toBeTrue()
        ->and(auth('client')->id())->toBe($user->getKey());
});

it('leaves a client no way to turn the one-time code off', function () {
    $user = portalUser();

    expect($user->hasEmailAuthentication())->toBeTrue()
        ->and(fn () => $user->toggleEmailAuthentication(false))->toThrow(LogicException::class)
        // `DisableEmailAuthenticationAction` chỉ sống trên trang hồ sơ cá nhân, và trang
        // "bắt buộc cài đặt xác thực hai bước" là đường duy nhất còn lại chạm tới bộ MFA.
        // Không route nào trong hai cái đó tồn tại trên panel này.
        ->and(Route::has('filament.portal.auth.profile'))->toBeFalse()
        ->and(Route::has('filament.portal.auth.multi-factor-authentication.set-up-required'))->toBeFalse()
        ->and(Filament::getPanel('portal')->getMultiFactorAuthenticationProviders())
        ->toHaveKey('email_code');
});

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — mã sống 5 phút, không phải 4 (mặc định của Filament)
|--------------------------------------------------------------------------
*/

it('still accepts the code after four and a half minutes, which four-minute expiry would not', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $code = portalCodesSentTo($user)[0];

    $this->travel(4)->minutes();
    $this->travel(30)->seconds();

    $component->set(portalCodeStatePath(), $code)->call('authenticate')->assertHasNoErrors();

    expect(auth('client')->check())->toBeTrue();
});

it('refuses the code after five minutes, and says to ask for a new one', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $code = portalCodesSentTo($user)[0];

    $this->travel(5)->minutes();
    $this->travel(30)->seconds();

    $component->set(portalCodeStatePath(), $code)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath()]);

    expect(auth('client')->check())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — mã dùng một lần
|--------------------------------------------------------------------------
*/

it('burns the code the moment it is used', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $code = portalCodesSentTo($user)[0];

    $provider = app(PortalEmailAuthentication::class);

    expect($provider->isCodeUnusable($user))->toBeFalse();

    $component->set(portalCodeStatePath(), $code)->call('authenticate')->assertHasNoErrors();

    expect($provider->isCodeUnusable($user))->toBeTrue()
        ->and($provider->verifyCode($code, $user))->toBeFalse();
});

it('refuses a code that was already used, on the next sign-in', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $usedCode = portalCodesSentTo($user)[0];

    $component->set(portalCodeStatePath(), $usedCode)->call('authenticate')->assertHasNoErrors();
    expect(auth('client')->check())->toBeTrue();

    auth('client')->logout();

    $second = submitPortalPassword($user);
    $codes = portalCodesSentTo($user);

    expect($codes)->toHaveCount(2)
        ->and($codes[1])->not->toBe($usedCode);

    $second->set(portalCodeStatePath(), $usedCode)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath()]);

    expect(auth('client')->check())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| SPEC §11 — mã của tài khoản này không mở tài khoản kia
|--------------------------------------------------------------------------
*/

it('will not open account B with a code issued for account A', function () {
    $alice = portalUser();
    $bob = portalUser();

    submitPortalPassword($alice);
    $aliceCode = portalCodesSentTo($alice)[0];

    submitPortalPassword($bob)
        ->set(portalCodeStatePath(), $aliceCode)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath()]);

    expect(auth('client')->check())->toBeFalse();
});

it('opens account B with the code issued for account B', function () {
    $alice = portalUser();
    $bob = portalUser();

    submitPortalPassword($alice);

    submitPortalPassword($bob)
        ->set(portalCodeStatePath(), portalCodesSentTo($bob)[0])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth('client')->id())->toBe($bob->getKey());
});

/*
|--------------------------------------------------------------------------
| SPEC §8 — ba câu lỗi phân biệt
|--------------------------------------------------------------------------
*/

it('says something different for a mistyped code than for a code that has gone stale', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $code = portalCodesSentTo($user)[0];

    $mistyped = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

    $component->set(portalCodeStatePath(), $mistyped)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath() => __('portal.login.code.invalid')]);

    $this->travel(6)->minutes();

    $component->set(portalCodeStatePath(), $code)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath() => __('portal.login.code.expired')]);

    expect(__('portal.login.code.invalid'))->not->toBe(__('portal.login.code.expired'));
});

it('tells a locked-out client how long to wait and gives them a phone number', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $realCode = portalCodesSentTo($user)[0];

    foreach (range(1, 5) as $ignored) {
        $component->set(portalCodeStatePath(), '000000')->call('authenticate');
    }

    $component->set(portalCodeStatePath(), $realCode)
        ->call('authenticate')
        ->assertHasErrors([portalCodeStatePath() => portalThrottleMessage(15)]);

    expect(auth('client')->check())->toBeFalse()
        ->and(portalThrottleMessage(15))->toContain((string) config('vkcrm.brand.hotline'));
});

/*
|--------------------------------------------------------------------------
| SPEC §10.3 — 5 lần / 15 phút, theo CẢ email lẫn IP
|--------------------------------------------------------------------------
*/

it('blocks a sixth attempt on the same email even from a brand-new address', function () {
    $user = portalUser(['email' => 'nan@example.test']);
    $snapshot = portalLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        expect(portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => $user->email,
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.10')))->toHaveKey('data.email');
    }

    // Cùng email, một địa chỉ mạng CHƯA từng thử lần nào: chỉ chiều email chặn được.
    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => $user->email,
        'data.password' => 'sai-mat-khau',
    ], '203.0.113.77'))['data.email'][0])->toBe(portalThrottleMessage(15));

    // Chứng minh cái chặn đến từ khoá EMAIL chứ không phải từ một cái khoá chung: một email khác
    // từ chính địa chỉ mạng mới đó vẫn đi tới được cổng mật khẩu.
    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'nguoi-khac@example.test',
        'data.password' => 'sai-mat-khau',
    ], '203.0.113.77'))['data.email'][0])
        ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
});

it('blocks a sixth attempt from the same address even on a brand-new email', function () {
    $snapshot = portalLoginSnapshot();

    // Năm email KHÁC NHAU: không khoá email nào chạm tới 2 lần, nên chỉ chiều IP đếm được tới 5.
    foreach (range(1, 5) as $index) {
        expect(portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => "khach-{$index}@example.test",
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.20')))->toHaveKey('data.email');
    }

    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khach-6@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.20'))['data.email'][0])->toBe(portalThrottleMessage(15));

    // Cùng email mới đó từ một địa chỉ mạng khác thì không bị chặn — cái chặn ở trên là chiều IP.
    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khach-6@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.21'))['data.email'][0])
        ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
});

it('keeps the lock for fifteen minutes, not for the sixty seconds Filament would use', function () {
    $snapshot = portalLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        postPortalLogin($snapshot, [
            'data.email' => 'khoa@example.test',
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.30');
    }

    $this->travel(5)->minutes();

    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khoa@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.30'))['data.email'][0])->toBe(portalThrottleMessage(10));

    $this->travel(11)->minutes();

    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khoa@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.30'))['data.email'][0])
        ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
});

it('counts failures, not attempts — signing in clears the count on that email', function () {
    $user = portalUser();

    foreach (range(1, 4) as $ignored) {
        $this->livewire(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'sai-mat-khau')
            ->call('authenticate');
    }

    expect(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::passwordKeys($user->email)))->toBeFalse();

    $component = submitPortalPassword($user);
    $component->set(portalCodeStatePath(), portalCodesSentTo($user)[0])->call('authenticate');

    expect(auth('client')->check())->toBeTrue()
        ->and(RateLimiter::attempts(PortalLoginThrottle::passwordAccountKey($user->email)))->toBe(0);

    // `Login::mount()` chuyển hướng ngay khi đã có phiên, nên phải ra khỏi phiên trước rồi mới
    // dựng lại được trang đăng nhập.
    auth('client')->logout();

    // Chiều ĐỊA CHỈ MẠNG cố ý KHÔNG được lần đăng nhập vừa rồi xoá — đó là điều test "never lets
    // one client sign-in clear the address lock everyone else shares" đo. Ở đây phải tự tay gỡ
    // nó ra, nếu không câu hỏi "chiều email đã được xoá chưa" sẽ bị chiều IP trả lời hộ và bốn
    // lần thử bên dưới chạm trần vì một lý do khác hẳn.
    expect(RateLimiter::attempts(PortalLoginThrottle::passwordIpKey()))->toBe(4);
    RateLimiter::clear(PortalLoginThrottle::passwordIpKey());

    // Bộ đếm theo email đã được xoá: bốn lần hỏng trước đó không còn tính vào lần đăng nhập kế tiếp.
    foreach (range(1, 4) as $ignored) {
        $this->livewire(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'sai-mat-khau')
            ->call('authenticate')
            ->assertHasErrors(['data.email' => __('filament-panels::auth/pages/login.messages.failed')]);
    }
});

/**
 * Bước nhập MÃ cũng phải khoá theo cả hai chiều, và nó có bộ đếm riêng (lý do ở
 * `PortalLoginThrottle`). Đo thẳng trên bộ đếm thay vì lái sáu lần đăng nhập đầy đủ qua HTTP:
 * ở đây thứ cần chứng minh là hai chiều khoá tồn tại và độc lập, còn việc bộ đếm ấy thật sự
 * được gọi trong luồng đăng nhập thì test "tells a locked-out client how long to wait" ở trên
 * đã đi hết đường thật.
 *
 * Chiều IP đọc `request()->ip()`, thứ trong tiến trình test luôn là một địa chỉ. Một địa chỉ
 * khác vì vậy được biểu diễn bằng đúng thứ nó là ở tầng bộ đếm: một khoá khác, chưa có lần thử
 * nào.
 */
it('locks the code step by account and by address, as two independent conditions', function () {
    $alice = portalUser();
    $bob = portalUser();

    [$aliceAccountKey, $ipKey] = PortalLoginThrottle::codeKeys($alice);
    [$bobAccountKey, $bobIpKey] = PortalLoginThrottle::codeKeys($bob);

    expect($aliceAccountKey)->not->toBe($bobAccountKey)
        ->and($bobIpKey)->toBe($ipKey);

    // Chiều TÀI KHOẢN: năm lần hỏng của Alice, rồi xoá chiều IP — tức Alice đổi sang một địa chỉ
    // chưa từng thử lần nào. Vẫn phải bị chặn.
    foreach (range(1, 5) as $ignored) {
        PortalLoginThrottle::hit(PortalLoginThrottle::codeKeys($alice));
    }

    RateLimiter::clear($ipKey);

    expect(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::codeKeys($alice)))->toBeTrue()
        // …và Bob, ở cùng địa chỉ sạch đó, KHÔNG bị chặn lây: chặn ở trên đến từ chiều tài khoản.
        ->and(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::codeKeys($bob)))->toBeFalse();

    // Chiều ĐỊA CHỈ: năm lần hỏng dồn lên khoá IP, tài khoản của Bob vẫn sạch. Bob vẫn bị chặn.
    RateLimiter::clear($aliceAccountKey);

    foreach (range(1, 5) as $ignored) {
        RateLimiter::hit($ipKey, PortalLoginThrottle::DECAY_SECONDS);
    }

    expect(RateLimiter::attempts($bobAccountKey))->toBe(0)
        ->and(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::codeKeys($bob)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SPEC §10.10 — trước khi mật khẩu đúng, không câu nào tiết lộ tài khoản có tồn tại
|--------------------------------------------------------------------------
*/

it('answers an unknown email exactly as it answers a wrong password', function () {
    $user = portalUser(['email' => 'co-that@example.test']);
    $snapshot = portalLoginSnapshot();

    $existing = portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => $user->email,
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.40'));

    $unknown = portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khong-he-co@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.41'));

    expect($unknown)->toBe($existing)
        ->and($existing['data.email'][0])->toBe(__('filament-panels::auth/pages/login.messages.failed'))
        ->and($existing['data.email'][0])->not->toContain($user->email);
});

it('locks an email that does not exist on exactly the same terms as one that does', function () {
    $user = portalUser(['email' => 'co-that-2@example.test']);
    $snapshot = portalLoginSnapshot();

    foreach (['co-that-2@example.test', 'khong-he-co-2@example.test'] as $index => $email) {
        $ip = '198.51.100.'.(50 + $index);

        foreach (range(1, 5) as $ignored) {
            postPortalLogin($snapshot, ['data.email' => $email, 'data.password' => 'sai'], $ip);
        }

        expect(portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => $email,
            'data.password' => 'sai',
        ], $ip))['data.email'][0])->toBe(portalThrottleMessage(15));
    }

    expect($user->fresh()->is_active)->toBeTrue();
});

it('keeps the three specific code messages out of the password step entirely', function () {
    $snapshot = portalLoginSnapshot();

    $errors = portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'khong-he-co-3@example.test',
        'data.password' => 'sai',
    ], '198.51.100.60'));

    expect($errors['data.email'][0])
        ->not->toBe(__('portal.login.code.invalid'))
        ->not->toBe(__('portal.login.code.expired'))
        ->not->toContain('tài khoản');
});

/*
|--------------------------------------------------------------------------
| SPEC §10.6 — nhật ký đăng nhập của guard `client`
|--------------------------------------------------------------------------
*/

it('writes a successful portal sign-in to the activity log, caused by the client user', function () {
    $user = portalUser();

    submitPortalPassword($user)
        ->set(portalCodeStatePath(), portalCodesSentTo($user)[0])
        ->call('authenticate');

    $activity = Activity::query()->where('event', 'login_success')->sole();

    expect($activity->causer)->toBeInstanceOf(ClientUser::class)
        ->and($activity->causer->is($user))->toBeTrue()
        ->and($activity->subject)->toBeInstanceOf(ClientUser::class)
        ->and($activity->properties['guard'] ?? null)->toBe('client');

    $user->refresh();

    expect($user->last_login_at)->not->toBeNull()
        ->and($user->last_login_ip)->not->toBeNull()
        // Nhân chứng cho câu trong docblock của `Login::recordSuccessfulLogin()`: việc ghi
        // `last_login_*` KHÔNG sinh thêm một dòng `updated` thứ hai, vì `ClientUser::getActivitylogOptions()`
        // không liệt kê hai cột đó và `dontSubmitEmptyLogs()` bỏ qua lần lưu không có thay đổi
        // nào được theo dõi. Không có dòng này thì câu docblock chỉ là một lời hứa.
        ->and(Activity::query()->where('event', 'updated')->count())->toBe(0);
});

/**
 * Vì sao dòng nhật ký phải mang người thực hiện **tường minh** chứ không để `Audit::record()` tự
 * suy ra từ phiên đang mở: helper đó ưu tiên guard `web` (`auth('web')->user() ?? auth('client')->user()`),
 * nên trong một trình duyệt đang đồng thời mở `/admin` — đúng tình huống của người trong văn
 * phòng khi demo cổng, và của một máy dùng chung — lần đăng nhập của KHÁCH sẽ bị ghi là do nhân
 * sự thực hiện. Một nhật ký nói sai ai làm gì còn tệ hơn một nhật ký trống.
 *
 * Test này là thứ duy nhất phân biệt được hai cơ chế: bỏ đối số `$causer` ở `recordSuccessfulLogin()`
 * thì mọi test khác vẫn xanh (phiên khách đang mở nên suy luận vẫn ra đúng người) — đã đo bằng
 * đột biến — và chỉ dòng này đỏ.
 */
it('credits a portal sign-in to the client even when a staff session is open in the same browser', function () {
    $staff = User::factory()->create();
    $user = portalUser();

    $this->actingAs($staff, 'web');

    submitPortalPassword($user)
        ->set(portalCodeStatePath(), portalCodesSentTo($user)[0])
        ->call('authenticate');

    $activity = Activity::query()->where('event', 'login_success')->sole();

    expect($activity->causer)->toBeInstanceOf(ClientUser::class)
        ->and($activity->causer->is($user))->toBeTrue();
});

it('writes a failed portal sign-in to the activity log, caused by the client user when the account exists', function () {
    $user = portalUser();

    $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate');

    $activity = Activity::query()->where('event', 'login_failed')->sole();

    expect($activity->causer)->toBeInstanceOf(ClientUser::class)
        ->and($activity->causer->is($user))->toBeTrue()
        ->and($activity->properties['guard'] ?? null)->toBe('client');
});

/**
 * Nửa còn lại của điều trên, ghim cố ý: khi email gõ vào không ứng với tài khoản nào thì không có
 * `ClientUser` nào để gán, nên dòng vẫn được ghi nhưng KHÔNG có người thực hiện. Tra lại bằng một
 * truy vấn riêng để "cho có causer" sẽ là đúng một chênh lệch thời gian mà SPEC §10.10 cấm.
 */
it('still logs a failed sign-in for an unknown email, with no causer and with the email attempted', function () {
    $this->livewire(Login::class)
        ->set('data.email', 'khong-he-co-4@example.test')
        ->set('data.password', 'sai')
        ->call('authenticate');

    $activity = Activity::query()->where('event', 'login_failed')->sole();

    expect($activity->causer)->toBeNull()
        ->and($activity->properties['email'] ?? null)->toBe('khong-he-co-4@example.test');
});

it('never writes the password into the activity log', function () {
    $user = portalUser();

    $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'mat-khau-bi-mat-cua-khach')
        ->call('authenticate');

    expect(Activity::query()->get()->toJson())->not->toContain('mat-khau-bi-mat-cua-khach');
});

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — đổi mật khẩu lần đầu, không đi vòng được bằng URL
|--------------------------------------------------------------------------
*/

it('sends a first-time client to the change-password screen from any portal url they type', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);

    $this->actingAs($user, 'client');

    $this->get('/portal')->assertRedirect(ChangePassword::getUrl(panel: 'portal'));
    // Panel này không còn đăng ký trang tổng quan mặc định của Filament (M5 Task 3):
    // `App\Filament\Portal\Pages\MyMatters` giữ đường dẫn gốc. Địa chỉ thứ hai vì thế mang thêm
    // chuỗi truy vấn `?tat-ca=1` — một URL khác hẳn mà khách gõ vào được thật.
    $this->get(MyMatters::getUrl([MyMatters::SHOW_ALL_PARAMETER => 1], panel: 'portal'))
        ->assertRedirect(ChangePassword::getUrl(panel: 'portal'));
    $this->get(ChangePassword::getUrl(panel: 'portal'))->assertOk();
});

it('lets a client who has already chosen a password through', function () {
    $user = portalUser();

    $this->actingAs($user, 'client');

    $this->get('/portal')->assertOk();
});

it('closes the gate once the client has set their own password', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);

    $this->actingAs($user, 'client');

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-toi-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-toi-2026')
        ->call('changePassword')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('mat-khau-moi-cua-toi-2026', $user->password))->toBeTrue();

    $this->get('/portal')->assertOk();
});

it('refuses a new password that is the one the office emailed out', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);

    $this->actingAs($user, 'client');

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'password')
        ->set('data.passwordConfirmation', 'password')
        ->call('changePassword')
        ->assertHasErrors(['data.password' => __('portal.change_password.reuse')]);

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('holds the change-password gate on livewire update requests too', function () {
    $user = portalUser();

    $this->actingAs($user, 'client');

    $snapshot = portalSnapshot(
        $this->get(MyMatters::getUrl(panel: 'portal'))->assertOk()->getContent(),
        MyMatters::class,
    );

    $user->forceFill(['must_change_password' => true])->save();
    $this->actingAs($user->fresh(), 'client');

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ])
        ->assertRedirect(ChangePassword::getUrl(panel: 'portal'));
});

/*
|--------------------------------------------------------------------------
| SPEC §10.9 — tài khoản bị vô hiệu về màn hình đăng nhập, không phải 404
|--------------------------------------------------------------------------
*/

it('answers a deactivated client with the login screen, not a 404 and not a 403', function () {
    $user = portalUser(['is_active' => false]);

    $this->actingAs($user, 'client');

    $response = $this->get('/portal');

    $response->assertRedirect('/portal/login');
    expect($response->getStatusCode())->not->toBe(404)
        ->and($response->getStatusCode())->not->toBe(403)
        ->and(auth('client')->check())->toBeFalse();
});

it('says nothing about why the account is locked, and offers the office phone instead', function () {
    $user = portalUser(['is_active' => false]);

    $this->actingAs($user, 'client');
    $this->get('/portal');

    $titles = collect(session('filament.notifications', []))->pluck('title');

    expect($titles)->toContain(__('portal.inactive', ['phone' => config('vkcrm.brand.hotline')]))
        ->and($titles->implode(' '))->toContain((string) config('vkcrm.brand.hotline'))
        ->and($titles->implode(' '))->not->toContain('vô hiệu')
        ->and($titles->implode(' '))->not->toContain('khoá');
});

it('ends the session of a client deactivated mid-visit, on their very next livewire update', function () {
    $user = portalUser();

    $this->actingAs($user, 'client');

    $snapshot = portalSnapshot(
        $this->get(MyMatters::getUrl(panel: 'portal'))->assertOk()->getContent(),
        MyMatters::class,
    );

    $user->forceFill(['is_active' => false])->save();
    $this->actingAs($user->fresh(), 'client');

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ])
        ->assertRedirect('/portal/login');

    expect(auth('client')->check())->toBeFalse();
});

it('lets an active client keep working', function () {
    $user = portalUser();

    $this->actingAs($user, 'client');

    $this->get('/portal')->assertOk();
    expect(auth('client')->check())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SPEC §8 — mọi chữ khách đọc đều là tiếng Việt, qua __()
|--------------------------------------------------------------------------
*/

it('renders the code screen in Vietnamese, including the label a screen reader reads out', function () {
    $user = portalUser();

    $html = submitPortalPassword($user)->html();

    expect($html)
        ->toContain(e(__('portal.login.code.label')))
        ->toContain(e(__('portal.login.code.hint')))
        ->toContain(e(__('portal.login.code.resend')))
        // Khoá mang sang từ M4: ô nhập mã in `aria_label` vào từng ô số từ thứ hai trở đi, và
        // bản `vi` bundled của Filament KHÔNG có tệp này nên nó rơi về tiếng Anh.
        ->toContain(e(__('filament::components/input/one-time-code.aria_label', ['position' => 2, 'count' => 6])))
        ->not->toContain('Character 2 of 6');
});

it('has a Vietnamese string behind every portal key the login screens use', function () {
    $keys = [
        'portal.login.code.label',
        'portal.login.code.validation_attribute',
        'portal.login.code.hint',
        'portal.login.code.resend',
        'portal.login.code.resent',
        'portal.login.code.resend_throttled',
        'portal.login.code.invalid',
        'portal.login.code.expired',
        'portal.login.throttled',
        'portal.inactive',
        'portal.change_password.title',
        'portal.change_password.heading',
        'portal.change_password.description',
        'portal.change_password.fields.password',
        'portal.change_password.fields.password_confirmation',
        'portal.change_password.submit',
        'portal.change_password.saved',
        'portal.change_password.reuse',
        'portal.email.otp.subject',
        'portal.email.otp.greeting',
        'portal.email.otp.line',
        'portal.email.otp.expiry',
        'portal.email.otp.ignore',
        'portal.email.otp.salutation',
    ];

    foreach ($keys as $key) {
        expect(__($key))->not->toBe($key, "Thiếu bản dịch cho khoá [{$key}].");
    }
});

it('sends the one-time code in a Vietnamese email that is not queued', function () {
    $user = portalUser();

    submitPortalPassword($user);

    Notification::assertSentTo($user, SendLoginCode::class, function (SendLoginCode $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $rendered = (string) $mail->render();

        expect($mail->subject)->toBe(__('portal.email.otp.subject'))
            ->and($rendered)->toContain($notification->code())
            ->and($rendered)->toContain((string) config('vkcrm.brand.hotline'))
            // Mã sống 5 phút và khách đang ngồi chờ nó: xếp hàng trên `QUEUE_CONNECTION=database`
            // là để thư nằm trong bảng `jobs` tới lần cron kế tiếp.
            ->and($notification)->not->toBeInstanceOf(ShouldQueue::class);

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| SPEC §10.3 — chiều tài khoản khoá theo TÀI KHOẢN, không theo chuỗi vừa gõ
|--------------------------------------------------------------------------
|
| `client_users.email` là `utf8mb4_unicode_ci`, nên rất nhiều cách viết khác nhau ĐĂNG NHẬP VÀO
| CÙNG MỘT HÀNG. Nếu bộ đếm khoá theo chuỗi vừa gõ thì mỗi cách viết mua được một bộ đếm rỗng
| mới và trần 5 lần / 15 phút gia hạn được vô hạn.
|
| Vòng trước cố gấp chuỗi trong PHP cho giống collation. Nó hụt: `U+0345` (gấp hoa/thường biến
| nó thành chữ iota TRƯỚC khi bước xoá dấu nhìn thấy nó), `U+0488`/`U+0489` (lớp `\p{Me}`, không
| nằm trong bộ bị xoá), và họ `U+0363`–`U+036F` (bị xoá trong PHP nhưng collation cân chúng như
| chữ cái nền) — tổng cộng 21 lớp trọng số lệch. `U+0345` lặp lại được, nên một mình nó đã đủ để
| gia hạn trần không giới hạn.
|
| Nay khoá dựng từ **khoá chính của hàng** mà chính truy vấn đăng nhập sẽ tra. MariaDB tự gấp,
| và không có bản sao thứ hai của collation nào trong PHP để trôi.
|
| Phép đo ấy chỉ nói được trên MariaDB: SQLite — thứ bộ test chạy trên đó theo mặc định — so
| chuỗi theo BYTE, nên ở đó `nám@…` không tra ra hàng nào và test không chứng minh được gì. Vì
| vậy test dưới đây tự bỏ qua KÈM LÝ DO khi không chạy trên MariaDB.
*/

/**
 * Bắt buộc phải là CHÍNH cơ sở dữ liệu của ứng dụng, không phải một kết nối phụ chỉ để hỏi
 * collation: thứ đang đo là một lần tra hàng thật đi qua `ClientUser`.
 */
function requirePortalMariadb(): void
{
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        return;
    }

    test()->markTestSkipped(
        'Cần chạy trên chính MariaDB. Bộ test mặc định chạy trên SQLite, thứ so chuỗi theo BYTE '
        .'— ở đó `nám@…` không tra ra hàng nào nên không có gì để chứng minh. Chạy lại với '
        ."DB_CONNECTION=mariadb trên container dev. Đang chạy trên: [{$driver}]."
    );
}

/**
 * Những cách viết mà `utf8mb4_unicode_ci` coi là CÙNG một hàng với `nam@example.test`.
 *
 * Bốn dòng đầu là đúng những chỗ phép gấp PHP của vòng trước hụt — đo trên MariaDB 11.8, cả bốn
 * đều tra ra đúng hàng của nạn nhân. Mỗi phần tử BẮT BUỘC qua được luật `email` của Laravel, vì
 * bộ đếm chỉ bị ĐẬP sau khi form đã xác thực; test bên dưới khẳng định điều đó trước khi so gì
 * cả. (`ZWJ` vì vậy KHÔNG có mặt: MariaDB coi nó cùng hàng nhưng luật `email` chặn nó, nên nó
 * không bao giờ đập được vào bộ đếm nào.)
 *
 * @return array<string, string>
 */
function portalSameRowSpellings(): array
{
    return [
        'iota_ngam' => "na\u{0345}m@example.test",
        'iota_ngam_lap_tam_lan' => 'na'.str_repeat("\u{0345}", 8).'m@example.test',
        'dau_bao_quanh_0488' => "na\u{0488}m@example.test",
        'dau_bao_quanh_0489' => "na\u{0489}m@example.test",
        'hoa' => 'NAM@Example.Test',
        'dau_gan_san' => "n\u{00E1}m@example.test",
        'dau_roi' => "na\u{0301}m@example.test",
        'n_nua_rong' => "\u{FF4E}am@example.test",
        'cgj' => "na\u{034F}m@example.test",
    ];
}

/**
 * Những cách viết mà MariaDB coi là hàng KHÁC — chúng phải nhận bộ đếm khác.
 *
 * Họ `U+0363`–`U+036F` nằm ở đây chứ không ở danh sách trên, và đó là nửa thứ hai của cái vòng
 * trước làm sai: phép gấp PHP XOÁ chúng (gộp `na◌ͣm@…` vào `nam@…`), còn collation cân chúng
 * như chữ cái nền nên `na◌ͣm@…` là `naam@…`, một địa chỉ khác hẳn. Gộp nhầm theo chiều này
 * không mở ra lỗ hổng nào, nhưng nó khoá oan một địa chỉ vô can — và nó chứng minh phép gấp cũ
 * không phải là collation.
 *
 * @return array<string, string>
 */
function portalOtherRowSpellings(): array
{
    return [
        'chu_a_ngam_0363' => "na\u{0363}m@example.test",
        'chu_x_ngam_036F' => "na\u{036F}m@example.test",
        'khac_han' => 'nem@example.test',
    ];
}

it('gives one account one lock however the email in the box is spelled', function () {
    requirePortalMariadb();

    $user = portalUser(['email' => 'nam@example.test']);

    // 1. Tiền đề của cả test: MariaDB thật sự đưa từng cách viết về đúng hàng ấy (hoặc không),
    //    và từng cách viết đi qua được luật `email` nên nó thật sự chạm tới được bộ đếm.
    foreach (portalSameRowSpellings() as $ten => $value) {
        expect(Validator::make(['email' => $value], ['email' => 'email'])->passes())->toBeTrue(
            "Cách viết [{$ten}] không qua được luật `email`, nên nó không bao giờ đập được vào bộ "
            .'đếm. Bỏ nó ra khỏi danh sách thay vì để test nói về chỗ không ai tới được.'
        );

        expect(ClientUser::query()->where('email', $value)->value('id'))->toBe(
            $user->id,
            "MariaDB không đưa cách viết [{$ten}] về hàng của nạn nhân, nên nó không thuộc danh sách này."
        );
    }

    foreach (portalOtherRowSpellings() as $ten => $value) {
        expect(ClientUser::query()->where('email', $value)->value('id'))->toBeNull(
            "MariaDB đưa cách viết [{$ten}] về đúng hàng của nạn nhân, nên nó thuộc danh sách kia."
        );
    }

    // 2. Khoá: một hàng, một khoá — dù gõ kiểu gì.
    $khoaCuaHang = PortalLoginThrottle::passwordAccountKey($user->email);

    foreach (portalSameRowSpellings() as $ten => $value) {
        expect(PortalLoginThrottle::passwordAccountKey($value))->toBe(
            $khoaCuaHang,
            "Cách viết [{$ten}] đăng nhập vào đúng hàng ấy nhưng nhận một bộ đếm khác — tức trần "
            .'5 lần / 15 phút của SPEC §10.3 gia hạn được bằng cách gõ lại email một kiểu khác.'
        );
    }

    foreach (portalOtherRowSpellings() as $ten => $value) {
        expect(PortalLoginThrottle::passwordAccountKey($value))->not->toBe(
            $khoaCuaHang,
            "Cách viết [{$ten}] là một địa chỉ KHÁC với MariaDB nhưng dùng chung bộ đếm với nạn "
            .'nhân — nó khoá oan một người không liên quan.'
        );
    }

    // 3. Và điều đó phải đúng ở đường đi thật, không chỉ ở tầng khoá: năm lần hỏng trên cách
    //    viết gốc, rồi MỖI cách viết khác — từ một địa chỉ mạng chưa thử lần nào, nên thứ duy
    //    nhất có thể chặn là chiều tài khoản — đều đã bị khoá.
    $snapshot = portalLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        postPortalLogin($snapshot, [
            'data.email' => $user->email,
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.70');
    }

    $octet = 100;

    foreach (portalSameRowSpellings() as $ten => $value) {
        expect(portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => $value,
            'data.password' => 'sai-mat-khau',
        ], '203.0.113.'.$octet++))['data.email'][0] ?? null)->toBe(
            portalThrottleMessage(15),
            "Cách viết [{$ten}] mua được một cửa sổ 5 lần mới cho đúng tài khoản vừa bị khoá."
        );
    }

    // Twin dương: một địa chỉ MariaDB nói là hàng khác vẫn đi tới được cổng mật khẩu. Không có
    // vế này thì mọi thứ trên cũng xanh với một phép khoá gộp tất cả vào một bộ đếm duy nhất.
    foreach (portalOtherRowSpellings() as $ten => $value) {
        expect(portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => $value,
            'data.password' => 'sai-mat-khau',
        ], '203.0.113.'.$octet++))['data.email'][0] ?? null)->toBe(
            __('filament-panels::auth/pages/login.messages.failed'),
            "Cách viết [{$ten}] bị khoá lây bởi bộ đếm của một tài khoản khác."
        );
    }
});

it('still folds the typed string when it matches no account at all', function () {
    // Không có tài khoản nào ở đây, nên không có hàng nào để khoá theo — phần dự phòng của
    // `passwordAccountKey()` là thứ đang chạy, và nó chạy giống nhau trên mọi cơ sở dữ liệu.
    expect(ClientUser::query()->where('email', 'nan@example.test')->exists())->toBeFalse();

    // NFKC vẫn phải chạy: `ｎ` nửa rộng và `n` là một. Dòng này cũng là chốt giữ lời gọi
    // `\Normalizer` — gỡ nó đi thì đây là chỗ đỏ, chứ không phải một lần đăng nhập vỡ trên máy
    // khách. (`App\Support\Normalizer` là một lớp khác hẳn và không có `normalize()`.)
    expect(PortalLoginThrottle::passwordAccountKey("\u{FF4E}an@example.test"))
        ->toBe(PortalLoginThrottle::passwordAccountKey('nan@example.test'));

    // Và phép gấp không được thô tới mức bỏ hết ký tự ngoài ASCII: MariaDB nói `đ` KHÁC `d`.
    expect(PortalLoginThrottle::passwordAccountKey("\u{0111}an@example.test"))
        ->not->toBe(PortalLoginThrottle::passwordAccountKey('dan@example.test'));

    // Hai dòng này giữ THỨ TỰ của phép gấp (`U+0345` phải bị xoá TRƯỚC khi gấp hoa/thường biến
    // nó thành chữ iota) và lớp `\p{Me}` (`U+0488`) — hai trong ba nguyên nhân của 21 lớp trọng
    // số lệch ở vòng trước. Với một tài khoản CÓ THẬT thì MariaDB gấp hộ nên chúng không còn là
    // chỗ giữ SPEC §10.3; với một địa chỉ không có tài khoản thì đây là tất cả những gì có, và
    // nó vẫn phải đúng để hai cách viết của cùng một địa chỉ không nằm ở hai bộ đếm — một người
    // dò đọc ra được sự khác nhau đó như một câu trả lời về việc tài khoản có tồn tại hay không.
    expect(PortalLoginThrottle::foldEmail("na\u{0345}n@example.test"))
        ->toBe(PortalLoginThrottle::foldEmail('nan@example.test'))
        ->and(PortalLoginThrottle::foldEmail("na\u{0488}n@example.test"))
        ->toBe(PortalLoginThrottle::foldEmail('nan@example.test'));

    $snapshot = portalLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        postPortalLogin($snapshot, [
            'data.email' => 'nan@example.test',
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.62');
    }

    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => "n\u{00E2}n@example.test",
        'data.password' => 'sai-mat-khau',
    ], '203.0.113.62'))['data.email'][0])->toBe(portalThrottleMessage(15));

    // Twin dương, để test trên cũng đỏ với một phép gấp biến mọi chuỗi thành rỗng.
    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'nun@example.test',
        'data.password' => 'sai-mat-khau',
    ], '203.0.113.62'))['data.email'][0])
        ->toBe(__('filament-panels::auth/pages/login.messages.failed'));
});

it('spends exactly one extra query on the account lookup', function () {
    portalUser(['email' => 'dem-truy-van@example.test']);

    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    PortalLoginThrottle::passwordAccountKey('dem-truy-van@example.test');

    // Một lần tra, trên đúng chỉ mục duy nhất của cột — cùng truy vấn mà `EloquentUserProvider`
    // sắp chạy ngay sau đó. Con số này được ghim để nó không lặng lẽ lớn lên.
    expect($sql)->toHaveCount(1)
        ->and($sql[0])->toContain('client_users');

    // Và ô email chưa gõ gì thì KHÔNG tốn truy vấn nào: phép kiểm chạy trên state thô ở mọi lần
    // gửi form, kể cả lần bấm nhầm vào nút khi form còn trống, và không có hàng nào để tìm.
    $sql = [];

    PortalLoginThrottle::passwordAccountKey('');

    expect($sql)->toHaveCount(0);
});

it('gives a soft-deleted account no lock of its own, exactly as the login query gives it no row', function () {
    $user = portalUser(['email' => 'da-xoa-mem@example.test']);

    $khoaKhiConSong = PortalLoginThrottle::passwordAccountKey($user->email);

    $user->delete();

    // `EloquentUserProvider::retrieveByCredentials()` chạy `newModelQuery()`, tức MANG THEO global
    // scope xoá mềm, nên hàng này không đăng nhập được nữa. Bộ đếm đi theo đúng truy vấn ấy: nó
    // rơi về phép gấp như mọi địa chỉ không có tài khoản, thay vì giữ riêng một bộ đếm cho một
    // hàng mà không ai vào được.
    expect(PortalLoginThrottle::passwordAccountKey($user->email))
        ->not->toBe($khoaKhiConSong)
        ->toStartWith('portal-login-email:')
        ->and($khoaKhiConSong)->toStartWith('portal-login-account:');
});

it('never turns raw pre-validation livewire state into an error of its own', function () {
    // `Filament\Auth\Pages\Login::authenticate()` gọi `rateLimit(5)` TRƯỚC `form->getState()`,
    // nên phép KIỂM TRA chạy trên state thô của Livewire ở mọi lần gửi form: chuỗi chưa qua luật
    // `email`, chưa qua `required`, có thể là bất cứ thứ gì. Nó không được phép là một cách làm
    // sập màn hình đăng nhập.
    $rac = [
        'null' => null,
        'rong' => '',
        'chi_khoang_trang' => "   \t\n",
        'khong_phai_email' => 'khong-phai-email',
        'utf8_hong' => "na\xC3\x28m@example.test",
        'surrogate_le_loi' => "na\xED\xA0\x80m@example.test",
        'co_byte_khong' => "na\0m@example.test",
        'chi_toan_dau' => "\u{0301}\u{0345}\u{0488}",
        'rat_dai' => str_repeat('a', 400).'@example.test',
    ];

    foreach ($rac as $ten => $value) {
        expect(fn () => PortalLoginThrottle::passwordAccountKey($value))
            ->not->toThrow(Throwable::class, '', "Chuỗi [{$ten}] làm cổng đăng nhập ném ngoại lệ.");
    }
});

/*
|--------------------------------------------------------------------------
| SPEC §10.10 — một tài khoản có thật bị khoá và một địa chỉ không có tài khoản bị khoá
| phải KHÔNG phân biệt được
|--------------------------------------------------------------------------
|
| Đây là hoá đơn của cách khoá mới: hai chuỗi đi vào hai loại khoá khác nhau (một theo khoá
| chính của hàng, một theo chuỗi đã gấp), nên phải đo lại rằng người gõ không nhìn thấy sự khác
| nhau đó. Cùng số lần, cùng câu, cùng số phút.
*/

it('locks a real account and an address with no account behind exactly the same wall', function () {
    $user = portalUser(['email' => 'co-that-4@example.test']);
    $snapshot = portalLoginSnapshot();

    // Năm lần hỏng từ một địa chỉ mạng, rồi lần thứ sáu từ một địa chỉ CHƯA thử lần nào. Lần thứ
    // sáu đi từ chỗ khác là cốt lõi của phép đo: nó lấy chiều IP ra khỏi câu trả lời, nên thứ
    // duy nhất còn có thể khoá là chiều TÀI KHOẢN — đúng cái chiều mà một email có thật và một
    // email bịa ra nay đi vào hai loại khoá khác nhau.
    $khoaLai = function (string $email, string $ipDo, string $ipSach) use ($snapshot): array {
        foreach (range(1, 5) as $ignored) {
            postPortalLogin($snapshot, ['data.email' => $email, 'data.password' => 'sai'], $ipDo);
        }

        return portalLoginErrors(postPortalLogin($snapshot, [
            'data.email' => $email,
            'data.password' => 'sai',
        ], $ipSach));
    };

    $coThat = $khoaLai($user->email, '198.51.100.80', '203.0.113.80');
    $khongCo = $khoaLai('khong-he-co-4@example.test', '198.51.100.81', '203.0.113.81');

    // So CẢ phản hồi, không chỉ câu chữ: số phút nằm trong câu, nên vế này cũng là vế đo rằng
    // hai bên đợi đúng cùng một khoảng.
    expect($khongCo)->toBe($coThat)
        ->and($coThat['data.email'][0])->toBe(portalThrottleMessage(15))
        ->and($coThat['data.email'][0])->not->toContain($user->email);
});

/*
|--------------------------------------------------------------------------
| SPEC §10.3 — một lần đăng nhập thành công không được xoá chiều IP của người khác
|--------------------------------------------------------------------------
*/

it('never lets one client sign-in clear the address lock everyone else shares', function () {
    $user = portalUser();
    $snapshot = portalLoginSnapshot();

    // BỐN lần hỏng chứ không phải năm, và con số ấy là một phần của điều đang đo: chạm trần thứ
    // năm thì chính lần đăng nhập thành công bên dưới cũng bị chặn — chiều IP không phân biệt ai
    // đang gõ. Đây cũng đúng là hình dạng thật ở văn phòng: một người gõ sai vài lần, người ngồi
    // cạnh vẫn vào được bình thường.
    //
    // `livewire()` luôn chạy ở 127.0.0.1 (xem docblock đầu tệp), nên các lần hỏng qua HTTP cũng
    // đi từ địa chỉ đó để cả hai đường thật sự dùng chung một khoá IP.
    foreach (range(1, 3) as $index) {
        postPortalLogin($snapshot, [
            'data.email' => "nguoi-{$index}@example.test",
            'data.password' => 'sai-mat-khau',
        ], '127.0.0.1');
    }

    // Lần hỏng thứ tư là của chính tài khoản sắp đăng nhập được, để có một twin dương: chiều tài
    // khoản CỦA HỌ phải được xoá.
    postPortalLogin($snapshot, [
        'data.email' => $user->email,
        'data.password' => 'sai-mat-khau',
    ], '127.0.0.1');

    $ipKey = PortalLoginThrottle::passwordIpKey();

    expect(RateLimiter::attempts($ipKey))->toBe(4)
        ->and(RateLimiter::attempts(PortalLoginThrottle::passwordAccountKey($user->email)))->toBe(1);

    $component = submitPortalPassword($user);
    $component->set(portalCodeStatePath(), portalCodesSentTo($user)[0])->call('authenticate');

    expect(auth('client')->check())->toBeTrue()
        // Twin dương: chiều tài khoản của người vừa vào được thì có xoá.
        ->and(RateLimiter::attempts(PortalLoginThrottle::passwordAccountKey($user->email)))->toBe(0)
        // Và điều đang được đo: chiều địa chỉ mạng KHÔNG. Ba lần hỏng kia không phải của họ.
        ->and(RateLimiter::attempts($ipKey))->toBe(4);

    auth('client')->logout();

    // Lần hỏng thứ năm chạm trần, và một email thứ sáu chưa từng thử vẫn bị chặn — tức chiều IP
    // của SPEC §10.3 còn sống sau lần đăng nhập thành công kia.
    postPortalLogin($snapshot, [
        'data.email' => 'nguoi-5@example.test',
        'data.password' => 'sai-mat-khau',
    ], '127.0.0.1');

    expect(portalLoginErrors(postPortalLogin($snapshot, [
        'data.email' => 'nguoi-6@example.test',
        'data.password' => 'sai-mat-khau',
    ], '127.0.0.1'))['data.email'][0])->toBe(portalThrottleMessage(15));
});

it('clears only the account dimension of the code lock when the code is finally right', function () {
    $alice = portalUser();
    $bob = portalUser();

    $component = submitPortalPassword($alice);
    $realCode = portalCodesSentTo($alice)[0];

    foreach (range(1, 4) as $ignored) {
        $component->set(portalCodeStatePath(), '000000')->call('authenticate');
    }

    [$aliceKey, $ipKey] = PortalLoginThrottle::codeKeys($alice);

    expect(RateLimiter::attempts($aliceKey))->toBe(4)
        ->and(RateLimiter::attempts($ipKey))->toBe(4);

    $component->set(portalCodeStatePath(), $realCode)->call('authenticate');

    expect(auth('client')->check())->toBeTrue()
        // Twin dương: bộ đếm theo tài khoản của Alice được xoá, nếu không lần đăng nhập kế tiếp
        // của chính cô ấy trong 15 phút sẽ bị chặn ngay từ mã đầu tiên.
        ->and(RateLimiter::attempts($aliceKey))->toBe(0)
        // Điều đang được đo: chiều địa chỉ mạng giữ đủ năm lượt (bốn lần sai + chính lần đúng,
        // vì cổng đập bộ đếm ở MỌI lần gửi mã).
        ->and(RateLimiter::attempts($ipKey))->toBe(5)
        // Hệ quả nhìn thấy được, và là cái giá SPEC §10.3 đã chọn: Bob sau cùng đường truyền vẫn
        // đang bị chặn ở bước mã. Nếu lần đăng nhập của Alice xoá chiều IP thì dòng này xanh với
        // `false`, và cùng cơ chế ấy cho bất kỳ ai mua một cửa sổ mới bằng một tài khoản của mình.
        ->and(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::codeKeys($bob)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — đổi mật khẩu xong thì vào thẳng cổng, không bị đá ra màn hình đăng nhập
|--------------------------------------------------------------------------
*/

it('keeps the client signed in after they set their very first password', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);
    $oldHash = $user->getAuthPassword();

    $this->actingAs($user, 'client');

    /*
     * Dựng lại đúng một thứ mà lần tải trang đầy đủ ngay trước đó đã làm: `AuthenticateSession`
     * cất băm mật khẩu ĐANG DÙNG vào phiên.
     *
     * Không có dòng này thì test KHÔNG THỂ ĐỎ, và đó là lý do phiên bản trước của nó vô nghĩa:
     * client test của Laravel không giữ cookie, nên khoá `password_hash_client` luôn vắng mặt,
     * và `AuthenticateSession` rẽ sang nhánh "chưa có thì cất" thay vì nhánh "có rồi thì so".
     */
    session()->put('password_hash_client', $oldHash);

    $this->livewire(ChangePassword::class)
        ->set('data.password', 'mat-khau-moi-cua-toi-2026')
        ->set('data.passwordConfirmation', 'mat-khau-moi-cua-toi-2026')
        ->call('changePassword')
        ->assertHasNoErrors();

    $newHash = $user->fresh()->getAuthPassword();

    expect($newHash)->not->toBe($oldHash)
        ->and(session('password_hash_client'))->toBe($newHash);

    // Câu "Xong rồi" đi qua phiên (`Notification::send()` đẩy vào `filament.notifications`), nên
    // `session()->flush()` của `AuthenticateSession` sẽ nuốt mất nó cùng với cả phiên.
    expect(collect(session('filament.notifications', []))->pluck('title'))
        ->toContain(__('portal.change_password.saved'));

    // Và request đầy đủ kế tiếp — chính cú chuyển hướng khách vừa nhận — phải mở ra cổng, không
    // phải màn hình đăng nhập.
    $this->get(Filament::getUrl())->assertOk();

    expect(auth('client')->check())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SPEC §8.1 — màn hình đổi mật khẩu là một cánh cổng bắt buộc, không phải một trang tự do
|--------------------------------------------------------------------------
*/

it('keeps the change-password screen shut for a client who already chose one', function () {
    $user = portalUser();

    $this->actingAs($user, 'client');

    expect(ChangePassword::canAccess())->toBeFalse();

    // Panel portal trả 404 cho mọi lần từ chối (`AnswerDeniedPanelRequestsWithNotFound`), nên
    // câu trả lời đúng ở đây là "không có trang nào như vậy" — và quan trọng hơn: khách quay lại
    // sau nhiều tháng không đọc được câu "Đây là lần đầu anh/chị đăng nhập", một câu sai.
    $this->get(ChangePassword::getUrl(panel: 'portal'))->assertNotFound();
});

it('opens the change-password screen for a client who still owes the office one', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);

    $this->actingAs($user, 'client');

    expect(ChangePassword::canAccess())->toBeTrue();

    $this->get(ChangePassword::getUrl(panel: 'portal'))
        ->assertOk()
        ->assertSee(__('portal.change_password.heading'));
});

/*
 * `canAccess()` là một phương thức TĨNH công khai, nên nó phải trả lời được ở NGOÀI một request
 * của cổng — một lệnh artisan, một test, một lần gọi từ lớp khác. Câu trả lời ở đó phải nói về
 * NGƯỜI DÙNG, không về việc panel nào tình cờ đang hiện hành.
 *
 * Bản trước trả `false` khi không có panel hiện hành. Nó không ném, nhưng nó nói sai: với một
 * khách ĐANG nợ văn phòng một lần đổi mật khẩu, "không" là câu trả lời của một cánh cổng đóng
 * vào mặt đúng người bắt buộc phải đi qua nó — và vì panel từ chối bằng 404
 * (`AnswerDeniedPanelRequestsWithNotFound`), lời nói sai ấy hiện ra thành "không có trang nào
 * như vậy" chứ không thành một tiếng động ai đó nghe thấy.
 *
 * Đường request thật KHÔNG tới được nhánh ấy — `Filament\Http\Middleware\SetUpPanel` là
 * middleware bền của Livewire và chạy ở `snapshot-verified`, tức TRƯỚC `hydrateCanAuthorizeAccess()`
 * — và có test ngay bên dưới đo đúng điều đó. Nhưng "không tới được" là một tính chất của thứ
 * tự middleware ở một gói khác, không phải của trang này; nên trang này thôi hỏi trạng thái
 * toàn cục và hỏi thẳng panel của chính nó.
 */
it('answers the change-password gate about the client, not about whichever panel is current', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);
    $this->actingAs($user, 'client');

    // Ngoài mọi ngữ cảnh panel. `FilamentManager::auth()` ném `NoDefaultPanelSetException` ở đây
    // (không panel nào của dự án gọi `->default()`), nên câu trả lời phải tới mà không ném.
    Filament::setCurrentPanel(null);
    expect(ChangePassword::canAccess())->toBeTrue();

    // Và trong ngữ cảnh panel KHÁC: khách vẫn là khách, món nợ vẫn là món nợ.
    Filament::setCurrentPanel('admin');
    expect(ChangePassword::canAccess())->toBeTrue();
});

it('keeps the change-password gate shut outside a panel for a client who already chose one', function () {
    $user = portalUser();
    $this->actingAs($user, 'client');

    Filament::setCurrentPanel(null);
    expect(ChangePassword::canAccess())->toBeFalse();

    Filament::setCurrentPanel('admin');
    expect(ChangePassword::canAccess())->toBeFalse();
});

it('never opens the change-password gate for a staff session, in any panel context', function () {
    $staff = User::factory()->create();
    $this->actingAs($staff, 'web');

    // Vế này là lý do trang hỏi guard của panel `portal` chứ không hỏi `Auth::user()`: một phiên
    // nhân sự đang mở trong cùng trình duyệt không được đếm là một khách hàng.
    Filament::setCurrentPanel('admin');
    expect(ChangePassword::canAccess())->toBeFalse();

    Filament::setCurrentPanel(null);
    expect(ChangePassword::canAccess())->toBeFalse();
});

/**
 * Bằng chứng cho câu "đường request thật không bao giờ hỏi cổng này khi chưa có panel".
 *
 * Nó phải đi bằng request HTTP thật tới đường cập nhật của Livewire, không bằng `livewire()`:
 * `PersistentMiddleware` bỏ qua toàn bộ middleware bền khi request không phải route cập nhật
 * thật (`isLivewireRoute()`), nên một test lái component thẳng KHÔNG đo được điều này — nó chỉ
 * đo cái `beforeEach` của tệp này vừa đặt.
 *
 * `setCurrentPanel(null)` ngay trước khi gửi là để chính request đó phải tự dựng lại ngữ cảnh
 * panel của mình. Nếu một bản Livewire hay Filament sau đổi thứ tự ấy — middleware bền chạy SAU
 * khi component được hydrate — thì `hydrateCanAuthorizeAccess()` sẽ hỏi cổng khi chưa có panel,
 * và dòng này đỏ. Đó là lúc phải đọc lại trang này, không phải lúc khách gặp một 403 không ai
 * giải thích được.
 */
it('opens the change-password screen on a real livewire update with no panel set beforehand', function () {
    $user = ClientUser::factory()->create(['must_change_password' => true]);
    $this->actingAs($user, 'client');

    $snapshot = portalSnapshot(
        $this->get(ChangePassword::getUrl(panel: 'portal'))->assertOk()->getContent(),
        ChangePassword::class,
    );

    Filament::setCurrentPanel(null);

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => ['data.password' => 'mat-khau-moi-cua-toi-2026'],
                'calls' => [],
            ]],
        ])
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| SPEC §10.3 và §10.6 — địa chỉ nào được đếm và được ghi khi có proxy đứng trước
|--------------------------------------------------------------------------
*/

it('ignores a forwarded-for header while no proxy is trusted', function () {
    // Mặc định phải là KHÔNG TIN AI, nếu không máy dev và bộ test đổi hành vi chỉ vì có người
    // thêm một tệp cấu hình.
    expect(config('trustedproxy.proxies'))->toBeNull();

    Route::get('/dia-chi-nhin-thay', fn () => request()->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
        ->get('/dia-chi-nhin-thay')
        ->assertOk()
        ->assertSee('10.0.0.5');
});

it('drives the trusted proxy list from one environment variable', function () {
    /*
     * Hai test bên dưới tự đặt `config(['trustedproxy.proxies' => …])`, nên chúng vẫn xanh cả
     * khi `config/trustedproxy.php` bị xoá — đã đo. Tức chúng chứng minh middleware đọc khoá
     * ấy, KHÔNG chứng minh có ai nối khoá ấy với một biến môi trường. Nếu không có tệp cấu
     * hình thì trên máy thật `TRUSTED_PROXIES` không đi tới đâu cả và SPEC §10.3 mất chiều IP
     * trong im lặng. Nên test này hỏi thẳng chính tệp đó.
     */
    expect(file_exists(config_path('trustedproxy.php')))->toBeTrue(
        'Thiếu config/trustedproxy.php — biến TRUSTED_PROXIES không còn nối với middleware nào.'
    );

    putenv('TRUSTED_PROXIES=203.0.113.1,203.0.113.2');
    $_ENV['TRUSTED_PROXIES'] = '203.0.113.1,203.0.113.2';

    try {
        expect((require config_path('trustedproxy.php'))['proxies'] ?? null)
            ->toBe('203.0.113.1,203.0.113.2');
    } finally {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES']);
    }

    // Twin âm, và nó là mặc định phải giữ: không khai báo gì thì KHÔNG TIN AI. Một mặc định
    // `*` sẽ trả lại quyền tự khai địa chỉ cho bất kỳ ai gửi một header.
    expect((require config_path('trustedproxy.php'))['proxies'] ?? null)->toBeNull();
});

it('reads the real client address through a proxy once that proxy is named', function () {
    config(['trustedproxy.proxies' => '10.0.0.5']);

    Route::get('/dia-chi-nhin-thay', fn () => request()->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
        ->get('/dia-chi-nhin-thay')
        ->assertOk()
        ->assertSee('203.0.113.99');
});

it('gives two clients behind the same proxy two different login buckets', function () {
    config(['trustedproxy.proxies' => '10.0.0.5']);

    Route::get('/khoa-nhin-thay', fn () => PortalLoginThrottle::passwordIpKey());

    $first = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
        ->get('/khoa-nhin-thay')->getContent();

    $second = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
        ->get('/khoa-nhin-thay')->getContent();

    // Không khai báo proxy thì hai dòng này bằng nhau, và SPEC §10.3 chỉ còn MỘT bộ đếm cho toàn
    // bộ cổng: năm lần gõ sai của bất kỳ ai khoá mọi khách hàng trong 15 phút.
    expect($first)->not->toBe($second);
});

/*
|--------------------------------------------------------------------------
| SPEC §10.6 — nhánh hỏng cuối cùng cũng phải để lại vết
|--------------------------------------------------------------------------
*/

it('writes a failed sign-in when the account is switched off during the code step', function () {
    $user = portalUser();

    $component = submitPortalPassword($user);
    $realCode = portalCodesSentTo($user)[0];

    /*
     * Lớp cha kiểm credentials HAI lần trong cùng một request ở bước nhập mã: một lần trước khi
     * xét mã, và một lần nữa SAU khi mã đã đúng (`attemptWhen()`), cố ý, để một mật khẩu bị đổi
     * hay một tài khoản bị vô hiệu ngay trong lúc khách đọc thư vẫn được nhìn thấy. Nhánh thứ
     * hai ấy là nhánh hỏng DUY NHẤT không đi qua `fireFailedEvent()`.
     *
     * Vì hai lần kiểm đọc cùng một hàng trong cùng một request, chỉ có một cách dựng lại nó:
     * đổi hàng ấy ĐÚNG VÀO khoảng giữa. Sự kiện `Attempting` đánh dấu đầu mỗi lần kiểm, nên bộ
     * đếm dưới đây bắt đúng khoảnh khắc trợ lý bấm "khoá tài khoản" trong lúc khách đang gõ mã.
     *
     * Không có mốc này thì test đi lạc sang nhánh khác và không đỏ được: vô hiệu tài khoản
     * TRƯỚC khi gửi form làm nó hỏng ngay ở cổng đầu tiên — đã đo, và ở đó `fireFailedEvent()`
     * đã ghi dòng nhật ký rồi.
     */
    $lanKiem = 0;

    Event::listen(Attempting::class, function () use (&$lanKiem, $user): void {
        $lanKiem++;

        if ($lanKiem === 2) {
            ClientUser::query()->whereKey($user->getKey())->update(['is_active' => false]);
        }
    });

    $countTruoc = Activity::query()->where('event', 'login_failed')->count();

    $component->set(portalCodeStatePath(), $realCode)
        ->call('authenticate')
        ->assertHasErrors(['data.email' => __('filament-panels::auth/pages/login.messages.failed')]);

    expect($lanKiem)->toBe(2, 'Không tới được lần kiểm thứ hai — test đang đo một nhánh khác.')
        ->and(auth('client')->check())->toBeFalse();

    $log = Activity::query()->where('event', 'login_failed')->latest('id')->first();

    expect(Activity::query()->where('event', 'login_failed')->count())->toBe($countTruoc + 1)
        ->and($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($user->id)
        ->and($log->causer_type)->toBe($user->getMorphClass())
        ->and($log->properties['guard'] ?? null)->toBe('client');
});

it('writes exactly one failed sign-in row for one wrong password, not two', function () {
    $user = portalUser();

    $this->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'sai-mat-khau')
        ->call('authenticate');

    // Twin âm của test trên: nhánh mật khẩu sai đi qua `fireFailedEvent()`, và cái cờ chống ghi
    // hai lần phải giữ nó ở đúng một dòng.
    expect(Activity::query()->where('event', 'login_failed')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Những giả định về vendor mà trang đăng nhập dựa vào, ghim lại để chúng không trôi trong im lặng
|--------------------------------------------------------------------------
*/

it('stops loudly if Filament ever asks the login page for a different number of attempts', function () {
    $component = $this->livewire(Login::class);

    $rateLimit = new ReflectionMethod(Login::class, 'rateLimit');

    // Con số của SPEC §10.3 đi qua bình thường…
    $rateLimit->invoke($component->instance(), PortalLoginThrottle::MAX_ATTEMPTS);

    // …còn một con số khác thì dừng lại ồn ào, thay vì lặng lẽ giữ 5 và làm như không có gì.
    expect(fn () => $rateLimit->invoke($component->instance(), 10))
        ->toThrow(LogicException::class);
});

it('still finds the SPEC number at the Filament call site it overrides', function () {
    $source = file_get_contents(base_path('vendor/filament/filament/src/Auth/Pages/Login.php'));

    // `Login::rateLimit()` ghi đè một phương thức mà lớp cha gọi với một con số viết cứng. Nếu
    // một bản Filament sau đổi con số đó thì bản vá SPEC §10.3 phải được đọc lại, không phải
    // được tin tiếp.
    expect($source)->toContain('$this->rateLimit(5)');
});

it('keeps the one-time code out of anything that dumps the notification', function () {
    $notification = new SendLoginCode('123456', 5);

    ob_start();
    var_dump($notification);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('123456')
        ->and((string) json_encode($notification))->not->toContain('123456')
        // …nhưng mã vẫn lấy được ở nơi thật sự cần nó, nếu không thư gửi đi sẽ rỗng.
        ->and($notification->code())->toBe('123456');
});
