<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Policies\IntakePartyPolicy;
use App\Policies\IntakeRequestPolicy;
use App\Support\ConflictOverride;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * M10 Task 1 — {@see IntakeRequestPolicy} và {@see IntakePartyPolicy} theo SPEC §5 (đính chính
 * 2026-09-24, R9).
 *
 * "Nhân chứng được cấp quyền trực tiếp": một người KHÔNG có vai trò nào, chỉ `givePermissionTo`
 * đúng một quyền — chứng minh policy đọc QUYỀN, không đọc VAI (mỗi vế của R9 một nhân chứng, mỗi
 * nhân chứng làm đỏ một mutation probe). Ngoại lệ có chủ đích và có test riêng: "xử lý Đỏ" đọc
 * VAI (`ConflictOverride::allowedFor`), "xoá theo yêu cầu" đọc vai admin.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistantA = User::factory()->withRole(Role::Assistant)->create();
    $this->assistantB = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->clientUser = ClientUser::factory()->create(['client_id' => Client::factory()->create()->id]);

    // Bản ghi do trợ lý A ghi, chưa giao cho ai; và một bản ghi do trợ lý B ghi, giao cho luật sư.
    $this->ofA = IntakeRequest::factory()->create(['created_by' => $this->assistantA->id]);
    $this->ofBAssignedToLawyer = IntakeRequest::factory()->create([
        'created_by' => $this->assistantB->id,
        'assigned_to' => $this->lawyer->id,
    ]);
});

/** Người không có vai trò nào, chỉ được cấp đúng các quyền nêu tên. */
function intakeWitness(string ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('lets everyone but the accountant into the list', function () {
    expect($this->admin->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and($this->manager->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and($this->lawyer->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and($this->assistantA->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and($this->accountant->can('viewAny', IntakeRequest::class))->toBeFalse();
});

it('reads viewAny from the permissions intake.create or intake.viewAny, not from the role', function () {
    expect(intakeWitness(Permission::IntakeCreate->value)->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and(intakeWitness(Permission::IntakeViewAny->value)->can('viewAny', IntakeRequest::class))->toBeTrue()
        ->and(intakeWitness(Permission::IntakeConvert->value)->can('viewAny', IntakeRequest::class))->toBeFalse()
        ->and(intakeWitness(Permission::MatterViewAny->value)->can('viewAny', IntakeRequest::class))->toBeFalse()
        ->and(User::factory()->create()->can('viewAny', IntakeRequest::class))->toBeFalse();
});

it('shows every record to the holders of intake.viewAny and only own or assigned records to the others', function () {
    // admin, manager: mọi bản ghi.
    foreach ([$this->admin, $this->manager] as $reader) {
        expect($reader->can('view', $this->ofA))->toBeTrue()
            ->and($reader->can('view', $this->ofBAssignedToLawyer))->toBeTrue();
    }

    // Trợ lý A chỉ thấy bản ghi MÌNH ghi; trợ lý B không thấy bản ghi của A và ngược lại.
    expect($this->assistantA->can('view', $this->ofA))->toBeTrue()
        ->and($this->assistantA->can('view', $this->ofBAssignedToLawyer))->toBeFalse()
        ->and($this->assistantB->can('view', $this->ofA))->toBeFalse()
        ->and($this->assistantB->can('view', $this->ofBAssignedToLawyer))->toBeTrue();

    // Luật sư thấy bản ghi ĐƯỢC GIAO, không thấy của người khác; luật sư khác không thấy gì.
    expect($this->lawyer->can('view', $this->ofBAssignedToLawyer))->toBeTrue()
        ->and($this->lawyer->can('view', $this->ofA))->toBeFalse()
        ->and($this->otherLawyer->can('view', $this->ofA))->toBeFalse()
        ->and($this->otherLawyer->can('view', $this->ofBAssignedToLawyer))->toBeFalse();
});

it('shows the accountant nothing, not even a record they wrote or were assigned', function () {
    $own = IntakeRequest::factory()->create(['created_by' => $this->accountant->id, 'assigned_to' => $this->accountant->id]);

    expect($this->accountant->can('view', $own))->toBeFalse()
        ->and($this->accountant->can('view', $this->ofA))->toBeFalse()
        ->and($this->accountant->can('update', $own))->toBeFalse();
});

it('reads view and update from the permissions, one witness per limb of R9', function () {
    // Chỉ intake.viewAny (không create): thấy và sửa mọi bản ghi.
    $viewer = intakeWitness(Permission::IntakeViewAny->value);
    expect($viewer->can('view', $this->ofA))->toBeTrue()
        ->and($viewer->can('update', $this->ofA))->toBeTrue();

    // Chỉ intake.create: bản ghi mình ghi hoặc được giao, không hơn.
    $writer = intakeWitness(Permission::IntakeCreate->value);
    $mine = IntakeRequest::factory()->create(['created_by' => $writer->id]);
    $assigned = IntakeRequest::factory()->create(['assigned_to' => $writer->id]);

    expect($writer->can('view', $mine))->toBeTrue()
        ->and($writer->can('update', $mine))->toBeTrue()
        ->and($writer->can('view', $assigned))->toBeTrue()
        ->and($writer->can('update', $assigned))->toBeTrue()
        ->and($writer->can('view', $this->ofA))->toBeFalse()
        ->and($writer->can('update', $this->ofA))->toBeFalse();

    // Người đã ghi bản ghi nhưng KHÔNG còn quyền intake.create thì không thấy nó nữa.
    $revoked = intakeWitness(Permission::IntakeConvert->value);
    $wrote = IntakeRequest::factory()->create(['created_by' => $revoked->id, 'assigned_to' => $revoked->id]);

    expect($revoked->can('view', $wrote))->toBeFalse();
});

it('gates create by intake.create', function () {
    expect($this->admin->can('create', IntakeRequest::class))->toBeTrue()
        ->and($this->manager->can('create', IntakeRequest::class))->toBeTrue()
        ->and($this->lawyer->can('create', IntakeRequest::class))->toBeTrue()
        ->and($this->assistantA->can('create', IntakeRequest::class))->toBeTrue()
        ->and($this->accountant->can('create', IntakeRequest::class))->toBeFalse()
        ->and(intakeWitness(Permission::IntakeCreate->value)->can('create', IntakeRequest::class))->toBeTrue()
        ->and(intakeWitness(Permission::IntakeViewAny->value)->can('create', IntakeRequest::class))->toBeFalse();
});

it('lets admin, manager and lawyers convert, but never the assistant or the accountant', function () {
    expect($this->admin->can('convert', $this->ofA))->toBeTrue()
        ->and($this->manager->can('convert', $this->ofA))->toBeTrue()
        // Luật sư chuyển được bản ghi được giao cho mình...
        ->and($this->lawyer->can('convert', $this->ofBAssignedToLawyer))->toBeTrue()
        // ...không phải bản ghi của người khác mà họ không thấy.
        ->and($this->lawyer->can('convert', $this->ofA))->toBeFalse()
        // Trợ lý có bản ghi của mình nhưng không có intake.convert và không có matter.create.
        ->and($this->assistantA->can('convert', $this->ofA))->toBeFalse()
        ->and($this->accountant->can('convert', $this->ofA))->toBeFalse();
});

it('requires BOTH intake.convert and matter.create to convert, and the record to be visible', function () {
    $both = intakeWitness(Permission::IntakeConvert->value, Permission::MatterCreate->value, Permission::IntakeViewAny->value);
    $onlyConvert = intakeWitness(Permission::IntakeConvert->value, Permission::IntakeViewAny->value);
    $onlyMatterCreate = intakeWitness(Permission::MatterCreate->value, Permission::IntakeViewAny->value);
    $bothButBlind = intakeWitness(Permission::IntakeConvert->value, Permission::MatterCreate->value, Permission::IntakeCreate->value);

    expect($both->can('convert', $this->ofA))->toBeTrue()
        ->and($onlyConvert->can('convert', $this->ofA))->toBeFalse()
        ->and($onlyMatterCreate->can('convert', $this->ofA))->toBeFalse()
        // Đủ hai quyền nhưng không xem được bản ghi của người khác.
        ->and($bothButBlind->can('convert', $this->ofA))->toBeFalse();
});

it('shows the conflict decline reason to intake.viewAny holders only', function () {
    expect($this->admin->can('viewConflictReason', $this->ofA))->toBeTrue()
        ->and($this->manager->can('viewConflictReason', $this->ofA))->toBeTrue()
        // Trợ lý A ghi bản ghi này và xem được nó, nhưng không xem được lý do xung đột (R8).
        ->and($this->assistantA->can('view', $this->ofA))->toBeTrue()
        ->and($this->assistantA->can('viewConflictReason', $this->ofA))->toBeFalse()
        ->and($this->lawyer->can('viewConflictReason', $this->ofBAssignedToLawyer))->toBeFalse()
        ->and($this->accountant->can('viewConflictReason', $this->ofA))->toBeFalse()
        ->and(intakeWitness(Permission::IntakeViewAny->value)->can('viewConflictReason', $this->ofA))->toBeTrue()
        ->and(intakeWitness(Permission::IntakeCreate->value)->can('viewConflictReason', $this->ofA))->toBeFalse();
});

/**
 * Lựa chọn của làn (không có phán quyết): "ai xử lý Đỏ / từ chối vì xung đột" là MỘT định nghĩa cho
 * cả hệ thống — `ConflictOverride::allowedFor()` (theo VAI manager/admin, cùng cổng `OpenMatter`) —
 * cộng thêm xem được bản ghi. Không phải quyền `intake.viewAny`: hôm nay hai luật trùng người nhưng
 * nhân chứng dưới đây (cấp `intake.viewAny` trực tiếp, không vai) cho thấy policy KHÔNG đọc quyền.
 */
it('lets only whoever may override a red conflict resolve one, by role, and only on a record they can see', function () {
    expect($this->admin->can('resolveConflict', $this->ofA))->toBeTrue()
        ->and($this->manager->can('resolveConflict', $this->ofA))->toBeTrue()
        ->and($this->lawyer->can('resolveConflict', $this->ofBAssignedToLawyer))->toBeFalse()
        ->and($this->assistantA->can('resolveConflict', $this->ofA))->toBeFalse()
        ->and($this->accountant->can('resolveConflict', $this->ofA))->toBeFalse()
        ->and(intakeWitness(Permission::IntakeViewAny->value)->can('resolveConflict', $this->ofA))->toBeFalse();

    // Cùng hàm với OpenMatter, không phải một bản sao lệch: đổi một, đổi cả hai.
    foreach ([$this->admin, $this->manager, $this->lawyer, $this->assistantA, $this->accountant] as $user) {
        expect($user->can('resolveConflict', $this->ofA))
            ->toBe(ConflictOverride::allowedFor($user) && $user->can('view', $this->ofA));
    }

    // Nhánh "xem được bản ghi": vai manager luôn có intake.viewAny, nên nhánh này chỉ có ý nghĩa khi
    // quyền bị gỡ khỏi vai — một quản lý có vai nhưng không nhìn thấy bản ghi thì không xử lý được.
    Spatie\Permission\Models\Role::findByName(Role::Manager->value)->revokePermissionTo(Permission::IntakeViewAny->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($this->manager->fresh()->can('view', $this->ofA))->toBeFalse()
        ->and($this->manager->fresh()->can('resolveConflict', $this->ofA))->toBeFalse();
});

it('lets only the admin erase prospect data on request', function () {
    expect($this->admin->can('erase', $this->ofA))->toBeTrue()
        ->and($this->manager->can('erase', $this->ofA))->toBeFalse()
        ->and($this->lawyer->can('erase', $this->ofBAssignedToLawyer))->toBeFalse()
        ->and($this->assistantA->can('erase', $this->ofA))->toBeFalse()
        ->and($this->accountant->can('erase', $this->ofA))->toBeFalse()
        ->and(intakeWitness(...array_map(fn ($p) => $p->value, Permission::cases()))->can('erase', $this->ofA))->toBeFalse();
});

it('offers no delete, restore or force-delete path to anyone, admin included', function () {
    foreach ([$this->admin, $this->manager, $this->lawyer, $this->assistantA] as $user) {
        foreach (['delete', 'restore', 'forceDelete'] as $ability) {
            expect($user->can($ability, $this->ofA))->toBeFalse("{$user->position->value} không được {$ability}");
        }

        foreach (['deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
            expect($user->can($ability, IntakeRequest::class))->toBeFalse("{$user->position->value} không được {$ability}");
        }
    }
});

it('refuses a client user every ability, on every record, without exception', function () {
    foreach (['view', 'update', 'delete', 'restore', 'forceDelete', 'convert', 'viewConflictReason', 'resolveConflict', 'erase'] as $ability) {
        expect($this->clientUser->can($ability, $this->ofA))->toBeFalse("khách không được {$ability}");
    }

    foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
        expect($this->clientUser->can($ability, IntakeRequest::class))->toBeFalse("khách không được {$ability}");
    }
});

it('never returns an intake request or party through the client portal query scope', function () {
    IntakeParty::factory()->for($this->ofA, 'intakeRequest')->create();

    $this->actingAs($this->clientUser, 'client');

    expect(IntakeRequest::query()->count())->toBe(0)
        ->and(IntakeParty::query()->count())->toBe(0);
});

/**
 * Một định nghĩa "thấy được" cho policy, resource, widget và báo cáo: `scopeVisibleTo` (SQL) và
 * `isVisibleTo` (bộ nhớ, policy dùng) phải trả lời giống nhau cho mọi người, mọi bản ghi.
 */
it('gives the SQL scope and the in-memory check the same answer for every user and record', function () {
    $users = [
        $this->admin, $this->manager, $this->lawyer, $this->otherLawyer, $this->assistantA, $this->assistantB,
        $this->accountant,
        intakeWitness(Permission::IntakeViewAny->value),
        intakeWitness(Permission::IntakeCreate->value),
        intakeWitness(Permission::IntakeConvert->value),
        User::factory()->create(),
    ];

    $own = IntakeRequest::factory()->create(['created_by' => $users[8]->id]);
    $assigned = IntakeRequest::factory()->create(['assigned_to' => $users[8]->id]);
    $deniedOwn = IntakeRequest::factory()->create(['created_by' => $users[9]->id, 'assigned_to' => $users[9]->id]);

    $records = IntakeRequest::query()->get();
    expect($records->count())->toBeGreaterThanOrEqual(5);

    foreach ($users as $user) {
        $bySql = IntakeRequest::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
        $inMemory = $records->filter(fn (IntakeRequest $r) => $r->isVisibleTo($user))->pluck('id')->sort()->values()->all();
        $byPolicy = $records->filter(fn (IntakeRequest $r) => $user->can('view', $r))->pluck('id')->sort()->values()->all();

        expect($inMemory)->toBe($bySql)
            ->and($byPolicy)->toBe($bySql);
    }

    expect(IntakeRequest::query()->visibleTo($users[8])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$own->id, $assigned->id])->sort()->values()->all())
        ->and(IntakeRequest::query()->visibleTo($users[9])->count())->toBe(0);
});

it('lets whoever sees the request see its parties, and no one else', function () {
    $party = IntakeParty::factory()->for($this->ofA, 'intakeRequest')->create();

    expect($this->manager->can('view', $party))->toBeTrue()
        ->and($this->assistantA->can('view', $party))->toBeTrue()
        ->and($this->assistantB->can('view', $party))->toBeFalse()
        ->and($this->accountant->can('view', $party))->toBeFalse()
        ->and($this->assistantA->can('update', $party))->toBeTrue()
        ->and($this->assistantB->can('update', $party))->toBeFalse()
        ->and($this->assistantA->can('viewAny', IntakeParty::class))->toBeTrue()
        ->and($this->accountant->can('viewAny', IntakeParty::class))->toBeFalse()
        ->and($this->assistantA->can('create', IntakeParty::class))->toBeTrue()
        ->and($this->accountant->can('create', IntakeParty::class))->toBeFalse()
        ->and($this->clientUser->can('view', $party))->toBeFalse()
        ->and($this->clientUser->can('viewAny', IntakeParty::class))->toBeFalse()
        ->and($this->clientUser->can('create', IntakeParty::class))->toBeFalse()
        ->and($this->clientUser->can('update', $party))->toBeFalse()
        ->and($this->clientUser->can('delete', $party))->toBeFalse();

    // Xoá một bên nhập nhầm theo đúng quyền sửa bản ghi cha.
    expect($this->assistantA->can('delete', $party))->toBeTrue()
        ->and($this->assistantB->can('delete', $party))->toBeFalse();
});
