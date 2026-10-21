<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\UpdateTask;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendTaskNotice;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa1 (kiểm tra luồng nội bộ 2026-10-16), phán quyết 1 và phần lời báo của phán quyết 7 — mọi lần bấm đi
 * qua trang "Công việc" (Livewire):
 *
 *  - "Sửa" hạn, ưu tiên, tiêu đề hay mô tả của một việc người khác đang giữ (chờ nhận, đang làm) báo người đó
 *    bằng chuông + thư `staff.task.updated` nêu ĐIỀU đã đổi (hạn cũ → mới, ưu tiên cũ → mới, tiêu đề cũ; mô tả
 *    chỉ "đã sửa", không chép nội dung); nhật ký `task_updated` ghi giá trị cũ và mới. Người sửa là người giữ,
 *    việc đã bị trả lại, hay không đổi gì: không báo. Vụ hạn chế: cùng cổng `forTask`;
 *  - luật sư phụ trách hay quản lý giao lại / huỷ việc NGƯỜI KHÁC giao: người giao gốc nhận một chuông;
 *  - lời báo "đã nhận", "đã hoàn thành" đi qua `forTaskAssigner` (người nhận thay khi người giao đã đi).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FA1-HS-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function fa1Held(TaskStatus $status = TaskStatus::Accepted, array $extra = []): Task
{
    return Task::factory()->forMatter(test()->matter)->status($status)->create([
        'title' => 'Soạn đơn khởi kiện',
        'description' => 'Theo mẫu cũ.',
        'assigned_by' => test()->associate->id,
        'assignee_id' => test()->assistant->id,
        'due_date' => '2026-10-30',
        'priority' => TaskPriority::Normal,
        ...$extra,
    ]);
}

function fa1Edit(User $actor, Task $task, array $data, string $tab = 'all'): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(ListTasks::class, ['activeTab' => $tab])
        ->callTableAction('edit', $task, data: [
            'title' => $task->title,
            'description' => $task->description,
            'due_date' => $task->due_date?->toDateString(),
            'priority' => $task->priority->value,
            ...$data,
        ])
        ->assertHasNoTableActionErrors();
}

/** @return list<FilamentNotification> */
function fa1Bells(User $user, ?string $title = null): array
{
    return $user->notifications()->where('type', TaskAlert::class)->orderBy('created_at')->get()
        ->map(fn ($row) => FilamentNotification::fromDatabase($row))
        ->filter(fn (FilamentNotification $bell): bool => $title === null || $bell->getTitle() === $title)
        ->values()
        ->all();
}

// =============================================================================================
// 1. SỬA VIỆC BÁO NGƯỜI ĐANG GIỮ
// =============================================================================================

/** Mutation probe: bỏ lời gọi `queueNotice(... UPDATED ...)` trong UpdateTask → đỏ (không chuông, không thư). */
it('tells the holder, by one bell and one mail, what the assigner changed: the old and new due date and priority', function () {
    $this->travelTo('2026-10-16 09:00:00');
    $task = fa1Held();

    fa1Edit($this->associate, $task, ['due_date' => '2026-10-17', 'priority' => TaskPriority::Urgent->value]);

    $bells = fa1Bells($this->assistant, __('tasks.notice.updated.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Luật sư Hà')->toContain('Soạn đơn khởi kiện')
        ->toContain('30/10/2026')->toContain('17/10/2026')
        ->toContain(TaskPriority::Normal->label())->toContain(TaskPriority::Urgent->label())
        ->toContain('FA1-HS-01');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->assistant->email)
        && $mail->kind === TaskNotice::KIND_UPDATED);
    Mail::assertSent(TaskNotice::class, 1);

    $mail = collect(Mail::sent(TaskNotice::class))->sole();
    $html = $mail->render();
    expect($html)->toContain('30/10/2026')->toContain('17/10/2026')
        ->and($mail->envelope()->subject)->not->toContain('Soạn đơn khởi kiện')->not->toContain('FA1-HS-01');

    expect(__('tasks.actions.edit_success'))->not->toBe('Đã lưu thay đổi.');
});

/** Mutation probe: ghi `'changed'` như cũ (không `old`/`new`) → đỏ. */
it('records the old and the new value of the due date, priority and title in the log, and only "edited" for the description', function () {
    $this->travelTo('2026-10-16 09:00:00');
    $task = fa1Held();

    fa1Edit($this->associate, $task, [
        'title' => 'Soạn đơn khởi kiện (bản sửa)',
        'due_date' => '2026-10-20',
        'priority' => TaskPriority::High->value,
        'description' => 'Cần 3 bộ, có chứng thực — NỘI-DUNG-MÔ-TẢ-MỚI',
    ]);

    $row = Activity::query()->where('event', 'task_updated')->sole();

    expect($row->properties['old'])->toBe([
        'title' => 'Soạn đơn khởi kiện',
        'due_date' => '2026-10-30',
        'priority' => 'normal',
    ])->and($row->properties['new'])->toBe([
        'title' => 'Soạn đơn khởi kiện (bản sửa)',
        'due_date' => '2026-10-20',
        'priority' => 'high',
    ])->and($row->properties['changed'])->toContain('description')
        ->and(json_encode($row->properties->all(), JSON_UNESCAPED_UNICODE))->not->toContain('NỘI-DUNG-MÔ-TẢ-MỚI');

    $bell = fa1Bells($this->assistant, __('tasks.notice.updated.title'))[0];
    expect($bell->getBody())->toContain(__('tasks.notice.changes.description'))
        ->toContain('Soạn đơn khởi kiện (bản sửa)')
        ->not->toContain('NỘI-DUNG-MÔ-TẢ-MỚI');
});

it('tells the holder of a task still waiting to be accepted as well', function () {
    $this->travelTo('2026-10-16 09:00:00');
    $task = fa1Held(TaskStatus::Pending);

    fa1Edit($this->associate, $task, ['description' => 'Thêm: mang theo bản gốc.']);

    expect(fa1Bells($this->assistant, __('tasks.notice.updated.title')))->toHaveCount(1);
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_UPDATED);
});

/** Mutation probe: bỏ điều kiện "người sửa không phải người giữ" / "việc đang được giữ" → đỏ. */
it('tells nobody when nothing changed, when the task was handed back, or when the editor is the holder', function () {
    $this->travelTo('2026-10-16 09:00:00');

    $same = fa1Held();
    fa1Edit($this->associate, $same, []);

    $returned = fa1Held(TaskStatus::Declined, ['title' => 'Việc đã trả lại']);
    fa1Edit($this->associate, $returned, ['due_date' => '2026-10-21']);

    // Trưởng phòng giao cho luật sư phụ trách; luật sư phụ trách (người quản lý được việc vì phụ trách vụ) tự sửa.
    $own = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của chính luật sư phụ trách',
        'assigned_by' => $this->manager->id,
        'assignee_id' => $this->lead->id,
        'due_date' => '2026-10-30',
    ]);
    fa1Edit($this->lead, $own, ['due_date' => '2026-10-22']);

    expect(fa1Bells($this->assistant))->toBe([])
        ->and(fa1Bells($this->lead))->toBe([]);
    Mail::assertNothingSent();

    expect(Activity::query()->where('event', 'task_updated')->count())->toBe(2);
});

it('never tells a holder who can no longer open a restricted matter, and does not resend on a retried job', function () {
    $this->travelTo('2026-10-16 09:00:00');
    $task = fa1Held();

    // Lần chạy lại của cùng một job (cùng dòng nhật ký) không gửi chuông hay thư lần hai.
    fa1Edit($this->associate, $task, ['due_date' => '2026-10-25']);
    $event = Activity::query()->where('event', 'task_updated')->sole();
    (new SendTaskNotice($task->id, TaskAlert::UPDATED, $this->assistant->id, $event->id))->handle(app(ResolveStaffRecipients::class));
    expect(fa1Bells($this->assistant, __('tasks.notice.updated.title')))->toHaveCount(1);

    // Vụ đổi sang hạn chế: trợ lý không mở được vụ nữa — lần sửa sau không tới họ.
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    fa1Edit($this->lead, $task->fresh(), ['priority' => TaskPriority::Urgent->value]);

    expect(fa1Bells($this->assistant, __('tasks.notice.updated.title')))->toHaveCount(1);
});

/**
 * Lời báo "đã sửa" còn đúng lúc job chạy (`SendTaskNotice::stillRelevant()`): việc đã sang tay người khác trước khi
 * job chạy thì người cũ không được báo sửa một việc không còn của họ. Đo nghiệp vụ của job — gọi thẳng Action.
 *
 * Mutation probe: bỏ nhánh `UPDATED` của `stillRelevant()` → đỏ.
 */
it('does not tell the old holder about an edit when the task was reassigned before the notice went out', function () {
    $this->travelTo('2026-10-16 09:00:00');
    // Trưởng phòng giao cho luật sư phụ trách — người vẫn THẤY việc sau khi nó sang tay người khác (họ phụ trách vụ),
    // nên chỉ lần hỏi "còn đúng không" của job, không phải bộ lọc người nhận, giữ họ khỏi lời báo cũ.
    $task = fa1Held(extra: ['assigned_by' => $this->manager->id, 'assignee_id' => $this->lead->id]);

    DB::transaction(function () use ($task): void {
        app(UpdateTask::class)->handle($task, $this->manager, $task->title, $task->description, '2026-11-20', TaskPriority::High, null);
        app(ReassignTask::class)->handle($task->fresh(), $this->manager, $this->assistant);
    });

    expect(fa1Bells($this->lead, __('tasks.notice.updated.title')))->toBe([]);
    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_UPDATED);
});

// =============================================================================================
// 7. NGƯỜI GIAO GỐC ĐƯỢC BÁO KHI NGƯỜI KHÁC GIAO LẠI / HUỶ VIỆC CỦA HỌ
// =============================================================================================

/** Mutation probe: bỏ lời báo REASSIGNED_BY_OTHER trong ReassignTask → đỏ. */
it('tells the original assigner by bell when the lead reassigns their task, and not when they reassign it themselves', function () {
    $task = fa1Held();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->otherAssistant->id, 'due_date' => '2026-12-01'])
        ->assertHasNoTableActionErrors();

    $bells = fa1Bells($this->associate, __('tasks.notice.reassigned_by_other.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Luật sư Khang')->toContain('Soạn đơn khởi kiện')->toContain('Trợ lý Tùng')->toContain('FA1-HS-01');
    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email));

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $task->fresh(), data: ['assignee_id' => $this->assistant->id, 'due_date' => '2026-12-01'])
        ->assertHasNoTableActionErrors();

    expect(fa1Bells($this->associate, __('tasks.notice.reassigned_by_other.title')))->toHaveCount(1);
});

/** Mutation probe: bỏ lời báo CANCELLED_BY_OTHER trong CancelTask → đỏ. */
it('tells the original assigner by bell, with the reason, when a manager cancels their task', function () {
    $task = fa1Held();

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Khách đã rút yêu cầu'])
        ->assertHasNoTableActionErrors();

    $bells = fa1Bells($this->associate, __('tasks.notice.cancelled_by_other.title'));
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->getBody())->toContain('Quản lý Minh')->toContain('Khách đã rút yêu cầu')->toContain('Soạn đơn khởi kiện');
});

/** Mutation probe: đưa COMPLETED về `forTask` (không người nhận thay) → đỏ. */
it('sends "completed" and "accepted" to the lead on behalf of an assigner who has left the matter', function () {
    $task = fa1Held(TaskStatus::Pending);
    $this->matter->team()->detach($this->associate->id);
    $this->associate->forceFill(['is_active' => false])->save();

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->callTableAction('accept', $task)
        ->assertHasNoTableActionErrors();
    $this->livewire(ListTasks::class, ['activeTab' => 'active'])
        ->callTableAction('complete', $task->fresh(), data: ['note' => 'Đã nộp ở toà'])
        ->assertHasNoTableActionErrors();

    expect(fa1Bells($this->lead, __('tasks.notice.accepted_on_behalf.title')))->toHaveCount(1)
        ->and(fa1Bells($this->lead, __('tasks.notice.completed_on_behalf.title')))->toHaveCount(1);

    $completed = fa1Bells($this->lead, __('tasks.notice.completed_on_behalf.title'))[0];
    expect($completed->getBody())->toContain('Luật sư Hà')->toContain('Trợ lý Lan');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->lead->email)
        && $mail->kind === TaskNotice::KIND_COMPLETED);
});
