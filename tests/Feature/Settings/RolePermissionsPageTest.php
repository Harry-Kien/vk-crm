<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\RolePermissionsPage;
use App\Models\User;
use App\Support\Settings\PermissionGroups;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Làn st Task 1, mục 3 — "Vai trò & quyền": ma trận sáu vai × quyền theo nhóm, ĐỌC-CHỈ, dựng từ
 * `Role::permissions()` (không chép tay), nhãn tiếng Việt từ `Permission::label()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

it('opens for an admin only', function (?Role $role) {
    $user = $role === null ? $this->admin : User::factory()->withRole($role)->create();

    $response = $this->actingAs($user, 'web')->get(RolePermissionsPage::getUrl(panel: 'admin'));

    $role === null ? $response->assertOk()->assertSee(__('settings.roles.title')) : $response->assertNotFound();
})->with([
    'admin' => [null],
    'trưởng phòng' => [Role::Manager],
    'luật sư' => [Role::Lawyer],
    'trợ lý' => [Role::Assistant],
    'kế toán' => [Role::Accountant],
    'kinh doanh' => [Role::Sales],
]);

/**
 * Mỗi ô của ma trận đúng bằng `Role::permissions()` — sáu vai × mọi quyền. Mutation probe: dựng ô từ
 * `in_array($permission, Role::Manager->permissions())` cho mọi vai → hàng của luật sư, kế toán… đỏ.
 */
it('marks every cell exactly as Role::permissions() says, for all six roles', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(RolePermissionsPage::class);

    foreach (Role::cases() as $role) {
        $page->assertSee($role->label());

        foreach (Permission::cases() as $permission) {
            $granted = in_array($permission, $role->permissions(), true) ? '1' : '0';

            $page->assertSeeHtml('data-role="'.$role->value.'" data-permission="'.$permission->value.'" data-granted="'.$granted.'"');
        }
    }
});

it('labels every permission in Vietnamese and puts each in exactly one group', function () {
    $this->actingAs($this->admin, 'web');

    $grouped = collect(PermissionGroups::all())->flatten()->all();

    expect(PermissionGroups::unplaced())->toBe([], 'Quyền mới chưa được xếp nhóm ở App\Support\Settings\PermissionGroups::GROUPS.')
        ->and($grouped)->toHaveCount(count(Permission::cases()))
        ->and(collect($grouped)->map->value->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());

    $page = Livewire::test(RolePermissionsPage::class);

    foreach (array_keys(PermissionGroups::all()) as $group) {
        $page->assertSee(__("settings.roles.groups.{$group}"));
    }

    foreach (Permission::cases() as $permission) {
        expect($permission->label())->not->toBe('permissions.'.$permission->value);
        $page->assertSee($permission->label());
    }
});

it('is read-only: no form, no save, and says where a person\'s role is changed', function () {
    $this->actingAs($this->admin, 'web');

    Livewire::test(RolePermissionsPage::class)
        ->assertDontSeeHtml('wire:submit')
        ->assertSee(__('settings.roles.read_only'));
});

it('counts the active people holding each role', function () {
    User::factory()->count(2)->withRole(Role::Lawyer)->create();
    User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    $this->actingAs($this->admin, 'web');

    Livewire::test(RolePermissionsPage::class)
        ->assertSeeHtml('data-role-count="lawyer">'.__('settings.roles.people', ['count' => 2]).'<');
});
