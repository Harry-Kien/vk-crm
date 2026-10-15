<?php

use App\Enums\ContractStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Làn kt (2026-10-15, chủ văn phòng chọn "Cho kế toán nhập hợp đồng"): quyền `contract.enter` — nhập
 * đúng hợp đồng giấy đã ký cho một vụ CÓ SẴN (soạn bản nháp, sửa và xoá bản nháp, kích hoạt với ngày
 * ký), không kèm nội dung hồ sơ. Mọi quyết định thương mại khác (phụ lục, hoàn tất, huỷ, miễn, dời
 * hạn, gửi thư nhắc khách) vẫn chỉ `contract.manage`.
 *
 * Ma trận sáu vai cho từng ability, cộng nhân chứng cấp quyền thẳng (không vai trò) để mỗi điều kiện
 * mới có một cặp âm/dương chỉ khác đúng điều kiện đó — thành ngữ `BillingAccessTest::billingWitness()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->sales = User::factory()->withRole(Role::Sales)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->draft = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    $this->draftInstalment = Instalment::factory()->for($this->draft)->create(['amount' => 10_000_000]);

    // Vụ thứ hai chưa có hợp đồng: câu hỏi `create` cần một vụ trống.
    $this->empty = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->restrictedDraft = Contract::factory()->for($this->restricted)->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
});

/**
 * Bốn câu hỏi của `contract.enter` (nhập hợp đồng đã ký), theo thứ tự: soạn cho vụ, sửa bản nháp,
 * kích hoạt, xoá bản nháp.
 *
 * @return list<bool>
 */
function canEnterContract(User $user, Matter $matter, Contract $draft): array
{
    return [
        $user->can('create', [Contract::class, $matter]),
        $user->can('updateDraft', $draft),
        $user->can('activate', $draft),
        $user->can('delete', $draft),
    ];
}

/**
 * Những câu hỏi vẫn CHỈ `contract.manage`: `update` (phụ lục, hoàn tất, huỷ), miễn, dời hạn, gửi thư
 * nhắc khách.
 *
 * @return list<bool>
 */
function canDecideCommercially(User $user, Contract $contract, Instalment $instalment): array
{
    return [
        $user->can('update', $contract),
        $user->can('waive', $instalment),
        $user->can('reschedule', $instalment),
        $user->can('remindClient', $instalment),
    ];
}

/** @param  list<Permission>  $permissions */
function contractEntryWitness(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));

    return $user;
}

it('grants contract.enter to the accountant and, through every permission, to the admin only', function () {
    foreach (Role::cases() as $role) {
        expect(in_array(Permission::ContractEnter, $role->permissions(), true))->toBe(
            in_array($role, [Role::Admin, Role::Accountant], true),
            "vai {$role->value}",
        );
    }

    expect($this->accountant->can(Permission::ContractEnter->value))->toBeTrue()
        ->and($this->accountant->can(Permission::ContractManage->value))->toBeFalse()
        ->and($this->accountant->can(Permission::MatterView->value))->toBeFalse()
        ->and(Permission::ContractEnter->label())->toBe('Nhập hợp đồng đã ký (giá trị, các đợt thu) cho vụ việc có sẵn');
});

it('answers the four contract-entry questions for each of the six roles on an ordinary matter', function () {
    $matrix = [
        'admin' => [$this->admin, [true, true, true, true]],
        'manager' => [$this->manager, [true, true, true, true]],
        'lawyer' => [$this->lawyer, [true, true, true, true]],
        'assistant' => [$this->assistant, [false, false, false, false]],
        'accountant' => [$this->accountant, [true, true, true, true]],
        'sales' => [$this->sales, [false, false, false, false]],
    ];

    foreach ($matrix as $name => [$user, $expected]) {
        expect(canEnterContract($user, $this->empty, $this->draft))->toBe($expected, "vai {$name}");
    }
});

it('keeps every commercial decision on contract.manage, so the accountant gets none of them', function () {
    $active = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]))->active()->create(['total_amount' => 5_000_000]);
    $instalment = Instalment::factory()->for($active)->create(['amount' => 5_000_000]);

    $matrix = [
        'admin' => [$this->admin, [true, true, true, true]],
        'manager' => [$this->manager, [true, true, true, true]],
        'lawyer' => [$this->lawyer, [true, true, true, true]],
        'assistant' => [$this->assistant, [false, false, false, false]],
        'accountant' => [$this->accountant, [false, false, false, false]],
        'sales' => [$this->sales, [false, false, false, false]],
    ];

    foreach ($matrix as $name => [$user, $expected]) {
        expect(canDecideCommercially($user, $active, $instalment))->toBe($expected, "vai {$name}")
            // Cùng câu `update` trên bản nháp: kế toán sửa nháp bằng `updateDraft`, không bằng `update`.
            ->and($user->can('update', $this->draft))->toBe($expected[0], "vai {$name}, update trên bản nháp");
    }
});

it('refuses contract entry on a restricted matter to everyone but its lead lawyer and the admin', function () {
    expect(canEnterContract($this->accountant, $this->restricted, $this->restrictedDraft))->toBe([false, false, false, false])
        ->and(canEnterContract($this->manager, $this->restricted, $this->restrictedDraft))->toBe([false, false, false, false])
        ->and(canEnterContract($this->lawyer, $this->restricted, $this->restrictedDraft))->toBe([true, true, true, true])
        ->and(canEnterContract($this->admin, $this->restricted, $this->restrictedDraft))->toBe([true, true, true, true]);
});

it('opens contract entry on contract.enter alone, and closes it when that one permission is missing', function () {
    // Cặp chỉ khác đúng `contract.enter`: cả hai thấy tiền của vụ (`matter.viewAny` + `billing.view`).
    $enterer = contractEntryWitness([Permission::MatterViewAny, Permission::BillingView, Permission::ContractEnter]);
    $reader = contractEntryWitness([Permission::MatterViewAny, Permission::BillingView]);

    expect(canEnterContract($enterer, $this->empty, $this->draft))->toBe([true, true, true, true])
        ->and(canEnterContract($reader, $this->empty, $this->draft))->toBe([false, false, false, false])
        ->and(canDecideCommercially($enterer, $this->draft, $this->draftInstalment))->toBe([false, false, false, false]);
});

it('refuses contract entry on a matter whose money the account cannot see, contract.enter or not', function () {
    // Cặp chỉ khác đúng `matter.viewAny`: không có nó thì vụ không nằm trong `listableBy()`.
    $blind = contractEntryWitness([Permission::BillingView, Permission::ContractEnter]);
    $sighted = contractEntryWitness([Permission::MatterViewAny, Permission::BillingView, Permission::ContractEnter]);
    // Cặp chỉ khác đúng `billing.view`.
    $noMoney = contractEntryWitness([Permission::MatterViewAny, Permission::ContractEnter]);

    expect(canEnterContract($blind, $this->empty, $this->draft))->toBe([false, false, false, false])
        ->and(canEnterContract($sighted, $this->empty, $this->draft))->toBe([true, true, true, true])
        ->and(canEnterContract($noMoney, $this->empty, $this->draft))->toBe([false, false, false, false]);
});

it('still refuses to draft without a matter context, contract.enter or not', function () {
    expect($this->accountant->can('create', [Contract::class, $this->empty]))->toBeTrue()
        ->and($this->accountant->can('create', Contract::class))->toBeFalse()
        ->and($this->accountant->can('create', [Contract::class, $this->draftInstalment]))->toBeFalse();
});
