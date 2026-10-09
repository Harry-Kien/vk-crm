<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục B) — tự thao tác bảo mật để lại dấu vết
|--------------------------------------------------------------------------
| Trước đây nhân sự tự đổi mật khẩu, tự cài 2FA hay tự tạo lại mã khôi phục đều không sinh dòng
| nhật ký nào (SPEC §10.6). Đo qua đúng màn hình nhân sự dùng.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('logs a user_password_changed row when a staff member changes their own password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-cu-1']);

    $this->actingAs($staff, 'web');

    Livewire::test(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'mat-khau-cu-1',
            'password' => 'mat-khau-moi-2-dai-hon',
            'passwordConfirmation' => 'mat-khau-moi-2-dai-hon',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = Activity::query()->where('event', 'user_password_changed')->sole();

    expect($row->causer_id)->toBe($staff->id)
        ->and($row->subject_id)->toBe($staff->id)
        ->and($row->properties->toArray())->toBe(['via' => 'self']);
});

it('logs no password row when the profile is saved without a new password', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($staff, 'web');

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Tên mới của nhân sự'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'user_password_changed')->exists())->toBeFalse();
});

it('logs staff_two_factor_enabled when a staff member sets up two-factor themselves, and no regeneration row', function () {
    $staff = User::factory()->withoutTwoFactor()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung-1']);

    $this->actingAs($staff, 'web');

    $setUp = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent(true, 'content'));

    $issued = decrypt($setUp->instance()->mountedActions[0]['arguments']['encrypted']);

    $setUp->fillForm([
        'code' => app(Google2FA::class)->getCurrentOtp($issued['secret']),
        'password' => 'mat-khau-dung-1',
    ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $row = Activity::query()->where('event', 'staff_two_factor_enabled')->sole();

    expect($row->causer_id)->toBe($staff->id)
        ->and($row->subject_id)->toBe($staff->id)
        ->and(Activity::query()->where('event', 'staff_recovery_codes_regenerated')->exists())->toBeFalse();
});

it('logs staff_recovery_codes_regenerated when a staff member regenerates their recovery codes', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-dung-1']);
    $staff->saveAppAuthenticationRecoveryCodes(['ma-cu-1', 'ma-cu-2']);

    $this->actingAs($staff, 'web');

    Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('regenerateAppAuthenticationRecoveryCodes')->schemaComponent(true, 'content'), data: [
            'password' => 'mat-khau-dung-1',
        ])
        ->assertHasNoFormErrors();

    $row = Activity::query()->where('event', 'staff_recovery_codes_regenerated')->sole();

    expect($row->causer_id)->toBe($staff->id)
        ->and(Activity::query()->where('event', 'staff_two_factor_enabled')->exists())->toBeFalse();
});
