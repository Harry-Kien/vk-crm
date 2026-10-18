<?php

use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Jobs\SendTeamDepartureNotices;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TeamDepartureAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa2, phán quyết 1 — gỡ một thành viên khỏi đội ngũ: hộp thoại liệt kê việc nội bộ còn mở người đó đang giữ trên
 * vụ (và ghế còn chờ nhận), bắt chọn người nhận thay hợp lệ (mặc định luật sư phụ trách nếu hợp lệ) hoặc "để Cần
 * giao lại". Người nhận thay được báo qua "Giao lại" có sẵn (`ReassignTask`, từng việc), người giao được báo, và
 * người bị gỡ nhận một chuông chung "đã rời đội ngũ hồ sơ … — n việc đã chuyển". Mọi đường qua tab "Đội ngũ".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->other = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0505']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->other, MatterRole::Assistant);

    $this->pending = Task::factory()->forMatter($this->matter)->status(TaskStatus::Pending)->create([
        'title' => 'Soạn đơn khởi kiện', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->accepted = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Sao y giấy tờ', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
});

function fa2TeamTab(Matter $matter)
{
    return test()->livewire(TeamRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function fa2DepartureBells(User $user): array
{
    return $user->notifications()->where('type', TeamDepartureAlert::class)->get()->pluck('data')->all();
}

it('asks where the held tasks go, defaults to the lead lawyer, and refuses a removal without a choice', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->mountTableAction('removeMember', $this->assistant)
        ->assertFormFieldExists('task_recipient_id', fn (Select $field): bool => array_key_exists($this->lead->id, $field->getOptions())
            && array_key_exists($this->other->id, $field->getOptions())
            && array_key_exists('leave', $field->getOptions())
            && ! array_key_exists($this->assistant->id, $field->getOptions())
            && ! array_key_exists($this->manager->id, $field->getOptions()))
        ->assertTableActionDataSet(['task_recipient_id' => $this->lead->id])
        ->setTableActionData(['task_recipient_id' => null])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['task_recipient_id' => 'required']);

    expect($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeTrue()
        ->and($this->pending->fresh()->assignee_id)->toBe($this->assistant->id);
});

it('moves the held tasks to the chosen colleague through "Giao lại", and tells the colleague, the assigner and the person removed', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->other->id])
        ->assertHasNoTableActionErrors();

    expect($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeFalse();

    foreach ([$this->pending, $this->accepted] as $task) {
        expect($task->fresh()->assignee_id)->toBe($this->other->id)
            ->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    }

    expect(Activity::query()->where('event', 'task_reassigned')->count())->toBe(2)
        ->and($this->other->notifications()->where('type', TaskAlert::class)->count())->toBe(2);

    $left = fa2DepartureBells($this->assistant);

    expect($left)->toHaveCount(1)
        ->and($left[0]['body'])->toContain('VK-2026-DS-0505')->toContain('2')->toContain('Trợ lý Tùng')
        ->and(json_encode($left[0], JSON_UNESCAPED_UNICODE))->not->toContain('Soạn đơn khởi kiện');

    $assigner = fa2DepartureBells($this->associate);

    expect($assigner)->toHaveCount(1)
        ->and($assigner[0]['body'])->toContain('Trợ lý Lan')->toContain('Trợ lý Tùng')->toContain('2');

    $removed = Activity::query()->where('event', 'team_member_removed')->sole();

    expect($removed->getExtraProperty('tasks_moved'))->toBe(2)
        ->and($removed->getExtraProperty('task_recipient_id'))->toBe($this->other->id);
});

it('leaves the tasks as "Cần giao lại" when asked, and tells the assigner they need reassigning', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => 'leave'])
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh()->assignee_id)->toBe($this->assistant->id)
        ->and(Task::query()->needsReassignment()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->pending->id, $this->accepted->id])->sort()->values()->all());

    $assigner = fa2DepartureBells($this->associate);

    expect($assigner)->toHaveCount(1)
        ->and($assigner[0]['body'])->toContain(__('internal_flow.team_removal.needs_reassignment_marker'));

    expect(fa2DepartureBells($this->assistant)[0]['body'])->toContain('2');
});

it('tells a member removed with no open tasks, without asking anything about tasks', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->other)
        ->assertHasNoTableActionErrors();

    expect($this->matter->team()->whereKey($this->other->id)->exists())->toBeFalse()
        ->and(fa2DepartureBells($this->other))->toHaveCount(1)
        ->and(fa2DepartureBells($this->associate))->toBe([]);
});

it('does not name a restricted matter to the person removed, nor ring an assigner who cannot open it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    $this->actingAs($admin, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => 'leave'])
        ->assertHasNoTableActionErrors();

    $left = fa2DepartureBells($this->assistant);

    expect($left)->toHaveCount(1)
        ->and(json_encode($left[0], JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-DS-0505')
        // Luật sư Minh (người giao) không mở được vụ hạn chế: không chuông nào mang hồ sơ này tới họ.
        ->and(fa2DepartureBells($this->associate))->toBe([]);
});

it('notes in the dialog when the member has not accepted their seat yet', function () {
    $newcomer = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mới']);
    $this->matter->addTeamMember($newcomer, MatterRole::Assistant, $this->lead);

    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->assertTableActionExists('removeMember', fn (Action $action): bool => str_contains((string) $action->getModalDescription(), __('internal_flow.team_removal.pending_seat')), $newcomer)
        ->assertTableActionExists('removeMember', fn (Action $action): bool => ! str_contains((string) $action->getModalDescription(), __('internal_flow.team_removal.pending_seat')), $this->other);
});

it('refuses, in the action itself, to remove a member still holding tasks without a choice', function () {
    expect(fn () => app(RemoveTeamMember::class)->handle($this->matter, $this->manager, $this->assistant))
        ->toThrow(ValidationException::class);

    expect($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeTrue();
});

it('does not send the "tasks handled" bell to an assigner who took the tasks over themselves', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->associate->id])
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh()->assignee_id)->toBe($this->associate->id)
        ->and(fa2DepartureBells($this->associate))->toBe([]);
});

it('does not ring a manager who takes themselves off the team', function () {
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);

    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)->callTableAction('removeMember', $this->manager)->assertHasNoTableActionErrors();

    expect(fa2DepartureBells($this->manager))->toBe([]);
});

it('counts for the assigner only the tasks still waiting when the notice goes out', function () {
    Queue::fake();

    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => 'leave'])
        ->assertHasNoTableActionErrors();

    $job = Queue::pushed(SendTeamDepartureNotices::class)->sole();

    $this->pending->fresh()->forceFill(['assignee_id' => $this->other->id])->save();

    $job->handle(app(ResolveStaffRecipients::class));

    expect(fa2DepartureBells($this->associate))->toHaveCount(1)
        ->and(fa2DepartureBells($this->associate)[0]['body'])->toContain(': 1 việc');
});

// Làn fa2, Task 1, vòng sửa 1 (minor 2): luật sư phụ trách gỡ trợ lý của chính mình — họ có trong ô người nhận thay,
// nhưng không là lựa chọn mặc định khi chính họ bấm (luật "không tự giao việc cho mình" của "Giao lại"); chọn chính
// mình thì một câu rõ ràng, không gỡ gì.
it('offers the lead lawyer themselves when they remove their own assistant, with no default, and explains the task rule if chosen', function () {
    $this->actingAs($this->lead, 'web');

    fa2TeamTab($this->matter)
        ->mountTableAction('removeMember', $this->assistant)
        ->assertFormFieldExists('task_recipient_id', fn (Select $field): bool => array_key_exists($this->lead->id, $field->getOptions())
            && array_key_exists($this->other->id, $field->getOptions()))
        ->assertTableActionDataSet(['task_recipient_id' => null]);

    $component = fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->lead->id])
        ->assertHasTableActionErrors(['task_recipient_id']);

    expect(collect($component->errors()->get('mountedActions.0.data.task_recipient_id'))->implode(' '))
        ->toBe(__('internal_flow.team_removal.self_refused'))
        ->and($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeTrue()
        ->and($this->pending->fresh()->assignee_id)->toBe($this->assistant->id);
});

// integ: khi RemoveTeamMember::reassignHeldTask() truyền notifyFormerHolder: false (fa1), người bị gỡ chỉ còn MỘT
// chuông (TeamDepartureAlert) — đổi kỳ vọng REASSIGNED_AWAY dưới đây thành 0.
it('rings the removed member once for the removal — plus, until fa1 is merged, ReassignTask\'s own per-task bell', function () {
    $this->actingAs($this->manager, 'web');

    fa2TeamTab($this->matter)
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->other->id])
        ->assertHasNoTableActionErrors();

    expect(fa2DepartureBells($this->assistant))->toHaveCount(1)
        ->and($this->assistant->notifications()->where('type', TaskAlert::class)->count())->toBe(2);
});
