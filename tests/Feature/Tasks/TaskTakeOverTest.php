<?php

use App\Actions\Task\ReassignTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendTaskNotice;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Gộp integ-fa (làn fa1 + fa2), mục A — ba cờ mới của `ReassignTask` cho người gọi hàng loạt (`notifyNewHolder`,
 * `notifyAssigner`) và lần NHẬN THAY (`takeOver`) chỉ dành cho đường gỡ thành viên / chuyển việc nội bộ: người bấm tự
 * nhận việc thì việc sang "Đang làm" của họ, không tự báo mình; "Giao việc"/"Giao lại" thường vẫn cấm tự giao.
 * Việc mà người nhận thay chính là người giao trở thành việc của riêng họ: không ở "Tôi đã giao", không "Trả lại"
 * được, và lời báo cho người giao về nó tới luật sư phụ trách thay vì chính họ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Ho_Chi_Minh'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->other = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-TO-0001']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->other, MatterRole::Assistant);
});

function tkoTask(TaskStatus $status = TaskStatus::Accepted, array $extra = []): Task
{
    return Task::factory()->forMatter(test()->matter)->status($status)->create([
        'title' => 'Soạn đơn khởi kiện',
        'assignee_id' => test()->assistant->id,
        'assigned_by' => test()->associate->id,
        ...$extra,
    ]);
}

function tkoBells(User $user): array
{
    return $user->notifications()->where('type', TaskAlert::class)->get()->pluck('data')->all();
}

it('skips only the "được giao" bell and mail of the new holder when notifyNewHolder is false', function () {
    $task = tkoTask();

    app(ReassignTask::class)->handle($task, $this->manager, $this->other, notifyNewHolder: false);

    expect($task->fresh()->assignee_id)->toBe($this->other->id)
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(tkoBells($this->other))->toBe([])
        // Người cũ và người giao gốc vẫn được báo như thường.
        ->and(tkoBells($this->assistant))->toHaveCount(1)
        ->and(tkoBells($this->associate))->toHaveCount(1);

    Mail::assertNotSent(TaskNotice::class);
});

it('skips only the bell to the original assigner when notifyAssigner is false', function () {
    $task = tkoTask();

    app(ReassignTask::class)->handle($task, $this->manager, $this->other, notifyAssigner: false);

    expect(tkoBells($this->associate))->toBe([])
        ->and(tkoBells($this->other))->toHaveCount(1)
        ->and(tkoBells($this->assistant))->toHaveCount(1);
});

it('lets the actor take a task over on a handover path: "Đang làm" at once, logged as taken over, no bell to oneself', function () {
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);
    $task = tkoTask(TaskStatus::Pending);

    $this->travel(2)->hours();
    app(ReassignTask::class)->handle($task, $this->manager, $this->manager, takeOver: true);

    $fresh = $task->fresh();

    expect($fresh->assignee_id)->toBe($this->manager->id)
        ->and($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($fresh->accepted_at?->equalTo(now()))->toBeTrue()
        ->and($fresh->assigned_at?->equalTo(now()))->toBeTrue()
        ->and($fresh->assigned_by)->toBe($this->associate->id)
        ->and(tkoBells($this->manager))->toBe([])
        // Người vừa mất việc và người giao gốc vẫn được báo (cờ mặc định).
        ->and(tkoBells($this->assistant))->toHaveCount(1)
        ->and(tkoBells($this->associate))->toHaveCount(1);

    $log = Activity::query()->where('event', 'task_reassigned')->sole();

    expect($log->getExtraProperty('taken_over'))->toBeTrue()
        ->and($log->getExtraProperty('to'))->toBe(TaskStatus::Accepted->value)
        ->and($log->getExtraProperty('assignee_id'))->toBe($this->manager->id);

    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->manager->email));
});

it('does not even queue a "được giao" notice to the person taking a task over', function () {
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);
    $task = tkoTask(TaskStatus::Pending);

    Queue::fake();

    app(ReassignTask::class)->handle($task, $this->manager, $this->manager, takeOver: true);

    Queue::assertNotPushed(SendTaskNotice::class, fn (SendTaskNotice $job): bool => $job->kind === TaskAlert::ASSIGNED);
    Queue::assertPushed(SendTaskNotice::class, fn (SendTaskNotice $job): bool => $job->kind === TaskAlert::REASSIGNED_AWAY);
});

it('still requires a working seat to take a task over', function () {
    $task = tkoTask();

    expect(fn () => app(ReassignTask::class)->handle($task, $this->manager, $this->manager, takeOver: true))
        ->toThrow(ValidationException::class, __('tasks.validation.assignee_ineligible'));

    expect($task->fresh()->assignee_id)->toBe($this->assistant->id);
});

it('keeps the self-assign ban without takeOver, and takeOver changes nothing when someone else receives the task', function () {
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);
    $task = tkoTask();

    expect(fn () => app(ReassignTask::class)->handle($task, $this->manager, $this->manager))
        ->toThrow(ValidationException::class, __('tasks.validation.self_assign'));

    app(ReassignTask::class)->handle($task, $this->manager, $this->other, takeOver: true);

    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(Activity::query()->where('event', 'task_reassigned')->sole()->getExtraProperty('taken_over'))->toBeNull()
        ->and(tkoBells($this->other))->toHaveCount(1);
});

it('keeps "Giao lại" on the task page from offering the actor themselves', function () {
    $task = tkoTask();

    $this->actingAs($this->associate, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountTableAction('reassign', $task)
        ->assertFormFieldExists('assignee_id', fn ($field): bool => ! array_key_exists($this->associate->id, $field->getOptions())
            && array_key_exists($this->other->id, $field->getOptions()));
});

it('makes a task the taker assigned themselves their own task: not under "Tôi đã giao", no "Trả lại", follow-ups go to the lead lawyer', function () {
    $task = tkoTask(TaskStatus::Pending);

    app(ReassignTask::class)->handle($task, $this->associate, $this->associate, takeOver: true);

    $fresh = $task->fresh();

    expect($fresh->assigned_by)->toBe($this->associate->id)
        ->and($fresh->assignee_id)->toBe($this->associate->id)
        ->and(Task::query()->assignedBy($this->associate)->pluck('id')->all())->not->toContain($task->id)
        ->and(Task::query()->inProgressFor($this->associate)->pluck('id')->all())->toContain($task->id)
        ->and(Gate::forUser($this->associate)->allows('decline', $fresh))->toBeFalse()
        ->and(Gate::forUser($this->associate)->allows('complete', $fresh))->toBeTrue();

    $this->actingAs($this->associate, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])->assertCanNotSeeTableRecords([$fresh]);

    $this->livewire(ListTasks::class, ['activeTab' => 'active'])
        ->assertTableActionHidden('decline', $fresh)
        ->callTableAction('complete', $fresh, data: ['note' => 'Đã nộp'])
        ->assertHasNoTableActionErrors();

    $titles = fn (User $user): array => $user->notifications()->where('type', TaskAlert::class)->get()
        ->map(fn ($row) => FilamentNotification::fromDatabase($row)->getTitle())->all();

    // Không ai nhận lời "đã hoàn thành việc anh/chị giao" về việc mình tự làm; luật sư phụ trách nhận thay.
    expect($titles($this->associate))->toBe([])
        ->and($titles($this->lead))->toBe([__('tasks.notice.completed_on_behalf.title')]);
});
