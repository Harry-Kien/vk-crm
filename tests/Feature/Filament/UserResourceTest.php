<?php

use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('lets an admin open every staff administration page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->create();

    $this->actingAs($admin, 'web')->get(UserResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(UserResource::getUrl('create', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(UserResource::getUrl('edit', ['record' => $staff], panel: 'admin'))->assertOk();
});

/** settings.manage chỉ admin có (SPEC §5): quản lý cũng không được ghi hồ sơ nhân sự. */
it('hides the staff write pages from a manager', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $staff = User::factory()->create();

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($manager, 'web')->get(UserResource::getUrl('create', panel: 'admin'))->assertNotFound();
    $this->actingAs($manager, 'web')->get(UserResource::getUrl('edit', ['record' => $staff], panel: 'admin'))->assertNotFound();
});

it('never renders the password hash or two-factor secret in the staff table response', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->create([
        'two_factor_secret' => 'super-secret-otp-seed',
    ]);

    $response = $this->actingAs($admin, 'web')->get(UserResource::getUrl('index', panel: 'admin'));

    $response->assertOk();
    $response->assertDontSee($staff->password, escape: false);
    $response->assertDontSee('super-secret-otp-seed');
});

/**
 * User::assignRoleFromPosition() — bình luận trên model ghi rõ Action/trang sửa nhân sự M3 phải
 * gọi lại hàm này khi chức danh được đặt hoặc đổi, để vai trò spatie luôn khớp chức danh.
 */
it('assigns a spatie role matching the chosen position when a staff record is created', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Nhân sự mới',
            'email' => 'nhansumoi@luatvukhang.com',
            'position' => UserPosition::Lawyer->value,
            'password' => 'password',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'nhansumoi@luatvukhang.com')->firstOrFail();

    expect($created->hasRole(Role::Lawyer->value))->toBeTrue();
});

it('re-syncs the spatie role when an existing staff record changes position', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $staff = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['position' => UserPosition::Manager->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $staff->refresh();

    expect($staff->hasRole(Role::Manager->value))->toBeTrue()
        ->and($staff->hasRole(Role::Lawyer->value))->toBeFalse();
});
