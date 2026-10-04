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
        ->and($accountant->can(Permission::SettingsManage->value))->toBeFalse()
        // M9 (SPEC §5, bổ sung 2026-09-19, sửa 2026-09-24): kế toán thấy và ghi TIỀN, không
        // soạn hợp đồng — và vẫn không có `matter.view` ở trên.
        ->and($accountant->can(Permission::BillingView->value))->toBeTrue()
        ->and($accountant->can(Permission::PaymentRecord->value))->toBeTrue()
        ->and($accountant->can(Permission::RevenueViewAny->value))->toBeTrue()
        ->and($accountant->can(Permission::ContractManage->value))->toBeFalse()
        // M10 (SPEC §5, bổ sung 2026-09-24): kế toán không thấy gì của tiếp nhận.
        ->and($accountant->can(Permission::IntakeCreate->value))->toBeFalse()
        ->and($accountant->can(Permission::IntakeViewAny->value))->toBeFalse()
        ->and($accountant->can(Permission::IntakeConvert->value))->toBeFalse();
});

/*
 * `EnumLabelsTest` chỉ bắt nhãn thiếu ở dạng `enums.…`; nhãn quyền đọc từ `lang/vi/permissions.php`,
 * nên một quyền thiếu nhãn trả về chính khoá `permissions.…` và lọt qua test đó. Bốn quyền M9 là
 * lần đầu bảng quyền đổi kể từ M2; ba quyền M10 (nhóm intake) là lần thứ hai. Chốt ở đây cho cả hai mươi.
 */
it('gives every permission a vietnamese label', function () {
    expect(Permission::cases())->toHaveCount(20);

    foreach (Permission::cases() as $permission) {
        expect($permission->label())->not->toStartWith('permissions.', "{$permission->value} thiếu nhãn trong lang/vi/permissions.php");
    }
});

it('matches the spec permission table for every role', function () {
    $expected = [
        Role::Admin->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'settings.manage', 'auditLog.view',
            'billing.view', 'contract.manage', 'payment.record', 'revenue.viewAny',
            'intake.create', 'intake.viewAny', 'intake.convert',
        ],
        Role::Manager->value => [
            'matter.viewAny', 'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'client.manage', 'clientUser.manage', 'auditLog.view',
            'billing.view', 'contract.manage', 'revenue.viewAny',
            'intake.create', 'intake.viewAny', 'intake.convert',
        ],
        Role::Lawyer->value => [
            'matter.view', 'matter.create', 'matter.update', 'matter.transitionStage',
            'stageLog.publish', 'document.viewInternal', 'document.publish', 'checklist.review',
            'clientUser.manage',
            'billing.view', 'contract.manage',
            'intake.create', 'intake.convert',
        ],
        Role::Assistant->value => [
            'matter.view', 'matter.update', 'checklist.review', 'client.manage', 'clientUser.manage',
            'intake.create',
        ],
        Role::Accountant->value => ['matter.viewAny', 'billing.view', 'payment.record', 'revenue.viewAny'],
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

/**
 * Minor (fix round 1, M6.5 Task 4): ghim đúng tiền đề mà
 * `App\Policies\UserPolicy::delete()` dựa vào để KHÔNG hỏi lại
 * `GuardsStaffOffboarding::wouldLeaveNoActiveAdmin()` — "chỉ ai có `settings.manage` (tức LÀ
 * admin) mới qua được cổng `viewAny()`". Lý lẽ đó (nhánh "admin cuối cùng" của `delete()` là mã
 * chết) SỤP ĐỔ ngay nếu một vai trò KHÁC Admin từng có `settings.manage` — test
 * "matches the spec permission table for every role" ở trên đã ghim điều này gián tiếp (mảng đầy
 * đủ của từng vai); test này ghim TRỰC TIẾP, đúng câu mà docblock kia viện dẫn, để một lần đổi
 * BẢNG QUYỀN không âm thầm biến một lập luận đã đóng thành một lỗ hổng.
 */
it('grants settings.manage to admin only, pinning the premise UserPolicy::delete() relies on', function () {
    foreach (Role::cases() as $role) {
        $hasSettingsManage = in_array(Permission::SettingsManage, $role->permissions(), true);

        expect($hasSettingsManage)->toBe(
            $role === Role::Admin,
            "vai trò {$role->value} ".($role === Role::Admin ? 'phải' : 'không được').' có settings.manage',
        );
    }
});

it('creates a missing role row rather than throwing', function () {
    Spatie\Permission\Models\Role::query()->delete();

    $user = User::factory()->create(['position' => UserPosition::Lawyer]);

    // Gọi THẲNG: `expect(fn () => ...)->not->toThrow(Throwable::class)` không thể đỏ được
    // (`Throwable` là interface, `class_exists` trả `false`, nhánh `not` của Pest nuốt cả hai
    // kiểu thất bại) — xem ghi chú dài hơn ở `tests/Feature/ActivityLogTest.php`. Một hàng rào
    // không thể đổ thì không phải hàng rào.
    $user->assignRoleFromPosition();

    expect($user->fresh()->hasRole(Role::Lawyer->value))->toBeTrue();
});
