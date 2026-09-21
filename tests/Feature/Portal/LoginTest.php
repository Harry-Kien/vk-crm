<?php

use App\Filament\Portal\Auth\PortalEmailAuthentication;
use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Filament\Portal\Pages\Auth\Login;
use App\Models\ClientUser;
use App\Models\User;
use App\Notifications\Client\SendLoginCode;
use App\Support\PortalLoginThrottle;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        ->map(fn (SendLoginCode $notification): string => $notification->code)
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

it('counts failures, not attempts — signing in clears the count', function () {
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

    expect(auth('client')->check())->toBeTrue();

    foreach (PortalLoginThrottle::passwordKeys($user->email) as $key) {
        expect(RateLimiter::attempts($key))->toBe(0);
    }

    // `Login::mount()` chuyển hướng ngay khi đã có phiên, nên phải ra khỏi phiên trước rồi mới
    // dựng lại được trang đăng nhập.
    auth('client')->logout();

    // Bộ đếm đã được xoá: bốn lần hỏng trước đó không còn tính vào lần đăng nhập kế tiếp.
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
    $this->get(Dashboard::getUrl(panel: 'portal'))->assertRedirect(ChangePassword::getUrl(panel: 'portal'));
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
        $this->get(Dashboard::getUrl(panel: 'portal'))->assertOk()->getContent(),
        Dashboard::class,
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
        $this->get(Dashboard::getUrl(panel: 'portal'))->assertOk()->getContent(),
        Dashboard::class,
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
            ->and($rendered)->toContain($notification->code)
            ->and($rendered)->toContain((string) config('vkcrm.brand.hotline'))
            // Mã sống 5 phút và khách đang ngồi chờ nó: xếp hàng trên `QUEUE_CONNECTION=database`
            // là để thư nằm trong bảng `jobs` tới lần cron kế tiếp.
            ->and($notification)->not->toBeInstanceOf(ShouldQueue::class);

        return true;
    });
});
