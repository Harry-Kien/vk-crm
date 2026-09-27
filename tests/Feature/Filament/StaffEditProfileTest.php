<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

/**
 * Task 20 (phát hiện "nhân sự không có chỗ nào tự đổi mật khẩu"): `AdminPanelProvider::panel()`
 * giờ gọi `->profile()`, đăng ký trang hồ sơ cá nhân MẶC ĐỊNH của Filament
 * (`Filament\Auth\Pages\EditProfile`, không ghi đè — xem docblock ở nơi gọi `->profile()`).
 *
 * Test ở đây không đo lại TOÀN BỘ hành vi có sẵn của Filament (đó là việc của bộ test riêng của
 * Filament), chỉ đo đúng điều Controller decision đòi: đổi mật khẩu cần mật khẩu hiện tại, và
 * trang này không có nút tắt 2FA nào (panel `admin` không bật `->multiFactorAuthentication()`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Kiểm ĐÚNG điều `->profile()` thêm vào: một route thật cho panel `admin`. Test còn lại trong tệp
 * này lái thẳng `Livewire::test(EditProfile::class)` — component đó chạy được độc lập với việc
 * panel có đăng ký route cho nó hay không, nên chỉ MỘT MÌNH chúng không chứng minh được
 * `->profile()` đã được gọi. Test này bắt đúng khoảng trống đó: gọi `EditProfile::getUrl()` yêu
 * cầu route đã đăng ký tồn tại (`RouteNotFoundException` nếu không), và request thật phải
 * `assertOk()`.
 */
it('registers a reachable profile route for the admin panel', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($staff, 'web')
        ->get(EditProfile::getUrl(panel: 'admin'))
        ->assertOk();
});

it('refuses to change a staff password without the current password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-cu-1']);

    $this->actingAs($staff, 'web');

    $this->livewire(EditProfile::class)
        ->fillForm([
            'password' => 'mat-khau-moi-2',
            'passwordConfirmation' => 'mat-khau-moi-2',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('mat-khau-cu-1', $staff->fresh()->password))->toBeTrue();
});

it('changes a staff password when the current password is given correctly', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-cu-1']);

    $this->actingAs($staff, 'web');

    $this->livewire(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'mat-khau-cu-1',
            'password' => 'mat-khau-moi-2',
            'passwordConfirmation' => 'mat-khau-moi-2',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('mat-khau-moi-2', $staff->fresh()->password))->toBeTrue();
});

/** Vế âm bắt buộc của mật khẩu hiện tại SAI. */
it('refuses to change a staff password when the current password is wrong', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-cu-1']);

    $this->actingAs($staff, 'web');

    $this->livewire(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'khong-phai-mat-khau-cu',
            'password' => 'mat-khau-moi-2',
            'passwordConfirmation' => 'mat-khau-moi-2',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('mat-khau-cu-1', $staff->fresh()->password))->toBeTrue();
});

/**
 * Controller decision: "không dựng gì giả định 2FA tắt được" — panel admin không bật MFA nên
 * trang không có khối 2FA nào để mà có nút tắt.
 */
it('has no multi-factor authentication block on the admin profile page', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($staff, 'web');

    expect(Filament::getPanel('admin')->hasMultiFactorAuthentication())->toBeFalse();
});
