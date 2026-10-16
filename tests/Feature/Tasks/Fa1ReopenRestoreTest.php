<?php

use App\Actions\Performance\BuildTaskPerformance;
use App\Actions\Task\ReopenTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Support\Performance\PerformancePeriod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa1, phán quyết 2 — "Mở lại" một việc đã Hoàn thành (người giao, luật sư phụ trách, quản lý — bắt buộc lý
 * do, tức "yêu cầu làm lại"; chính người đã hoàn thành trong 24 giờ đầu — bấm nhầm, lý do tuỳ chọn) và
 * "Khôi phục" một việc đã Huỷ (chính người đã huỷ, luật sư phụ trách, quản lý — bắt buộc lý do). Mọi lần bấm đi
 * qua trang "Công việc" (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo('2026-10-16 09:00:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FA1-RO-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function fa1Done(array $extra = []): Task
{
    return Task::factory()->forMatter(test()->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Nộp hồ sơ ở toà',
        'assigned_by' => test()->associate->id,
        'assignee_id' => test()->assistant->id,
        'due_date' => '2026-10-20',
        'completed_at' => now()->subHours(2),
        'completion_note' => 'Đã nộp, biên nhận số 12',
        ...$extra,
    ]);
}

function fa1Cancelled(User $by, array $extra = []): Task
{
    $task = Task::factory()->forMatter(test()->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Liên hệ nhân chứng',
        'assigned_by' => test()->associate->id,
        'assignee_id' => test()->assistant->id,
        ...$extra,
    ]);

    test()->actingAs($by, 'web');
    test()->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Bấm nhầm'])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);

    return $task->fresh();
}

/** Thông báo từ chối của `ReportsActionFailures` mang đúng câu `$message`. */
function fa1Refusal(string $message): FilamentNotification
{
    return FilamentNotification::make()->title(__('actions.failed_title'))->body($message)->danger()->persistent();
}

function fa1TaskBells(User $user, string $title): array
{
    return $user->notifications()->where('type', TaskAlert::class)->get()
        ->map(fn ($row) => FilamentNotification::fromDatabase($row))
        ->filter(fn (FilamentNotification $bell): bool => $bell->getTitle() === $title)
        ->values()
        ->all();
}

// =============================================================================================
// MỞ LẠI (YÊU CẦU LÀM LẠI)
// =============================================================================================

it('lets the assigner ask for rework: back to "Đang làm" for the same person, reason told by bell and mail, logged', function () {
    $task = fa1Done();

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->assertTableActionVisible('reopen', $task)
        ->callTableAction('reopen', $task, data: ['reason' => 'Thiếu bản sao y CCCD, nộp bổ sung'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.reopen.success'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($fresh->assignee_id)->toBe($this->assistant->id)
        ->and($fresh->completed_at)->toBeNull()
        ->and($fresh->completion_note)->toBeNull()
        ->and($fresh->accepted_at)->not->toBeNull();

    $row = Activity::query()->where('event', 'task_reopened')->sole();
    expect($row->causer_id)->toBe($this->associate->id)
        ->and($row->properties['from'])->toBe('completed')
        ->and($row->properties['to'])->toBe('accepted')
        ->and($row->properties['reason'])->toBe('Thiếu bản sao y CCCD, nộp bổ sung')
        ->and($row->properties['completion_note'])->toBe('Đã nộp, biên nhận số 12')
        ->and($row->properties['matter_id'])->toBe($this->matter->id);

    $bells = fa1TaskBells($this->assistant, __('tasks.notice.reopened.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Thiếu bản sao y CCCD')->toContain('Luật sư Hà')->toContain('Nộp hồ sơ ở toà');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->assistant->email)
        && $mail->kind === TaskNotice::KIND_REOPENED);
});

/** Mutation probe: bỏ `->required()` khỏi ô lý do của màn hình → đỏ. */
it('requires a reason from anyone but the person who completed it', function () {
    $task = fa1Done();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
});

/**
 * Nửa gác cổng thật của ô `required()` (một payload Livewire dàn dựng bỏ qua được form): chính Action từ chối lý do
 * rỗng của người không phải người đã hoàn thành. Luật nghiệp vụ, không phải hành vi màn hình — gọi thẳng Action.
 *
 * Mutation probe: bỏ vế "lý do rỗng mà không phải người hoàn thành" trong ReopenTask → đỏ.
 */
it('refuses, in the action itself, an empty reason from the assigner', function () {
    $task = fa1Done();

    expect(fn () => app(ReopenTask::class)->handle($task, $this->associate, '   '))
        ->toThrow(ValidationException::class);

    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
});

it('lets the lead and a manager reopen, but not a colleague who only sees the matter', function () {
    $task = fa1Done();

    foreach ([$this->lead, $this->manager] as $who) {
        $this->actingAs($who, 'web');
        $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertTableActionVisible('reopen', $task);
    }

    // Trợ lý khác cùng vụ không thấy việc này; luật sư cộng sự khác giao việc khác cũng không mở lại được việc này.
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($other, MatterRole::Associate);
    $this->actingAs($other, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertCanNotSeeTableRecords([$task]);
});

/** Mutation probe: đổi cửa sổ 24 giờ thành không giới hạn → đỏ (nút còn hiện ở giờ thứ 25). */
it('lets the person who completed it undo a mistaken click within 24 hours, without a reason, and tells the assigner', function () {
    $task = fa1Done(['completed_at' => now()->subHours(23)]);

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task, data: ['reason' => ''])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted);
    expect(fa1TaskBells($this->associate, __('tasks.notice.reopened_by_holder.title')))->toHaveCount(1)
        ->and(fa1TaskBells($this->assistant, __('tasks.notice.reopened.title')))->toBe([]);

    $late = fa1Done(['title' => 'Việc xong hôm kia', 'completed_at' => now()->subHours(25)]);
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertTableActionHidden('reopen', $late);
});

/** Mutation probe: bỏ kiểm tra "vụ đã kết thúc" trong ReopenTask → đỏ (việc mở lại trên vụ đã đóng). */
it('refuses to reopen a task of a closed matter, with a plain sentence, even from a screen opened before it closed', function () {
    $task = fa1Done();

    $this->actingAs($this->associate, 'web');
    $page = $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountAction(TestAction::make('reopen')->table($task));

    $this->matter->forceFill(['closed_at' => now()])->save();

    $page->setActionData(['reason' => 'Làm lại'])
        ->callMountedAction()
        ->assertNotified(fa1Refusal(__('tasks.reopen.errors.matter_closed')));

    expect($task->fresh()->status)->toBe(TaskStatus::Completed);

    // Mở trang sau khi vụ kết thúc: nút không mở hộp thoại nào, bấm là nghe ngay câu từ chối.
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task->fresh())
        ->assertNotified(fa1Refusal(__('tasks.reopen.errors.matter_closed')));

    expect($task->fresh()->status)->toBe(TaskStatus::Completed);
});

it('no longer counts a reopened task as completed on time, until it is completed again', function () {
    $this->travelTo('2026-10-31 09:00:00');
    $task = fa1Done(['completed_at' => '2026-10-19 10:00:00', 'due_date' => '2026-10-20']);
    $period = PerformancePeriod::fromFilters(['period' => PerformancePeriod::THIS_MONTH]);
    $ratio = fn () => app(BuildTaskPerformance::class)->handle($this->manager, collect([$this->assistant]), $period)['rows'][$this->assistant->id]['taskOnTime'];

    expect($ratio()->numerator)->toBe(1);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reopen', $task, data: ['reason' => 'Thiếu chữ ký'])
        ->assertHasNoTableActionErrors();

    // Đang mở lại và đã qua hạn: không còn là "xong đúng hạn", và tính là một việc quá hạn còn mở (phán quyết 6).
    expect($ratio()->numerator)->toBe(0)->and($ratio()->denominator)->toBe(1);
});

// =============================================================================================
// KHÔI PHỤC VIỆC ĐÃ HUỶ
// =============================================================================================

it('lets the person who cancelled restore the task to "Chờ nhận" for the same, still eligible person, who is told', function () {
    $task = fa1Cancelled($this->associate);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->assertTableActionVisible('restore', $task)
        ->callTableAction('restore', $task, data: ['reason' => 'Huỷ nhầm, khách vẫn cần'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.restore.success'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Pending)
        ->and($fresh->assignee_id)->toBe($this->assistant->id)
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->cancel_reason)->toBeNull()
        ->and($fresh->accepted_at)->toBeNull();

    $row = Activity::query()->where('event', 'task_restored')->sole();
    expect($row->properties['reason'])->toBe('Huỷ nhầm, khách vẫn cần')
        ->and($row->properties['from'])->toBe('cancelled')
        ->and($row->properties['to'])->toBe('pending');

    expect(fa1TaskBells($this->assistant, __('tasks.notice.restored.title')))->toHaveCount(1);
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->assistant->email)
        && $mail->kind === TaskNotice::KIND_RESTORED);
});

/** Mutation probe: cho người giao (không phải người huỷ) khôi phục → đỏ. */
it('lets the lead and a manager restore, but not the assigner when someone else cancelled, and requires a reason', function () {
    $task = fa1Cancelled($this->manager);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertTableActionHidden('restore', $task);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->assertTableActionVisible('restore', $task)
        ->callTableAction('restore', $task, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertTableActionVisible('restore', $task);
});

/** Mutation probe: bỏ `TaskAssignees::qualifies` (luôn báo người cũ) → đỏ. */
it('restores the task of someone who has since become an observer as "Cần giao lại", telling them nothing', function () {
    $task = fa1Cancelled($this->associate);
    // Người theo dõi vẫn xem được vụ (nên bộ lọc người nhận của job KHÔNG tự chặn được) nhưng không giữ được việc.
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task, data: ['reason' => 'Vẫn cần làm'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.restore.success_needs_reassignment'));

    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(Task::query()->whereKey($task->id)->needsReassignment()->exists())->toBeTrue()
        ->and(fa1TaskBells($this->assistant, __('tasks.notice.restored.title')))->toBe([]);
});

it('refuses to restore a task of a closed matter with a plain sentence', function () {
    $task = fa1Cancelled($this->associate);

    $this->actingAs($this->associate, 'web');
    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->mountAction(TestAction::make('restore')->table($task));

    $this->matter->forceFill(['closed_at' => now()])->save();

    $page->setActionData(['reason' => 'Vẫn cần làm'])
        ->callMountedAction()
        ->assertNotified(fa1Refusal(__('tasks.restore.errors.matter_closed')));

    // Mở trang sau khi vụ kết thúc: không hộp thoại, bấm là nghe ngay câu từ chối (không phải "hãy ghi lý do").
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh())
        ->assertNotified(fa1Refusal(__('tasks.restore.errors.matter_closed')));

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});
