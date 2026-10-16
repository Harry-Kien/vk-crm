<?php

use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Làn tk, Task 1 — ai THẤY một việc giao nội bộ, và ai làm được gì trên nó.
 *
 * Hai tầng nói cùng một luật bằng hai thứ tiếng: `Task::scopeVisibleTo()` (truy vấn, mọi danh sách)
 * và `TaskPolicy::view()` (thuộc tính, mọi nút/Action). Test ghim hai tầng KHỚP NHAU trên cùng một ma
 * trận sáu vai (thêm Nhân viên kinh doanh khi gộp làn kd) × việc chung / việc của vụ thường / việc của vụ hạn chế, rồi từng khả năng chuyển trạng
 * thái theo vai và trạng thái.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    // Vòng sửa cuối 1 (F1, gộp làn kd): vai thứ sáu, không `matter.*` — như kế toán, chỉ việc chung của mình.
    $this->sales = User::factory()->withRole(Role::Sales)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);

    // Việc của vụ thường: luật sư cộng sự giao cho trợ lý.
    $this->matterTask = Task::factory()->forMatter($this->matter)->create([
        'assigned_by' => $this->associate->id,
        'assignee_id' => $this->assistant->id,
    ]);

    // Việc của vụ hạn chế: chỉ luật sư phụ trách và admin thấy vụ — admin giao cho luật sư phụ trách.
    $this->restrictedTask = Task::factory()->forMatter($this->restricted)->create([
        'assigned_by' => $this->admin->id,
        'assignee_id' => $this->lead->id,
    ]);

    // Việc chung: quản lý giao cho kế toán.
    $this->generalTask = Task::factory()->create([
        'assigned_by' => $this->manager->id,
        'assignee_id' => $this->accountant->id,
    ]);

    // Việc chung: quản lý giao cho nhân viên kinh doanh (vai thứ sáu, gộp làn kd).
    $this->salesTask = Task::factory()->create([
        'assigned_by' => $this->manager->id,
        'assignee_id' => $this->sales->id,
    ]);
});

/** @return array<string, list<string>> người => việc người đó phải thấy */
function tkExpectedVisibility(): array
{
    return [
        'admin' => ['matterTask', 'restrictedTask', 'generalTask', 'salesTask'],
        'manager' => ['matterTask', 'generalTask', 'salesTask'],
        'lead' => ['matterTask', 'restrictedTask'],
        'associate' => ['matterTask'],
        'assistant' => ['matterTask'],
        'otherAssistant' => [],
        'outsider' => [],
        'accountant' => ['generalTask'],
        'sales' => ['salesTask'],
    ];
}

it('shows each person exactly the tasks the rules give them, in lists and in the policy alike', function (string $person) {
    $user = test()->{$person};
    $expected = collect(tkExpectedVisibility()[$person])->map(fn (string $key): int => test()->{$key}->id)->sort()->values()->all();

    $listed = Task::query()->visibleTo($user)->pluck('id')->sort()->values()->all();

    $allowed = collect(['matterTask', 'restrictedTask', 'generalTask', 'salesTask'])
        ->filter(fn (string $key): bool => Gate::forUser($user)->allows('view', test()->{$key}->fresh()))
        ->map(fn (string $key): int => test()->{$key}->id)
        ->sort()->values()->all();

    expect($listed)->toBe($expected)
        ->and($allowed)->toBe($expected);
})->with(array_keys(tkExpectedVisibility()));

it('never shows a task of a restricted matter to a manager, to a team member who is not the lead, or to the accountant', function () {
    $this->restricted->addTeamMember($this->associate, MatterRole::Associate);

    foreach ([$this->manager, $this->associate, $this->accountant, $this->outsider] as $user) {
        expect(Task::query()->visibleTo($user)->whereKey($this->restrictedTask->id)->exists())->toBeFalse()
            ->and(Gate::forUser($user)->allows('view', $this->restrictedTask->fresh()))->toBeFalse();
    }
});

it('lets the lead of the matter see a task in it that someone else assigned to someone else', function () {
    expect(Gate::forUser($this->lead)->allows('view', $this->matterTask->fresh()))->toBeTrue();

    // Bàn giao: người phụ trách mới thấy, người cũ (không còn trong đội ngũ) thì không.
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->team()->detach($this->lead->id);
    $this->matter->update(['lead_lawyer_id' => $newLead->id]);
    $this->matter->addTeamMember($newLead, MatterRole::Lead);

    expect(Gate::forUser($newLead)->allows('view', $this->matterTask->fresh()))->toBeTrue()
        ->and(Task::query()->visibleTo($newLead)->whereKey($this->matterTask->id)->exists())->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('view', $this->matterTask->fresh()))->toBeFalse()
        ->and(Task::query()->visibleTo($this->lead)->whereKey($this->matterTask->id)->exists())->toBeFalse();
});

it('hides the tasks of a cancelled (soft-deleted) matter from everyone, admin included, in both layers', function () {
    $this->matter->delete();

    foreach ([$this->admin, $this->manager, $this->lead, $this->assistant] as $user) {
        expect(Task::query()->visibleTo($user)->whereKey($this->matterTask->id)->exists())->toBeFalse()
            ->and(Gate::forUser($user)->allows('view', $this->matterTask->fresh()))->toBeFalse();
    }
});

it('stops showing a matter task to its assignee once the assignee is taken off the team', function () {
    $this->matter->team()->detach($this->assistant->id);

    expect(Task::query()->visibleTo($this->assistant)->exists())->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('view', $this->matterTask->fresh()))->toBeFalse();
});

it('stops showing a matter task to a lawyer whose position became accountant, in both layers', function () {
    // Kế toán có `matter.viewAny` (vụ thường "liệt kê được") nhưng không `matter.view`: không bao giờ thấy
    // việc của vụ nào — kể cả việc mà chính họ từng được giao khi còn là trợ lý.
    $this->assistant->syncRoles([Role::Accountant->value]);
    $assistant = $this->assistant->fresh();

    expect(Task::query()->visibleTo($assistant)->whereKey($this->matterTask->id)->exists())->toBeFalse()
        ->and(Gate::forUser($assistant)->allows('view', $this->matterTask->fresh()))->toBeFalse();
});

it('does not let someone who can read a matter but not write to it assign work on it, even from the lead seat', function () {
    $reader = User::factory()->create();
    $reader->givePermissionTo(Permission::MatterView->value);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $reader->id]);

    expect(Gate::forUser($reader)->allows('view', $matter))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('assignOnMatter', [Task::class, $matter]))->toBeFalse();
});

it('never lets a portal account read a task, whatever the policy or the query', function () {
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);

    expect(Gate::forUser($clientUser)->allows('view', $this->matterTask->fresh()))->toBeFalse()
        ->and(Gate::forUser($clientUser)->allows('viewAny', Task::class))->toBeFalse();

    $this->actingAs($clientUser, 'client');

    expect(Task::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Khả năng theo vai và trạng thái
// ---------------------------------------------------------------------------------------------

it('lets every staff member who sees matters assign a general task, and not the accountant', function () {
    // Làn fa1 (phán quyết 3, 2026-10-16): trước đó chỉ quản lý/quản trị.
    expect(Gate::forUser($this->admin)->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('create', Task::class))->toBeTrue()
        ->and(Gate::forUser($this->accountant)->allows('create', Task::class))->toBeFalse();
});

it('lets the lead, an associate lawyer, a manager and an admin assign work on a matter, and nobody else', function () {
    $gate = fn (User $user, Matter $matter): bool => Gate::forUser($user)->allows('assignOnMatter', [Task::class, $matter]);

    expect($gate($this->lead, $this->matter))->toBeTrue()
        ->and($gate($this->associate, $this->matter))->toBeTrue()
        ->and($gate($this->manager, $this->matter))->toBeTrue()
        ->and($gate($this->admin, $this->matter))->toBeTrue()
        ->and($gate($this->assistant, $this->matter))->toBeFalse()
        ->and($gate($this->outsider, $this->matter))->toBeFalse()
        ->and($gate($this->accountant, $this->matter))->toBeFalse()
        // Vụ hạn chế: quản lý không xem được vụ.
        ->and($gate($this->lead, $this->restricted))->toBeTrue()
        ->and($gate($this->admin, $this->restricted))->toBeTrue()
        ->and($gate($this->manager, $this->restricted))->toBeFalse();
});

it('does not let an observer or an assistant seated as associate assign work on a matter', function () {
    $observer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($observer, MatterRole::Observer);
    $assistantAsAssociate = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistantAsAssociate, MatterRole::Associate);

    expect(Gate::forUser($observer)->allows('assignOnMatter', [Task::class, $this->matter]))->toBeFalse()
        ->and(Gate::forUser($assistantAsAssociate)->allows('assignOnMatter', [Task::class, $this->matter]))->toBeFalse();
});

it('does not let anyone assign work on a closed or cancelled matter', function () {
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'closed_at' => today()]);
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $cancelled->delete();

    expect(Gate::forUser($this->lead)->allows('assignOnMatter', [Task::class, $closed]))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('assignOnMatter', [Task::class, $cancelled]))->toBeFalse();
});

it('lets only the assignee accept, decline and complete, and only from the right states', function () {
    $task = $this->matterTask->fresh();

    expect(Gate::forUser($this->assistant)->allows('accept', $task))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('decline', $task))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('complete', $task))->toBeTrue()
        ->and(Gate::forUser($this->associate)->allows('accept', $task))->toBeFalse()
        ->and(Gate::forUser($this->lead)->allows('complete', $task))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('decline', $task))->toBeFalse();

    $task->forceFill(['status' => TaskStatus::Accepted])->save();
    expect(Gate::forUser($this->assistant)->allows('accept', $task))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('decline', $task))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('complete', $task))->toBeTrue();

    foreach ([TaskStatus::Declined, TaskStatus::Completed, TaskStatus::Cancelled] as $status) {
        $task->forceFill(['status' => $status])->save();
        expect(Gate::forUser($this->assistant)->allows('accept', $task))->toBeFalse()
            ->and(Gate::forUser($this->assistant)->allows('decline', $task))->toBeFalse()
            ->and(Gate::forUser($this->assistant)->allows('complete', $task))->toBeFalse();
    }
});

it('does not let an assignee taken off the team accept, decline or complete the task', function () {
    $this->matter->team()->detach($this->assistant->id);
    $task = $this->matterTask->fresh();

    expect(Gate::forUser($this->assistant)->allows('accept', $task))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('decline', $task))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('complete', $task))->toBeFalse();
});

it('lets the assigner, the lead of the matter, a manager and an admin edit, reassign and cancel an open task', function () {
    $task = $this->matterTask->fresh();

    foreach (['update', 'reassign', 'cancel'] as $ability) {
        expect(Gate::forUser($this->associate)->allows($ability, $task))->toBeTrue("người giao: {$ability}")
            ->and(Gate::forUser($this->lead)->allows($ability, $task))->toBeTrue("luật sư phụ trách: {$ability}")
            ->and(Gate::forUser($this->manager)->allows($ability, $task))->toBeTrue("quản lý: {$ability}")
            ->and(Gate::forUser($this->admin)->allows($ability, $task))->toBeTrue("admin: {$ability}")
            ->and(Gate::forUser($this->assistant)->allows($ability, $task))->toBeFalse("người nhận: {$ability}")
            ->and(Gate::forUser($this->otherAssistant)->allows($ability, $task))->toBeFalse("đồng nghiệp: {$ability}")
            ->and(Gate::forUser($this->accountant)->allows($ability, $task))->toBeFalse("kế toán: {$ability}");
    }

    $task->forceFill(['status' => TaskStatus::Declined])->save();
    expect(Gate::forUser($this->associate)->allows('reassign', $task))->toBeTrue()
        ->and(Gate::forUser($this->associate)->allows('cancel', $task))->toBeTrue();

    foreach ([TaskStatus::Completed, TaskStatus::Cancelled] as $status) {
        $task->forceFill(['status' => $status])->save();

        foreach (['update', 'reassign', 'cancel'] as $ability) {
            expect(Gate::forUser($this->associate)->allows($ability, $task))->toBeFalse()
                ->and(Gate::forUser($this->admin)->allows($ability, $task))->toBeFalse();
        }
    }
});

it('does not let a manager manage a general task they cannot see, nor the accountant manage the task given to them', function () {
    expect(Gate::forUser($this->manager)->allows('update', $this->generalTask->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->accountant)->allows('update', $this->generalTask->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->accountant)->allows('accept', $this->generalTask->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('update', $this->restrictedTask->fresh()))->toBeFalse();
});

it('never lets anyone delete a task outright', function () {
    expect(Gate::forUser($this->admin)->allows('delete', $this->generalTask->fresh()))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('forceDelete', $this->generalTask->fresh()))->toBeFalse();
});
