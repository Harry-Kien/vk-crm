<?php

use App\Actions\Matter\TransferStaffWork;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa2, phán quyết 2 — nghỉ việc / tạm khoá / xoá / đổi chức danh đếm việc NỘI BỘ còn dở (đang giữ, đã giao còn
 * mở, hồ sơ chờ nhận) như mọi loại việc dở khác, và "Bàn giao hàng loạt" chuyển được việc nội bộ và ghế của người
 * KHÔNG phải luật sư phụ trách (phần "Việc nội bộ và ghế"). Mọi đường qua màn hình (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Sắp Nghỉ']);
    $this->successor = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Nhận']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0606']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function fa2HeldTask(User $holder, ?Matter $matter, TaskStatus $status = TaskStatus::Accepted): Task
{
    return Task::factory()->when($matter !== null, fn ($factory) => $factory->forMatter($matter))->status($status)->create([
        'title' => 'Nộp tạm ứng án phí',
        'assignee_id' => $holder->id,
        'assigned_by' => test()->lead->id,
    ]);
}

it('refuses to deactivate someone still holding internal tasks, naming the count and the way out', function () {
    fa2HeldTask($this->assistant, $this->matter);
    fa2HeldTask($this->assistant, $this->matter, TaskStatus::Pending);

    $this->actingAs($this->admin, 'web');

    $component = $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    expect($component->errors()->first('data.is_active'))
        ->toContain(__('internal_flow.offboarding.held_tasks', ['count' => 2]))
        ->and($this->assistant->fresh()->is_active)->toBeTrue();
});

it('refuses to delete, or move to accountant, someone who assigned open tasks or has a seat waiting for them', function () {
    $lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Giao Việc']);
    $this->matter->addTeamMember($lawyer, MatterRole::Associate);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Pending)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $lawyer->id]);

    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $other->addTeamMember($lawyer, MatterRole::Associate, $this->lead);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->callAction('delete')
        ->assertNotified();

    expect($lawyer->fresh()->trashed())->toBeFalse();

    $component = $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['position' => UserPosition::Accountant->value])
        ->call('save')
        ->assertHasFormErrors(['position']);

    expect($component->errors()->first('data.position'))
        ->toContain(__('internal_flow.offboarding.assigned_tasks', ['count' => 1]))
        ->toContain(__('internal_flow.offboarding.pending_seats', ['count' => 1]));
});

it('names the internal tasks in the "Khoá truy cập ngay" confirmation and logs their counts', function () {
    fa2HeldTask($this->assistant, $this->matter);
    fa2HeldTask($this->assistant, null, TaskStatus::Pending);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->mountAction('suspendAccess')
        ->assertMountedActionModalSee(__('internal_flow.offboarding.suspend_tasks', ['held' => 2, 'assigned' => 0, 'seats' => 0]));

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->callAction('suspendAccess', data: ['reason' => 'Nghỉ việc đột xuất, chưa kịp bàn giao các việc.'])
        ->assertHasNoActionErrors();

    $audit = Activity::query()->where('event', 'staff_access_suspended')->sole();

    expect($audit->properties['open_held_tasks'])->toBe(2)
        ->and($audit->properties['open_assigned_tasks'])->toBe(0)
        ->and($audit->properties['pending_seats'])->toBe(0);
});

it('moves a non-lead\'s tasks, seats and deadlines from the bulk handover screen, after which the deactivation goes through', function () {
    $second = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0607']);
    $second->addTeamMember($this->assistant, MatterRole::Assistant);

    $onFirst = fa2HeldTask($this->assistant, $this->matter);
    $onSecond = fa2HeldTask($this->assistant, $second, TaskStatus::Pending);
    $general = fa2HeldTask($this->assistant, null);
    $deadline = Deadline::factory()->for($second)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false]);

    $this->actingAs($this->admin, 'web');

    $page = $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->assertSet('staff.user_id', $this->assistant->id)
        ->set('staff.recipient_id', $this->successor->id)
        ->call('transferStaffWork')
        ->assertHasNoErrors();

    foreach ([$onFirst, $onSecond, $general] as $task) {
        expect($task->fresh()->assignee_id)->toBe($this->successor->id)
            ->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    }

    expect($deadline->fresh()->responsible_user_id)->toBe($this->successor->id);

    foreach ([$this->matter, $second] as $matter) {
        $seat = MatterUser::query()->where('matter_id', $matter->id)->where('user_id', $this->successor->id)->sole();

        expect($seat->role_in_matter)->toBe(MatterRole::Assistant)
            ->and($seat->assignmentStatus())->toBe(MatterAssignmentStatus::Pending)
            ->and($matter->team()->whereKey($this->assistant->id)->exists())->toBeFalse();
    }

    expect(collect($page->get('staffResults'))->where('success', true)->count())->toBe(3)
        ->and(Activity::query()->where('event', 'staff_work_transferred')->sole()->properties['tasks_moved'])->toBe(3);

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->assistant->fresh()->is_active)->toBeFalse();
});

it('points to the lead handover for a matter the person leads, and moves nothing there', function () {
    $lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();
    $led = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $task = fa2HeldTask($lawyer, $led);
    $heir = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($this->admin, 'web');

    $page = $this->livewire(BulkReassign::class, ['from' => $lawyer->id])
        ->set('staff.recipient_id', $heir->id)
        ->call('transferStaffWork')
        ->assertHasNoErrors();

    $row = collect($page->get('staffResults'))->firstWhere('matterId', $led->id);

    expect($row['success'])->toBeFalse()
        ->and($row['message'])->toBe(__('internal_flow.staff_work.results.is_lead'))
        ->and($task->fresh()->assignee_id)->toBe($lawyer->id);
});

it('offers the bulk screen link when the person only has internal tasks', function () {
    fa2HeldTask($this->assistant, $this->matter);

    $this->actingAs($this->admin, 'web');

    expect(BulkReassign::offboardingLinkAction($this->assistant))->not->toBeNull()
        ->and(BulkReassign::offboardingLinkAction($this->successor))->toBeNull();
});

it('does not count internal tasks left on a matter that has closed or been cancelled — nothing could move them there', function () {
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'closed_at' => now()]);
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    fa2HeldTask($this->assistant, $closed);
    fa2HeldTask($this->assistant, $cancelled);
    $cancelled->delete();
    $this->matter->team()->detach($this->assistant->id);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->assistant->fresh()->is_active)->toBeFalse();
});

it('moves who follows the general tasks a leaving manager assigned, and the tasks they gave the recipient go to the person moving the work', function () {
    $leaving = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Nghỉ']);
    $heir = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Nhận']);
    $general = Task::factory()->status(TaskStatus::Accepted)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $leaving->id]);
    $toHeir = Task::factory()->status(TaskStatus::Pending)->create(['assignee_id' => $heir->id, 'assigned_by' => $leaving->id]);
    $onMatter = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $leaving->id]);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $leaving->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    $this->livewire(BulkReassign::class, ['from' => $leaving->id])
        ->set('staff.recipient_id', $heir->id)
        ->call('transferStaffWork')
        ->assertHasNoErrors();

    expect($general->fresh()->assigned_by)->toBe($heir->id)
        ->and($general->fresh()->assignee_id)->toBe($this->assistant->id)
        ->and($general->fresh()->status)->toBe(TaskStatus::Accepted)
        ->and($toHeir->fresh()->assigned_by)->toBe($this->admin->id)
        ->and($onMatter->fresh()->assigned_by)->not->toBe($leaving->id);

    $changed = Activity::query()->where('event', 'task_assigner_changed')->where('subject_id', $general->id)->sole();

    expect($changed->getExtraProperty('previous_assigner_id'))->toBe($leaving->id)
        ->and($changed->getExtraProperty('assigner_id'))->toBe($heir->id)
        ->and($changed->getExtraProperty('reason'))->toBe('staff_work_transferred');

    $this->livewire(EditUser::class, ['record' => $leaving->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($leaving->fresh()->is_active)->toBeFalse();
});

it('does not count a seat waiting on a matter that has already closed', function () {
    $lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'closed_at' => now()]);
    $closed->addTeamMember($lawyer, MatterRole::Associate, $this->lead);

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $lawyer->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lawyer->fresh()->is_active)->toBeFalse();
});

it('refuses, in the action itself, someone without the bulk handover gate — not even the general tasks move', function () {
    $general = Task::factory()->status(TaskStatus::Accepted)->create(['assignee_id' => $this->successor->id, 'assigned_by' => $this->assistant->id]);

    expect(fn () => app(TransferStaffWork::class)->handle($this->assistant, $this->successor, $this->lead))
        ->toThrow(AuthorizationException::class);

    expect($general->fresh()->assigned_by)->toBe($this->assistant->id)
        ->and(Activity::query()->whereIn('event', ['task_assigner_changed', 'staff_work_transferred'])->count())->toBe(0);
});
