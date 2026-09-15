<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates every role and permission from the spec', function () {
    expect(Spatie\Permission\Models\Role::count())->toBe(count(Role::cases()))
        ->and(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()))
        ->and(Spatie\Permission\Models\Permission::pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());
});

it('grants the admin every permission and the accountant almost none', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    foreach (Permission::cases() as $permission) {
        expect($admin->can($permission->value))->toBeTrue("admin thiếu {$permission->value}");
    }

    expect($accountant->can(Permission::MatterViewAny->value))->toBeTrue()
        ->and($accountant->can(Permission::MatterView->value))->toBeFalse()
        ->and($accountant->can(Permission::MatterCreate->value))->toBeFalse()
        ->and($accountant->can(Permission::SettingsManage->value))->toBeFalse();
});

it('matches the spec permission table for every role', function () {
    $expected = [
        Role::Admin->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'settings.manage', 'auditLog.view',
        ],
        Role::Manager->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'auditLog.view',
        ],
        Role::Lawyer->value => [
            'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'clientUser.manage',
        ],
        Role::Assistant->value => [
            'matter.view', 'matter.update', 'checklist.review', 'client.manage', 'clientUser.manage',
        ],
        Role::Accountant->value => ['matter.viewAny'],
    ];

    foreach ($expected as $roleName => $permissions) {
        $granted = Spatie\Permission\Models\Role::findByName($roleName)
            ->permissions->pluck('name')->sort()->values()->all();

        expect($granted)->toBe(collect($permissions)->sort()->values()->all(), "vai trò {$roleName} sai bộ quyền");
    }
});

it('assigns a role matching the staff position', function () {
    $user = User::factory()->create(['position' => UserPosition::Manager]);
    $user->assignRoleFromPosition();

    expect($user->hasRole(Role::Manager->value))->toBeTrue()
        ->and($user->hasRole(Role::Admin->value))->toBeFalse();
});

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()));
});

it('creates a missing role row rather than throwing', function () {
    Spatie\Permission\Models\Role::query()->delete();

    $user = User::factory()->create(['position' => UserPosition::Lawyer]);

    expect(fn () => $user->assignRoleFromPosition())->not->toThrow(Throwable::class)
        ->and($user->fresh()->hasRole(Role::Lawyer->value))->toBeTrue();
});
