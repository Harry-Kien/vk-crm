<?php

use App\Actions\User\ResetStaffTwoFactor;
use App\Enums\Role;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * "Đặt lại 2FA" (R2, kế hoạch M8 Task 2) — {@see ResetStaffTwoFactor}, hai đường vào:
 * `EditUser::getHeaderActions()` (Livewire) và `vkcrm:reset-2fa` (artisan). Test Ở TẦNG ACTION
 * (gọi thẳng `handle()`) cho luật riêng của chính Action — cùng quy ước
 * `tests/Feature/Actions/User/DeleteStaffMemberTest.php`; test màn hình/lệnh riêng ở hai khối
 * dưới, qua Livewire/`$this->artisan()` như luật làn đòi.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/*
|--------------------------------------------------------------------------
| Tầng Action
|--------------------------------------------------------------------------
*/

it('xoá secret và mã khôi phục 2FA của người bị đặt lại', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();

    expect($target->two_factor_secret)->not->toBeNull();

    app(ResetStaffTwoFactor::class)->handle($admin, $target);

    expect($target->fresh()->two_factor_secret)->toBeNull()
        ->and($target->fresh()->two_factor_recovery_codes)->toBeNull();
});

it('từ chối tự đặt lại 2FA của chính mình', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(ResetStaffTwoFactor::class)->handle($admin, $admin))
        ->toThrow(LogicException::class);

    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
});

/** `$actor = null` (đường console) không tự áp luật "không tự đặt lại" — không có "chính mình" khi không ai gọi cả. */
it('actor null (đường console) không ném lỗi tự đặt lại', function () {
    $target = User::factory()->withRole(Role::Lawyer)->create();

    app(ResetStaffTwoFactor::class)->handle(null, $target);

    expect($target->fresh()->two_factor_secret)->toBeNull();
});

it('xoá mọi phiên đang mở của người bị đặt lại, không đụng phiên của người khác', function () {
    config(['session.driver' => 'database']);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();
    $bystander = User::factory()->withRole(Role::Lawyer)->create();

    DB::table('sessions')->insert([
        ['id' => 'sess-target-1', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp],
        ['id' => 'sess-target-2', 'user_id' => $target->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp],
        ['id' => 'sess-bystander', 'user_id' => $bystander->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp],
    ]);

    app(ResetStaffTwoFactor::class)->handle($admin, $target);

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $bystander->id)->count())->toBe(1);
});

it('đổi remember_token của người bị đặt lại — cookie ghi nhớ đăng nhập cũ không mở lại được phiên', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create(['remember_token' => 'token-cu']);

    app(ResetStaffTwoFactor::class)->handle($admin, $target);

    expect($target->fresh()->remember_token)->not->toBe('token-cu')
        ->and($target->fresh()->remember_token)->not->toBeNull();
});

it('ghi audit staff_two_factor_reset với via=admin khi có actor', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();

    app(ResetStaffTwoFactor::class)->handle($admin, $target);

    $row = Activity::query()->where('event', 'staff_two_factor_reset')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer?->is($admin))->toBeTrue()
        ->and($row->subject?->is($target))->toBeTrue()
        ->and($row->properties->get('via'))->toBe('admin');
});

it('ghi audit staff_two_factor_reset với via=console và causer rỗng khi actor null', function () {
    $target = User::factory()->withRole(Role::Lawyer)->create();

    app(ResetStaffTwoFactor::class)->handle(null, $target);

    $row = Activity::query()->where('event', 'staff_two_factor_reset')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->causer)->toBeNull()
        ->and($row->properties->get('via'))->toBe('console');
});

/** Không log secret/mã khôi phục thô — cùng luật SensitivePropertyFilter cho hai cột này. */
it('không ghi secret hay mã khôi phục cũ vào properties của dòng nhật ký', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

    app(ResetStaffTwoFactor::class)->handle($admin, $target);

    $row = Activity::query()->where('event', 'staff_two_factor_reset')->latest('id')->firstOrFail();

    expect($row->properties->toJson())->not->toContain('JBSWY3DPEHPK3PXP');
});

/*
|--------------------------------------------------------------------------
| Màn hình — nút "Đặt lại 2FA" trên EditUser
|--------------------------------------------------------------------------
*/

it('admin đặt lại 2FA của một nhân sự khác qua nút trên EditUser', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $target = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertActionVisible('resetTwoFactor')
        ->callAction('resetTwoFactor');

    expect($target->fresh()->two_factor_secret)->toBeNull();
});

/** Nút không hiện trên chính hồ sơ của người đang thao tác — cùng luật "không tự đặt lại". */
it('ẩn nút Đặt lại 2FA khi admin mở đúng trang sửa của chính mình', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->assertActionHidden('resetTwoFactor');
});

/**
 * Ép gọi thẳng action dù nút ẩn. Đo trực tiếp bằng instrumentation (không suy luận): sau
 * `->mountAction('resetTwoFactor')` từ một Livewire test call RIÊNG (không cùng lời gọi với lúc
 * trang vừa mount), `$component->instance()->mountedActions` là mảng RỖNG — action không mount
 * được, dù `assertActionExists('resetTwoFactor')` ngay trước đó vẫn tìm thấy nó. Không dựng lại
 * đúng cơ chế nội bộ của Filament ở đây (không phải phạm vi của test này) — chỉ ghim HÀNH VI
 * quan sát được: một action `->visible(false)` không mount được qua đường test-harness thông
 * thường, nên `Gate::authorize()` bên trong `action()` không có cơ hội chạy cho ĐÚNG kịch bản
 * này. Test đo hệ quả cuối: không có gì được mount, secret không đổi — cùng thành ngữ
 * `StaffEditProfileTest::assertActionNotMounted()`.
 *
 * `Gate::authorize()` trong `action()` vẫn không thừa: nó là lớp phòng thủ cho một cuộc đua KHÁC
 * mà cách chặn "biến mất khỏi mount" ở trên không với tới — ví dụ nút hiện ĐÚNG lúc mở (đang sửa
 * người KHÁC), rồi quyền của actor thay đổi giữa lúc mở modal và lúc bấm xác nhận — cùng tinh
 * thần `Gate::authorize()` lặp lại trong `EditClientUser::unlockLogin`.
 */
it('ép gọi hành động đặt lại 2FA cho chính mình không mount được gì cả — action biến mất khỏi schema khi ẩn', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $this->livewire(EditUser::class, ['record' => $admin->getRouteKey()])
        ->mountAction('resetTwoFactor')
        ->assertActionNotMounted();

    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Lệnh console vkcrm:reset-2fa
|--------------------------------------------------------------------------
*/

it('vkcrm:reset-2fa {email} đặt lại 2FA của đúng người, không cần đăng nhập admin nào', function () {
    $target = User::factory()->withRole(Role::Lawyer)->create();

    $this->artisan('vkcrm:reset-2fa', ['email' => $target->email])
        ->assertSuccessful();

    expect($target->fresh()->two_factor_secret)->toBeNull();

    $row = Activity::query()->where('event', 'staff_two_factor_reset')->latest('id')->firstOrFail();
    expect($row->properties->get('via'))->toBe('console')
        ->and($row->causer)->toBeNull();
});

it('vkcrm:reset-2fa báo lỗi và không làm gì với một email không tồn tại', function () {
    $this->artisan('vkcrm:reset-2fa', ['email' => 'khong-ton-tai@luatvukhang.com'])
        ->assertFailed();

    expect(Activity::query()->where('event', 'staff_two_factor_reset')->exists())->toBeFalse();
});
