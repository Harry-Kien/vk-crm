<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Spatie\Activitylog\Models\Activity;

/**
 * Dấu vết đăng nhập nhân sự guard `web` (SPEC §10.6, Task 20, phát hiện "lượt rà soát cuối").
 *
 * Panel `admin` KHÔNG viết lại `Filament\Auth\Pages\Login` (khác cổng khách hàng — xem
 * `App\Filament\Portal\Pages\Auth\Login` và docblock `App\Listeners\RecordStaffLogin`), nên test
 * ở đây lái thẳng trang đăng nhập MẶC ĐỊNH của Filament qua Livewire, đúng đường một nhân sự thật
 * đi qua.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('logs a login_failed row and does not touch last_login_at on a wrong password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    expect($staff->last_login_at)->toBeNull();

    $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'khong-dung')
        ->call('authenticate')
        ->assertHasFormErrors();

    $failure = Activity::query()->where('event', 'login_failed')->latest('id')->first();

    expect($failure)->not->toBeNull()
        ->and($failure->properties->get('guard'))->toBe('web')
        ->and($failure->causer?->is($staff))->toBeTrue()
        ->and($staff->fresh()->last_login_at)->toBeNull();
});

/** Vế "email không ứng với tài khoản nào" của SPEC §10.6 vẫn phải để lại dấu vết, kèm IP. */
it('logs a login_failed row with no causer for an email that matches no staff account', function () {
    $this->livewire(Login::class)
        ->set('data.email', 'khong-ton-tai@luatvukhang.com')
        ->set('data.password', 'bat-ky-gi')
        ->call('authenticate')
        ->assertHasFormErrors();

    $failure = Activity::query()->where('event', 'login_failed')->latest('id')->first();

    expect($failure)->not->toBeNull()
        ->and($failure->causer)->toBeNull()
        ->and($failure->properties->get('guard'))->toBe('web')
        ->and($failure->properties->get('email'))->toBe('khong-ton-tai@luatvukhang.com')
        ->and($failure->properties->get('ip'))->not->toBeNull();
});

it('logs a login_success row and stamps last_login_at on a correct password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $success = Activity::query()->where('event', 'login_success')->latest('id')->first();

    expect($success)->not->toBeNull()
        ->and($success->causer?->is($staff))->toBeTrue()
        ->and($success->subject?->is($staff))->toBeTrue()
        ->and($success->properties->get('guard'))->toBe('web')
        ->and($staff->fresh()->last_login_at)->not->toBeNull();
});

/** Đăng nhập sai rồi đúng, cùng một tài khoản: cả hai dòng phải cùng tồn tại (test bắt buộc của brief). */
it('logs both a failed and a successful attempt when a staff member mistypes then corrects their password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'sai-roi')
        ->call('authenticate')
        ->assertHasFormErrors();

    $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'login_failed')->where('causer_id', $staff->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'login_success')->where('causer_id', $staff->id)->exists())->toBeTrue()
        ->and($staff->fresh()->last_login_at)->not->toBeNull();
});

/** Vế âm bắt buộc: một tài khoản bị vô hiệu hoá không đăng nhập được, và điều đó vẫn là login_failed. */
it('logs a login_failed row when a deactivated staff account is tried', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung', 'is_active' => false]);

    $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(Activity::query()->where('event', 'login_failed')->where('causer_id', $staff->id)->exists())->toBeTrue()
        ->and($staff->fresh()->last_login_at)->toBeNull();
});

/**
 * Final review C-M6: ô "email" của một lần đăng nhập hỏng có thể chứa MẬT KHẨU (người dùng gõ nhầm
 * ô, hay dán nhầm). Listener ghi thẳng `credentials['email']` vào nhật ký, nên một lần như vậy để
 * lại mật khẩu dạng chữ trong `activity_log`. Chỉ ghi khi nó THẬT SỰ là một địa chỉ email.
 */
it('never writes a typed value that is not an email address into the failed-login audit row', function () {
    event(new Failed('web', null, ['email' => 'MatKhau#Bi-Mat-2026', 'password' => 'x']));

    $failure = Activity::query()->where('event', 'login_failed')->latest('id')->firstOrFail();

    expect($failure->properties->toJson())->not->toContain('MatKhau#Bi-Mat-2026')
        ->and($failure->properties->get('email'))->toBeNull()
        ->and($failure->properties->get('guard'))->toBe('web');
});

it('still records a real email address typed into a failed login', function () {
    event(new Failed('web', null, ['email' => 'ai-do@luatvukhang.com', 'password' => 'x']));

    expect(Activity::query()->where('event', 'login_failed')->latest('id')->firstOrFail()->properties->get('email'))
        ->toBe('ai-do@luatvukhang.com');
});
