<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

/**
 * Task 20 (phát hiện "nhân sự không có chỗ nào tự đổi mật khẩu"): `AdminPanelProvider::panel()`
 * giờ gọi `->profile()`.
 *
 * Fix round 1 (ruling "staff profile page"): trang đăng ký giờ là `App\Filament\Admin\Pages\Auth\EditProfile`
 * — MỘT lớp con NHỎ của `Filament\Auth\Pages\EditProfile` (không còn dùng nguyên bản mặc định),
 * chỉ ghi đè ô email thành chỉ đọc (xem docblock của lớp đó cho lý do). Mọi hành vi khác (mật khẩu
 * đòi `PasswordRule::default()`, mật khẩu hiện tại bắt buộc khi đổi mật khẩu, không có khối 2FA)
 * vẫn là hành vi KẾ THỪA từ lớp cha, và các test dưới đây đo lại chúng qua lớp con này để chắc
 * chắn việc ghi đè không lặng lẽ làm mất chúng.
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

/**
 * Fix round 1 (ruling): "Filament's stock EditProfile lets staff change their own login email
 * without re-verification. Make the email field read-only on the admin profile; an admin changes
 * a staff login email through EditUser."
 *
 * `->set('data.email', …)` THẲNG vào property Livewire — không phải `fillForm()` — để mô phỏng
 * "request bị chỉnh sửa tay" mà chính Filament cảnh báo (`CanBeDisabled::disabled()`: "skilled
 * users can manipulate Livewire's JavaScript to bypass the disabled state") — cùng thành ngữ đã
 * dùng ở `ClientUserResourceTest` ("...tampered livewire request sets it false directly").
 *
 * **Đã tự đo, và nói thẳng ra đây:** với đúng test này, ngay cả tắt hẳn
 * `EditProfile::mutateFormDataBeforeSave()` (chỉ còn `disabled()`), test vẫn XANH. Lý do đọc được
 * trong chính `CanBeDisabled::disabled($condition)`: `$this->saved(fn ($c) =>
 * ! $c->evaluate($condition))`, và điều kiện ở `getEmailFormComponent()` là `true` TRẦN — không
 * phụ thuộc bất kỳ state nào một request có thể ảnh hưởng — nên `Schema::getState()` loại hẳn
 * `email` khỏi `$data` một cách VÔ ĐIỀU KIỆN, không phân biệt property thô phía sau đang giữ gì.
 * `->set()` vì vậy không dựng lại được đúng lỗ hổng gốc (một field CÓ ĐIỀU KIỆN đổi
 * disabled/dehydrated theo state) — nó vẫn là bằng chứng ĐÚNG cho hành vi cuối cùng (email không
 * đổi), chỉ không phải bằng chứng cho VAI TRÒ của `mutateFormDataBeforeSave()`.
 *
 * Vai trò của hook đó được đo RIÊNG, độc lập với toàn bộ vòng Livewire/`disabled()`, ở test kế
 * tiếp — cùng thành ngữ `ClientUserResourceTest` đã dùng cho `mutateFormDataBeforeCreate()`
 * ("the create-page mutate hook itself rejects... independent of form validation").
 *
 * Kèm `currentPassword` đúng: `getCurrentPasswordFormComponent()` của lớp cha tự bắt buộc ô này
 * khi `$get('email')` khác giá trị đang có trên bản ghi — một hệ quả ĐÚNG của việc gửi thẳng state
 * thô, không liên quan tới điều test này đang đo (email có đổi được hay không), nên phải điền vào
 * để cô lập đúng một điều kiện.
 */
it('cannot change the login email from the admin profile page', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create([
        'email' => 'cu@luatvukhang.com',
        'password' => 'mat-khau-cu-1',
    ]);

    $this->actingAs($staff, 'web');

    $this->livewire(EditProfile::class)
        ->set('data.email', 'khac@luatvukhang.com')
        ->set('data.currentPassword', 'mat-khau-cu-1')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->email)->toBe('cu@luatvukhang.com');
});

/**
 * Vế "hook tự nó chặn", độc lập với `disabled()`/Livewire — xem docblock của test ngay trên. Gọi
 * thẳng `mutateFormDataBeforeSave()` qua reflection với một `$data['email']` giả mạo, bỏ qua toàn
 * bộ schema, để chứng minh chính dòng code trong hook thật sự ghi đè, không phải "chết" (không
 * bao giờ chạy tới) đằng sau `disabled()`.
 */
it('the mutate hook itself resets a tampered email, independent of the disabled() form field', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['email' => 'cu@luatvukhang.com']);

    $this->actingAs($staff, 'web');

    $page = new EditProfile;
    $method = new ReflectionMethod($page, 'mutateFormDataBeforeSave');
    $method->setAccessible(true);

    $result = $method->invoke($page, ['email' => 'gia-mao@evil.test', 'name' => 'Tên mới']);

    expect($result['email'])->toBe('cu@luatvukhang.com')
        ->and($result['name'])->toBe('Tên mới');
});

/** Fix round 1 (ruling): "Apply PasswordRule::default() to the new-password field." */
it('refuses a weak new password on the admin profile page', function () {
    $staff = User::factory()->withRole(Role::Lawyer)->create(['password' => 'mat-khau-cu-1']);

    $this->actingAs($staff, 'web');

    $this->livewire(EditProfile::class)
        ->fillForm([
            'currentPassword' => 'mat-khau-cu-1',
            'password' => '1',
            'passwordConfirmation' => '1',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('mat-khau-cu-1', $staff->fresh()->password))->toBeTrue();
});
