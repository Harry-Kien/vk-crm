<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\PortalLoginThrottle;
use App\Support\StaffLoginThrottle;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Activitylog\Models\Activity;

/**
 * Đăng nhập NHÂN SỰ — SPEC §10.3 (5 lần / 15 phút theo email VÀ IP, hai bước), M8 Task 3.
 *
 * Cùng hai cách lái với `tests/Feature/Portal/LoginTest.php`, vì cùng lý do: `Livewire::test()`
 * dựng request với `REMOTE_ADDR` cố định 127.0.0.1, nên một test "đổi IP" ở đó là một test không
 * đổi gì cả. Các test cần đổi địa chỉ đi bằng **request HTTP thật** tới đường cập nhật của
 * Livewire (`withServerVariables(['REMOTE_ADDR' => …])`), lấy snapshot từ trang `/admin/login`.
 *
 * Trước task này panel `admin` dùng `Filament\Auth\Pages\Login` mặc định: 5 lần / 60 giây, chỉ
 * theo IP ở bước mật khẩu; chỉ theo tài khoản ở bước mã (đo trong docblock của `LoginThrottle`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function staffLoginSnapshot(): string
{
    $html = test()->get('/admin/login')->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === Login::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Không có snapshot Livewire của trang đăng nhập nhân sự trong HTML đã render.');
}

/** @param  array<string, mixed>  $updates */
function postStaffLogin(string $snapshot, array $updates, string $ip): TestResponse
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

/** @return array<string, list<string>> */
function staffLoginErrors(TestResponse $response): array
{
    $response->assertOk();

    $snapshot = json_decode(json_decode($response->getContent(), true)['components'][0]['snapshot'], true);

    return $snapshot['memo']['errors'] ?? [];
}

function staffThrottleMessage(int $minutes): string
{
    return __('users.login.throttled', ['minutes' => $minutes]);
}

function staffLoginFailedMessage(): string
{
    return __('filament-panels::auth/pages/login.messages.failed');
}

/** Bước một qua Livewire trực tiếp (IP 127.0.0.1). */
function submitStaffPassword(User $user, string $password = 'password'): Testable
{
    return test()->livewire(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', $password)
        ->call('authenticate');
}

function staffTotp(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);
}

/** Một mã 6 số chắc chắn SAI: không nằm trong cửa sổ ±8 bước mà Filament chấp nhận. */
function staffWrongTotp(User $user): string
{
    $google2fa = app(Google2FA::class);

    foreach (range(100000, 100500) as $candidate) {
        if (! $google2fa->verifyKey($user->two_factor_secret, (string) $candidate, 12)) {
            return (string) $candidate;
        }
    }

    throw new RuntimeException('Không tìm được mã TOTP sai.');
}

function staffFailedRows(User $user, ?string $step = null)
{
    return Activity::query()
        ->where('event', 'login_failed')
        ->where('causer_id', $user->getKey())
        ->where('causer_type', $user->getMorphClass())
        ->get()
        ->filter(fn (Activity $a): bool => $step === null || $a->properties->get('step') === $step);
}

function staffLockedOut(User $staff, int $times = 5): void
{
    foreach (range(1, $times) as $ignored) {
        submitStaffPassword($staff, 'sai-mat-khau');
    }
}

/*
|--------------------------------------------------------------------------
| Bước mật khẩu — theo CẢ email lẫn IP
|--------------------------------------------------------------------------
*/

it('§10.3 blocks a sixth admin sign-in on the same email even from a brand-new address, and even with the right password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['email' => 'luat-su@example.test', 'password' => 'mat-khau-dung']);
    $snapshot = staffLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        expect(staffLoginErrors(postStaffLogin($snapshot, [
            'data.email' => $staff->email,
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.10')))->toHaveKey('data.email');
    }

    // Cùng email, một địa chỉ CHƯA từng thử, MẬT KHẨU ĐÚNG: chỉ chiều email chặn được — và nó chặn.
    $blocked = staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => $staff->email,
        'data.password' => 'mat-khau-dung',
    ], '203.0.113.77'));

    expect($blocked['data.email'][0])->toBe(staffThrottleMessage(15))
        ->and(auth('web')->check())->toBeFalse();

    // Chứng minh cái chặn là khoá EMAIL, không phải một khoá chung: email khác từ chính địa chỉ mới đó
    // vẫn tới được cổng mật khẩu.
    expect(staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => 'nguoi-khac@example.test',
        'data.password' => 'sai-mat-khau',
    ], '203.0.113.77'))['data.email'][0])->toBe(staffLoginFailedMessage());
});

it('§10.3 blocks a sixth admin sign-in from the same address even on a brand-new email', function () {
    $snapshot = staffLoginSnapshot();

    // Năm email KHÁC NHAU: không khoá email nào chạm tới 2 lần, nên chỉ chiều IP đếm được tới 5.
    foreach (range(1, 5) as $index) {
        expect(staffLoginErrors(postStaffLogin($snapshot, [
            'data.email' => "nhan-su-{$index}@example.test",
            'data.password' => 'sai-mat-khau',
        ], '198.51.100.20')))->toHaveKey('data.email');
    }

    expect(staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => 'nhan-su-6@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.20'))['data.email'][0])->toBe(staffThrottleMessage(15));

    // Cùng email mới đó từ địa chỉ khác không bị chặn: cái chặn ở trên là chiều IP.
    expect(staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => 'nhan-su-6@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.21'))['data.email'][0])->toBe(staffLoginFailedMessage());
});

it('§10.3 keeps the admin lock for fifteen minutes, not for the sixty seconds Filament would use', function () {
    $snapshot = staffLoginSnapshot();

    foreach (range(1, 5) as $ignored) {
        postStaffLogin($snapshot, ['data.email' => 'khoa@example.test', 'data.password' => 'sai-mat-khau'], '198.51.100.30');
    }

    $this->travel(5)->minutes();

    expect(staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => 'khoa@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.30'))['data.email'][0])->toBe(staffThrottleMessage(10));

    $this->travel(11)->minutes();

    expect(staffLoginErrors(postStaffLogin($snapshot, [
        'data.email' => 'khoa@example.test',
        'data.password' => 'sai-mat-khau',
    ], '198.51.100.30'))['data.email'][0])->toBe(staffLoginFailedMessage());
});

it('§10.3 locks an admin email that does not exist on exactly the same terms as one that does', function () {
    User::factory()->withRole(Role::Lawyer)->create(['email' => 'co-that@example.test']);
    $snapshot = staffLoginSnapshot();

    $locked = [];

    foreach (['co-that@example.test' => '198.51.100.50', 'khong-co@example.test' => '198.51.100.51'] as $email => $ip) {
        foreach (range(1, 5) as $ignored) {
            postStaffLogin($snapshot, ['data.email' => $email, 'data.password' => 'sai-mat-khau'], $ip);
        }

        $locked[$email] = staffLoginErrors(postStaffLogin($snapshot, [
            'data.email' => $email,
            'data.password' => 'sai-mat-khau',
        ], $ip));
    }

    // SPEC §10.10: không câu nào, không số phút nào cho người ngoài biết email nào là thật.
    expect($locked['co-that@example.test'])->toBe($locked['khong-co@example.test'])
        ->and($locked['co-that@example.test']['data.email'][0])->toBe(staffThrottleMessage(15));
});

it('§10.3 counts failures, not attempts — a successful admin sign-in clears the account count but never the shared address count', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    foreach (range(1, 4) as $ignored) {
        submitStaffPassword($staff, 'sai-mat-khau');
    }

    $accountKey = StaffLoginThrottle::passwordAccountKey($staff->email);
    $ipKey = StaffLoginThrottle::passwordIpKeyFor('127.0.0.1');

    expect(RateLimiter::attempts($accountKey))->toBe(4)
        ->and(RateLimiter::attempts($ipKey))->toBe(4);

    submitStaffPassword($staff, 'mat-khau-dung')
        ->assertHasNoErrors()
        ->set('data.multiFactor.app.code', staffTotp($staff))
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth('web')->check())->toBeTrue()
        // Chiều TÀI KHOẢN được xoá; chiều ĐỊA CHỈ thì không — một lần vào được không nói gì về những
        // lần hỏng của người khác sau cùng một NAT (docblock `LoginThrottle`).
        ->and(RateLimiter::attempts($accountKey))->toBe(0)
        ->and(RateLimiter::attempts($ipKey))->toBe(4);
});

it('§10.3 a wrong admin password is one failure on each dimension, not two', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    submitStaffPassword($staff, 'sai-mat-khau');

    expect(RateLimiter::attempts(StaffLoginThrottle::passwordAccountKey($staff->email)))->toBe(1)
        ->and(RateLimiter::attempts(StaffLoginThrottle::passwordIpKeyFor('127.0.0.1')))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Bước mã — TOTP và mã khôi phục dùng CHUNG một bộ đếm
|--------------------------------------------------------------------------
*/

it('§10.3 locks the admin code step after five wrong one-time codes, and then even the right code is refused', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $component = submitStaffPassword($staff)->assertHasNoErrors();

    foreach (range(1, 5) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    // Lần thứ sáu, MÃ ĐÚNG: cổng đóng, không đăng nhập, và câu trả lời nằm ngay dưới ô mã.
    $component->set('data.multiFactor.app.code', staffTotp($staff))
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code']);

    expect($component->errors()->first('data.multiFactor.app.code'))->toBe(staffThrottleMessage(15))
        ->and(auth('web')->check())->toBeFalse();
});

it('§10.3 counts recovery-code attempts on the very same code-step counter', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    // Người đã cài 2FA luôn có mã khôi phục (`recoverable()`); không có, Filament ném LogicException.
    $staff->saveAppAuthenticationRecoveryCodes([Hash::make('ma-khoi-phuc-that-1'), Hash::make('ma-khoi-phuc-that-2')]);

    $component = submitStaffPassword($staff)->assertHasNoErrors();

    // Ba mã TOTP sai + hai mã khôi phục sai = năm lần hỏng ở CÙNG một cổng.
    foreach (range(1, 3) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    $component->set('data.multiFactor.app.useRecoveryCode', true);

    foreach (['khong-phai-ma-1', 'khong-phai-ma-2'] as $wrong) {
        $component->set('data.multiFactor.app.recoveryCode', $wrong)->call('authenticate');
    }

    expect(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($staff)))->toBeTrue();

    $component->set('data.multiFactor.app.recoveryCode', 'khong-phai-ma-3')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.recoveryCode']);

    expect($component->errors()->first('data.multiFactor.app.recoveryCode'))->toBe(staffThrottleMessage(15))
        ->and(auth('web')->check())->toBeFalse();
});

it('§10.3 keeps the admin code step and the password step as two separate counters', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    // Hai mật khẩu sai rồi đúng, rồi ba mã sai: không lần nào là một lần dò ở CÙNG bước.
    submitStaffPassword($staff, 'sai-1');
    submitStaffPassword($staff, 'sai-2');

    $component = submitStaffPassword($staff, 'mat-khau-dung')->assertHasNoErrors();

    foreach (range(1, 3) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    expect(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::passwordKeys($staff->email)))->toBeFalse()
        ->and(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($staff)))->toBeFalse();

    // Đúng mã: vẫn vào được.
    $component->set('data.multiFactor.app.code', staffTotp($staff))->call('authenticate')->assertHasNoErrors();

    expect(auth('web')->check())->toBeTrue();
});

it('§10.3 locks the admin code step by account and by address, as two independent conditions', function () {
    $alice = User::factory()->withRole(Role::Lawyer)->create();
    $bob = User::factory()->withRole(Role::Lawyer)->create();

    [$aliceAccountKey, $ipKey] = StaffLoginThrottle::codeKeys($alice);
    [$bobAccountKey, $bobIpKey] = StaffLoginThrottle::codeKeys($bob);

    expect($aliceAccountKey)->not->toBe($bobAccountKey)
        ->and($bobIpKey)->toBe($ipKey);

    // Chiều TÀI KHOẢN: năm lần hỏng của Alice, rồi xoá chiều IP (Alice đổi địa chỉ). Vẫn bị chặn.
    foreach (range(1, 5) as $ignored) {
        StaffLoginThrottle::hit(StaffLoginThrottle::codeKeys($alice));
    }

    RateLimiter::clear($ipKey);

    expect(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($alice)))->toBeTrue()
        ->and(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($bob)))->toBeFalse();

    // Chiều ĐỊA CHỈ: năm lần dồn lên khoá IP, tài khoản của Bob vẫn sạch. Bob vẫn bị chặn.
    RateLimiter::clear($aliceAccountKey);

    foreach (range(1, 5) as $ignored) {
        RateLimiter::hit($ipKey, StaffLoginThrottle::DECAY_SECONDS);
    }

    expect(RateLimiter::attempts($bobAccountKey))->toBe(0)
        ->and(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::codeKeys($bob)))->toBeTrue();
});

it('§10.3 clears only the account side of the admin code lock when the code is finally right', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $component = submitStaffPassword($staff)->assertHasNoErrors();

    foreach (range(1, 3) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    [$accountKey, $ipKey] = StaffLoginThrottle::codeKeys($staff);

    expect(RateLimiter::attempts($accountKey))->toBe(3);

    $component->set('data.multiFactor.app.code', staffTotp($staff))->call('authenticate')->assertHasNoErrors();

    expect(auth('web')->check())->toBeTrue()
        ->and(RateLimiter::attempts($accountKey))->toBe(0)
        // Bước mã đập bộ đếm ở MỌI lần gửi, kể cả lần đúng: 3 sai + 1 đúng = 4 ở chiều địa chỉ.
        ->and(RateLimiter::attempts($ipKey))->toBe(4);
});

it('§10.3 gives the staff counters and the portal counters different baskets', function () {
    $email = 'dung-chung@example.test';

    foreach (range(1, 5) as $ignored) {
        StaffLoginThrottle::hit(StaffLoginThrottle::passwordKeys($email));
    }

    expect(StaffLoginThrottle::tooManyAttempts(StaffLoginThrottle::passwordKeys($email)))->toBeTrue()
        // Năm lần dò ở cổng nhân sự không tiêu lượt nào của cổng khách — kể cả cùng email, cùng IP.
        ->and(PortalLoginThrottle::tooManyAttempts(PortalLoginThrottle::passwordKeys($email)))->toBeFalse()
        ->and(StaffLoginThrottle::passwordIpKeyFor('10.0.0.1'))->not->toBe(PortalLoginThrottle::passwordIpKeyFor('10.0.0.1'))
        // Khoá của cổng khách giữ nguyên chuỗi từ M5.
        ->and(PortalLoginThrottle::passwordIpKeyFor('10.0.0.1'))->toBe('portal-login-ip:'.sha1('10.0.0.1'));
});

it('§10.3 gives one admin account one lock however the email in the box is spelled', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['email' => 'nam@example.test']);

    expect(StaffLoginThrottle::passwordAccountKey($staff->email))->toStartWith('staff-login-account:')
        // Không tra ra hàng nào thì rơi về phép gấp email — khoá khác loại, cùng một bức tường.
        ->and(StaffLoginThrottle::passwordAccountKey('khong-co@example.test'))->toStartWith('staff-login-email:');

    // Phần "cách viết khác nhau ra cùng một hàng" là việc của COLLATION của CSDL (`users.email` là
    // utf8mb4_unicode_ci trên MariaDB) — SQLite dùng cho bộ test nhanh phân biệt hoa/thường nên
    // chỉ MariaDB đo được. Chạy bằng `test:mariadb`.
    if (DB::getDriverName() === 'mariadb') {
        expect(StaffLoginThrottle::passwordAccountKey('NAM@Example.TEST'))->toBe(StaffLoginThrottle::passwordAccountKey('nam@example.test'))
            ->and(StaffLoginThrottle::passwordAccountKey('ｎam@example.test'))->toBe(StaffLoginThrottle::passwordAccountKey($staff->email));
    }
});

/*
|--------------------------------------------------------------------------
| Nhật ký: lỗi ở CẢ HAI bước đều để lại dòng login_failed kèm bước và IP
|--------------------------------------------------------------------------
*/

it('§10.6 records a failed admin password with step=password and the address', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    submitStaffPassword($staff, 'sai-mat-khau');

    $row = staffFailedRows($staff, 'password')->last();

    expect($row)->not->toBeNull()
        ->and($row->properties->get('guard'))->toBe('web')
        ->and($row->properties->get('ip'))->toBe('127.0.0.1');
});

it('§10.6 records a failed admin code with step=code, the address and the account, one row per wrong code', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $component = submitStaffPassword($staff)->assertHasNoErrors();

    foreach (range(1, 3) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    $rows = staffFailedRows($staff, 'code');

    expect($rows)->toHaveCount(3)
        ->and($rows->first()->properties->get('guard'))->toBe('web')
        ->and($rows->first()->properties->get('ip'))->toBe('127.0.0.1')
        ->and($rows->first()->subject?->is($staff))->toBeTrue();
});

it('§10.6 writes no failure row for an attempt the throttle refused without checking the code', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $component = submitStaffPassword($staff)->assertHasNoErrors();

    foreach (range(1, 5) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    // Hai lần thử nữa khi cổng đã đóng: không ai chấm mã, nên không phải hai lần hỏng mới.
    foreach (range(1, 2) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    expect(staffFailedRows($staff, 'code'))->toHaveCount(5);
});

/*
|--------------------------------------------------------------------------
| Đường mở khoá: UnlockStaffLogin, nút trên EditUser, chỉ quản trị viên
|--------------------------------------------------------------------------
*/

it('§10.3 lets an admin unlock a locked staff account so the very next sign-in works, from the same address', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    staffLockedOut($staff);

    $accountKey = StaffLoginThrottle::passwordAccountKey($staff->email);
    $ipKey = StaffLoginThrottle::passwordIpKeyFor('127.0.0.1');

    expect(StaffLoginThrottle::tooManyAttempts([$accountKey]))->toBeTrue()
        ->and(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeTrue();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getKey()])
        ->assertActionVisible('unlockLogin')
        ->callAction('unlockLogin')
        ->assertNotified(__('users.actions.unlock_login.success', ['name' => $staff->name]));

    // Mọi lần hỏng của địa chỉ đó đều của CHÍNH tài khoản này: không phải NAT, nên chiều IP cũng xoá.
    expect(StaffLoginThrottle::tooManyAttempts([$accountKey]))->toBeFalse()
        ->and(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeFalse();

    auth('web')->logout();

    submitStaffPassword($staff, 'mat-khau-dung')->assertHasNoErrors();
});

it('§10.3 unlocks the admin code step too, after five real wrong codes', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    $component = submitStaffPassword($staff, 'mat-khau-dung')->assertHasNoErrors();

    foreach (range(1, 5) as $ignored) {
        $component->set('data.multiFactor.app.code', staffWrongTotp($staff))->call('authenticate');
    }

    [$accountKey, $ipKey] = StaffLoginThrottle::codeKeys($staff);

    expect(StaffLoginThrottle::tooManyAttempts([$accountKey]))->toBeTrue()
        ->and(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeTrue();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getKey()])
        ->callAction('unlockLogin')
        ->assertNotified(__('users.actions.unlock_login.success', ['name' => $staff->name]));

    expect(StaffLoginThrottle::tooManyAttempts([$accountKey]))->toBeFalse()
        // Dòng `step=code` do trang đăng nhập ghi là thứ cho phép chiều IP của bước mã xoá được.
        ->and(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeFalse();

    auth('web')->logout();

    $component->set('data.multiFactor.app.code', staffTotp($staff))->call('authenticate')->assertHasNoErrors();

    expect(auth('web')->check())->toBeTrue();
});

it('§10.3 keeps a shared address lock when someone else also failed there, and tells the admin how long', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);
    $other = User::factory()->withRole(Role::Lawyer)->create();

    // Thứ tự bắt buộc: người kia thử TRƯỚC (khi chiều IP còn sạch, dòng nhật ký của họ được ghi);
    // sau khi IP chạm trần, chính lần thử của họ bị chặn ở cổng và không để lại dòng nào.
    submitStaffPassword($other, 'sai-mat-khau');
    staffLockedOut($staff, 4);

    $ipKey = StaffLoginThrottle::passwordIpKeyFor('127.0.0.1');
    $accountKey = StaffLoginThrottle::passwordAccountKey($staff->email);

    expect(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeTrue();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getKey()])
        ->callAction('unlockLogin')
        ->assertNotified(__('users.actions.unlock_login.success_ip_still_locked', ['name' => $staff->name, 'minutes' => 15]));

    expect(StaffLoginThrottle::tooManyAttempts([$accountKey]))->toBeFalse()
        ->and(StaffLoginThrottle::tooManyAttempts([$ipKey]))->toBeTrue();
});

it('§10.3 does not let a portal failure at the same address make a staff address lock look shared', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);
    $client = ClientUser::factory()->activated()->create();

    // Một dòng login_failed của guard `client` ở cùng địa chỉ, cùng bước, TRƯỚC khi nhân sự thử.
    Activity::query()->create([
        'log_name' => 'default',
        'description' => 'login_failed',
        'event' => 'login_failed',
        'subject_type' => $client->getMorphClass(),
        'subject_id' => $client->getKey(),
        'causer_type' => $client->getMorphClass(),
        'causer_id' => $client->getKey(),
        'properties' => ['guard' => 'client', 'step' => 'password', 'ip' => '127.0.0.1'],
    ]);

    staffLockedOut($staff);

    $this->actingAs($admin, 'web');

    // Hai guard có rổ đếm KHÁC NHAU: dòng của khách không tiêu lượt nào trong rổ của nhân sự, nên
    // chiều IP của nhân sự vẫn xoá được (mọi lần hỏng CỦA GUARD NÀY đều thuộc về tài khoản).
    $this->livewire(EditUser::class, ['record' => $staff->getKey()])
        ->callAction('unlockLogin')
        ->assertNotified(__('users.actions.unlock_login.success', ['name' => $staff->name]));

    expect(StaffLoginThrottle::tooManyAttempts([StaffLoginThrottle::passwordIpKeyFor('127.0.0.1')]))->toBeFalse();
});

it('§10.3 writes an audit line when an admin unlocks a staff account', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    staffLockedOut($staff);

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $staff->getKey()])->callAction('unlockLogin');

    $row = Activity::query()->where('event', 'staff_login_unlocked')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->subject?->is($staff))->toBeTrue()
        ->and($row->causer?->is($admin))->toBeTrue()
        ->and($row->properties->get('guard'))->toBe('web')
        ->and($row->properties->get('ip_still_locked'))->toBeFalse();
});

it('§10.3 lets only admins unlock a staff login', function (Role $role, bool $allowed) {
    $actor = User::factory()->withRole($role)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();

    expect($actor->can('unlockLogin', $target))->toBe($allowed);
})->with([
    'admin' => [Role::Admin, true],
    'manager' => [Role::Manager, false],
    'lawyer' => [Role::Lawyer, false],
    'assistant' => [Role::Assistant, false],
    'accountant' => [Role::Accountant, false],
]);

it('§10.3 refuses the unlock action when a non-admin forces the call, and leaves the lock in place', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();

    staffLockedOut($target);

    $this->actingAs($lawyer, 'web');

    // Bị từ chối ở cổng Livewire/Gate — dù bằng 403 hay 404 — và khoá đếm còn nguyên.
    try {
        $this->livewire(EditUser::class, ['record' => $target->getKey()])->callAction('unlockLogin');
    } catch (Throwable) {
        // Từ chối bằng ngoại lệ cũng là một lời từ chối.
    }

    expect(StaffLoginThrottle::tooManyAttempts([StaffLoginThrottle::passwordAccountKey($target->email)]))->toBeTrue()
        ->and(Activity::query()->where('event', 'staff_login_unlocked')->exists())->toBeFalse();
});

it('§10.3 stops loudly if Filament ever asks the admin login page for a different number of attempts', function () {
    $page = new class extends Login
    {
        public function callRateLimit(int $attempts): void
        {
            $this->rateLimit($attempts);
        }
    };

    expect(fn () => $page->callRateLimit(10))->toThrow(LogicException::class);
});

it('§10.3 still finds the SPEC number at the Filament call site the admin login page overrides', function () {
    $source = file_get_contents(base_path('vendor/filament/filament/src/Auth/Pages/Login.php'));

    expect($source)->toContain('$this->rateLimit('.StaffLoginThrottle::MAX_ATTEMPTS.')');
});
