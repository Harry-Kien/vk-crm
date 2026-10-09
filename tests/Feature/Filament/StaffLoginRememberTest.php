<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Cookie;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A4): trang đăng nhập NỘI BỘ không còn ô
 * "Ghi nhớ đăng nhập". Lớp cha của Filament nạp `form()` với ba trường (email, mật khẩu, remember);
 * tích ô đó thì `SessionGuard` phát cookie recaller sống 400 ngày, và mỗi phiên mới trên máy đó vào
 * thẳng hồ sơ khách không qua mật khẩu lẫn mã 2FA — trái SPEC §10 mục 7 (2FA bắt buộc cho mọi tài
 * khoản nội bộ). Cổng khách đã bỏ ô này từ `portal/portal-1` (`tests/Feature/Portal/LoginTest.php`).
 *
 * Cookie đã phát từ trước bản sửa bị vô hiệu bằng migration
 * `2026_10_09_300002_forget_staff_remember_tokens` (test cuối tệp).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('has no remember-me checkbox on the staff login form', function () {
    $this->livewire(Login::class)
        ->assertFormFieldDoesNotExist('remember');
});

/**
 * `set('data.remember', true)` đi thẳng vào state thô của Livewire, đúng hình dạng một request bị
 * chỉnh tay. Đo bằng cookie recaller THẬT được xếp hàng, không chỉ đọc lại `$data`.
 */
it('never queues a remember-me cookie for staff, even when a tampered request sends remember=1', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung']);

    $component = $this->livewire(Login::class)
        ->set('data.email', $staff->email)
        ->set('data.password', 'mat-khau-dung')
        ->set('data.remember', true)
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $component
        ->set('data.remember', true)
        ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($staff->two_factor_secret))
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('web')->check())->toBeTrue();

    /** @var SessionGuard $guard */
    $guard = auth('web');

    expect(Cookie::queued($guard->getRecallerName()))->toBeNull();
});

it('forgets every staff remember token issued before the fix, so an old 400-day cookie no longer logs anyone in', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();
    $staff->forceFill(['remember_token' => 'token-cu-tu-truoc-ban-sua'])->save();

    (require database_path('migrations/2026_10_09_300002_forget_staff_remember_tokens.php'))->up();

    expect($staff->fresh()->remember_token)->toBeNull()
        ->and(auth('web')->getProvider()->retrieveByToken($staff->id, 'token-cu-tu-truoc-ban-sua'))->toBeNull();
});
