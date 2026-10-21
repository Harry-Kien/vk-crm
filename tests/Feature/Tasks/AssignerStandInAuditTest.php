<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\AssignTask;
use App\Actions\Task\DeclineTask;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Mail\Staff\TaskNotice;
use App\Mail\Staff\TaskReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TaskReminderAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * Kiểm toán luồng Công việc 2026-10-21 — người nhận THAY người giao:
 *
 *  - N1: lời báo "cho người giao" rơi tới người quản lý hồ sơ (trưởng phòng) nói "hồ sơ :code của đội ngũ anh/chị quản
 *    lý", không "hồ sơ anh/chị phụ trách"; "bị trả lại" tới CÙNG tập người với lời nhắc người giao — mọi người của
 *    `forTaskAssigner()` (trước: chỉ trưởng phòng đầu tiên, `->first()`).
 *  - A7: việc CHUNG mà người giao không còn hợp lệ và lời báo cần người quyết định — rơi tới trưởng phòng đang hoạt động
 *    (không có thì quản trị viên), không vào khoảng không.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    asiTravel('2026-10-12 09:00');

    $this->managerA = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng An']);
    $this->managerB = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Bình']);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cộng Sự Cũ']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'ASI-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function asiTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function asiMails(string $template, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', $template)->where('recipient', $to->email)->where('status', OutboundStatus::Sent)->count();
}

function asiBell(User $user, string $type): ?array
{
    return $user->notifications()->where('type', $type)->latest('created_at')->first()?->data;
}

// =============================================================================================
// N1 — lời báo cho người giao tới người quản lý hồ sơ
// =============================================================================================

/** Mutation probe: `SendTaskNotice` lấy `->first()` của `forTaskAssigner()` như trước → trưởng phòng thứ hai không nhận → đỏ. */
it('tells every manager of the matter, in a manager\'s words, that a task was returned when neither the assigner nor the lead can take it', function () {
    // Luật sư cộng sự giao cho luật sư phụ trách rồi bị khoá; luật sư phụ trách trả lại.
    $task = app(AssignTask::class)->handle($this->associate, $this->matter, $this->lead, 'Soạn bản luận cứ');
    $this->associate->forceFill(['is_active' => false])->save();

    app(DeclineTask::class)->handle($task, $this->lead, 'Trùng lịch phiên toà khác');

    foreach ([$this->managerA, $this->managerB] as $manager) {
        $bell = asiBell($manager, TaskAlert::class);

        expect($bell)->not->toBeNull()
            ->and($bell['title'])->toBe(__('tasks.managed.alert.declined.title', ['code' => 'ASI-01']))
            ->and($bell['title'])->not->toContain('anh/chị phụ trách')
            ->and($bell['body'])->toContain('Luật sư Cộng Sự Cũ')->not->toContain('anh/chị phụ trách')
            ->and($bell['actions'][0]['url'])->toBe(TaskColumns::matterTabUrl($this->matter->id))
            ->and(asiMails('staff.task.declined', $manager))->toBe(1);
    }

    // Không ai khác: không quản trị viên (vụ thường), không chính luật sư phụ trách vừa trả lại.
    expect(asiBell($this->admin, TaskAlert::class))->toBeNull()
        ->and(asiMails('staff.task.declined', $this->lead))->toBe(0);
});

/** Mutation probe: thư "bị trả lại" cho trưởng phòng dùng bộ chữ `_on_behalf` → tiêu đề "anh/chị phụ trách" → đỏ. */
it('words the returned-task mail to a manager for the team they manage', function () {
    Mail::fake();
    $task = app(AssignTask::class)->handle($this->associate, $this->matter, $this->lead, 'Soạn bản luận cứ');
    $this->associate->forceFill(['is_active' => false])->save();

    app(DeclineTask::class)->handle($task, $this->lead, 'Trùng lịch phiên toà khác');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->managerA->email)
        && $mail->envelope()->subject === __('tasks.managed.email.subject.declined', ['name' => 'Luật sư Khang'])
        && str_contains($mail->render(), 'ASI-01')
        && ! str_contains($mail->render(), 'anh/chị phụ trách'));
});

/** Mutation probe: lời nhắc người giao cho trưởng phòng dùng bộ chữ `_on_behalf` → đỏ. */
it('words the overdue note to managers standing in for the team they manage, and keeps the lead\'s own words for the lead', function () {
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp bản khai', 'assignee_id' => $this->lead->id, 'assigned_by' => $this->associate->id,
        'assigned_at' => now()->subWeek(), 'due_date' => '2026-10-09',
    ]);
    $byAssistant = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Sao y giấy tờ', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
        'assigned_at' => now()->subWeek(), 'due_date' => '2026-10-09',
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    app(RemindPendingWork::class)->handle();

    $bell = asiBell($this->managerA, TaskReminderAlert::class);
    expect($bell['title'])->toBe(__('tasks.managed.reminder.alert.overdue_assigner.title', ['code' => 'ASI-01']))
        ->and($bell['body'])->not->toContain('anh/chị phụ trách')
        ->and(asiMails('staff.task.reminder.overdue_assigner', $this->managerB))->toBe(1);

    // Luật sư phụ trách nhận thay cho việc của trợ lý — câu cũ "hồ sơ anh/chị phụ trách" là đúng với họ.
    $leadBell = asiBell($this->lead, TaskReminderAlert::class);
    expect($leadBell['viewData']['task_id'] ?? null)->not->toBeNull();
    expect(collect($this->lead->notifications()->where('type', TaskReminderAlert::class)->get())
        ->first(fn ($n) => $n->data['viewData']['task_id'] === $byAssistant->id)->data['title'])
        ->toBe(__('tasks.reminder.alert.overdue_assigner_on_behalf.title'));
});

// =============================================================================================
// A7 — việc chung có người giao không còn hợp lệ
// =============================================================================================

/** Mutation probe: `forTaskAssigner()` trả rỗng cho việc chung như trước → đỏ. */
it('sends a returned general task whose assigner was suspended to the active managers', function () {
    $task = app(AssignTask::class)->handle($this->associate, null, $this->assistant, 'Đối chiếu công văn đến');
    $this->associate->forceFill(['is_active' => false])->save();

    app(DeclineTask::class)->handle($task, $this->assistant, 'Không thuộc việc của trợ lý');

    foreach ([$this->managerA, $this->managerB] as $manager) {
        $bell = asiBell($manager, TaskAlert::class);

        expect($bell['title'])->toBe(__('tasks.managed.alert.declined_general.title'))
            ->and($bell['body'])->toContain('Luật sư Cộng Sự Cũ')->toContain('Đối chiếu công văn đến')
            ->and($bell['actions'][0]['url'])->toBe(TaskResource::getUrl('index', [
                'tab' => 'all', 'filters' => ['assigned_by' => ['value' => $this->associate->id]],
            ], panel: 'admin'))
            ->and(asiMails('staff.task.declined', $manager))->toBe(1);
    }

    expect(asiBell($this->admin, TaskAlert::class))->toBeNull();
});

it('falls back to the administrators for a general task when no manager is active', function () {
    $task = Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Đối chiếu công văn đến', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->forceFill(['is_active' => false])->save();
    $this->managerA->forceFill(['is_active' => false])->save();
    $this->managerB->forceFill(['is_active' => false])->save();

    expect(app(ResolveStaffRecipients::class)->forTaskAssigner($task->fresh())->pluck('id')->all())->toBe([$this->admin->id]);
});

/** Phép thử ngược: tin (không cần quyết định) và người giao còn hợp lệ không đổi đường. */
it('keeps general-task news and valid assigners where they were', function () {
    $task = Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Đối chiếu công văn đến', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $resolver = app(ResolveStaffRecipients::class);

    expect($resolver->forTaskAssigner($task)->pluck('id')->all())->toBe([$this->associate->id]);

    $this->associate->forceFill(['is_active' => false])->save();

    expect($resolver->forTaskAssigner($task->fresh(), decisionNeeded: false)->all())->toBe([]);
});

/** Việc chung RIÊNG (người giao = người giữ) của người còn làm được: không ai khác bị báo (như việc riêng của hồ sơ). */
it('keeps an own general task of a working holder off the managers', function () {
    $task = Task::factory()->status(TaskStatus::Pending)->create([
        'title' => 'Việc tự giao', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->assistant->id,
    ]);

    expect(app(ResolveStaffRecipients::class)->forTaskAssigner($task)->all())->toBe([]);

    $this->assistant->forceFill(['is_active' => false])->save();

    expect(app(ResolveStaffRecipients::class)->forTaskAssigner($task->fresh())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->managerA->id, $this->managerB->id])->sort()->values()->all());
});

it('reminds the managers that a general task whose assigner is gone is overdue, in a manager\'s words', function () {
    Mail::fake();
    Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Đối chiếu công văn đến', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
        'assigned_at' => now()->subWeek(), 'due_date' => '2026-10-09',
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    app(RemindPendingWork::class)->handle();

    expect(asiBell($this->managerA, TaskReminderAlert::class)['title'])->toBe(__('tasks.managed.reminder.alert.overdue_assigner_general.title'));

    Mail::assertSent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->managerB->email)
        && $mail->envelope()->subject === __('tasks.managed.reminder.email.subject.overdue_assigner_general'));
});
