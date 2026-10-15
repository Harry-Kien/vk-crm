<?php

use App\Actions\Task\TaskAssignees;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Làn tk, Task 1 — tab "Công việc" của một vụ: ai thấy tab, ai thấy dòng nào, ai giao được, và giao việc
 * trong một khung ngắn (chọn người trong đội ngũ, gõ tiêu đề).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);

    $this->byAssociate = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc của Hà giao Lan',
        'assigned_by' => $this->associate->id,
        'assignee_id' => $this->assistant->id,
    ]);
    $this->byLead = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc của Khang giao Tùng',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->otherAssistant->id,
    ]);
});

function tkMatterTab(Matter $matter)
{
    return test()->livewire(TasksRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

it('sits on the matter page right after the deadlines tab', function () {
    $relations = MatterResource::getRelations();

    expect(array_search(TasksRelationManager::class, $relations, true))
        ->toBe(array_search(DeadlinesRelationManager::class, $relations, true) + 1);
});

it('shows the tab to whoever can open the matter, and not to the accountant', function () {
    expect(TasksRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();

    $this->actingAs($this->assistant, 'web');
    expect(TasksRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();

    $this->actingAs($this->accountant, 'web');
    expect(TasksRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();
});

it('shows the lead every task of the matter, but an assistant only their own', function () {
    $this->actingAs($this->lead, 'web');
    tkMatterTab($this->matter)->assertCanSeeTableRecords([$this->byAssociate, $this->byLead]);

    $this->actingAs($this->assistant, 'web');
    tkMatterTab($this->matter)
        ->assertCanSeeTableRecords([$this->byAssociate])
        ->assertCanNotSeeTableRecords([$this->byLead]);

    $this->actingAs($this->associate, 'web');
    tkMatterTab($this->matter)
        ->assertCanSeeTableRecords([$this->byAssociate])
        ->assertCanNotSeeTableRecords([$this->byLead]);
});

it('assigns a task to a team member from a short form, and tells the assignee', function () {
    $this->actingAs($this->associate, 'web');

    tkMatterTab($this->matter)
        ->assertTableActionVisible('assign')
        ->callTableAction('assign', data: [
            'assignee_id' => $this->otherAssistant->id,
            'title' => 'Sao y hợp đồng mua bán',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.actions.assign_success'));

    $task = Task::query()->where('title', 'Sao y hợp đồng mua bán')->sole();

    expect($task->matter_id)->toBe($this->matter->id)
        ->and($task->assignee_id)->toBe($this->otherAssistant->id)
        ->and($task->priority)->toBe(TaskPriority::Normal)
        ->and($task->due_date)->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Pending);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->otherAssistant->email));
});

it('refuses, at the form, an assignee outside the team, and requires a title', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($this->lead, 'web');

    tkMatterTab($this->matter)
        ->callTableAction('assign', data: ['assignee_id' => $outsider->id, 'title' => 'Việc'])
        ->assertHasTableActionErrors(['assignee_id']);

    tkMatterTab($this->matter)
        ->callTableAction('assign', data: ['assignee_id' => $this->assistant->id, 'title' => ''])
        ->assertHasTableActionErrors(['title' => 'required']);

    tkMatterTab($this->matter)
        ->callTableAction('assign', data: [
            'assignee_id' => $this->assistant->id,
            'title' => 'Việc',
            'description' => str_repeat('a', Task::DESCRIPTION_MAX_LENGTH + 1),
        ])
        ->assertHasTableActionErrors(['description' => 'max']);

    expect(Task::query()->count())->toBe(2);
});

it('offers exactly the working team members who can open the matter, never the person assigning', function () {
    $observer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($observer, MatterRole::Observer);

    $options = app(TaskAssignees::class)->options($this->matter, $this->associate);

    expect(array_keys($options))->toEqualCanonicalizing([$this->lead->id, $this->assistant->id, $this->otherAssistant->id]);
});

it('hides the assign button from an assistant and on a closed matter', function () {
    $this->actingAs($this->assistant, 'web');
    tkMatterTab($this->matter)->assertTableActionHidden('assign');

    $this->matter->forceFill(['closed_at' => today()])->save();
    $this->actingAs($this->lead, 'web');
    tkMatterTab($this->matter->fresh())->assertTableActionHidden('assign');
});

it('lets the lead assign work on a restricted matter, and shows its tasks to nobody but the lead and an admin', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $restricted->addTeamMember($admin, MatterRole::Associate);

    $this->actingAs($this->lead, 'web');
    tkMatterTab($restricted)
        ->callTableAction('assign', data: ['assignee_id' => $admin->id, 'title' => 'Rà soát chứng từ mật'])
        ->assertHasNoTableActionErrors();

    $task = Task::query()->where('title', 'Rà soát chứng từ mật')->sole();

    $this->actingAs($admin, 'web');
    tkMatterTab($restricted)->assertCanSeeTableRecords([$task]);

    $this->actingAs($this->manager, 'web');
    expect(TasksRelationManager::canViewForRecord($restricted, ViewMatter::class))->toBeFalse();
});

it('lets the assignee accept and the lead reassign right from the tab', function () {
    $this->actingAs($this->assistant, 'web');
    tkMatterTab($this->matter)
        ->callTableAction('accept', $this->byAssociate)
        ->assertHasNoTableActionErrors();

    expect($this->byAssociate->fresh()->status)->toBe(TaskStatus::Accepted);

    $this->actingAs($this->lead, 'web');
    tkMatterTab($this->matter)
        ->callTableAction('reassign', $this->byAssociate, data: ['assignee_id' => $this->otherAssistant->id])
        ->assertHasNoTableActionErrors();

    expect($this->byAssociate->fresh())
        ->assignee_id->toBe($this->otherAssistant->id)
        ->status->toBe(TaskStatus::Pending);
});
