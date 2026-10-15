<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\TaskReminderAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

/**
 * Làn tk, Task 2 — lịch `tasks.remind` (`RemindPendingWork`): chờ nhận quá 24 giờ làm việc (nhắc người
 * nhận), quá 48 giờ làm việc (báo người giao), sắp đến hạn (một lần cho mỗi hạn), quá hạn (mỗi ngày một
 * lần, người nhận và người giao). Mỗi lời nhắc một lần cho mỗi người nhận — đo bằng chuông và sổ thư thật
 * (thư đi qua mailer `array`, sổ thư ghi như máy thật).
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30 (9,5 giờ một ngày). Giao 09:00 Thứ Hai 12/10/2026 →
 * 24 giờ làm việc chạm lúc 14:00 Thứ Tư 14/10; 48 giờ làm việc chạm lúc 09:30 Thứ Hai 19/10.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    travelToVn('2026-10-12 09:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0042']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function travelToVn(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function remindNow(): array
{
    return app(RemindPendingWork::class)->handle();
}

function taskReminderMails(?string $template = null, ?User $to = null): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'like', 'staff.task.%')
        ->when($template !== null, fn ($q) => $q->where('template', $template))
        ->when($to !== null, fn ($q) => $q->where('recipient', $to->email))
        ->where('status', OutboundStatus::Sent)
        ->count();
}

function reminderBells(User $user, string $type = TaskReminderAlert::class): array
{
    return $user->notifications()->where('type', $type)->get()->pluck('data')->all();
}

function scheduledRemindEvent(): CallbackEvent
{
    $matches = collect(Schedule::events())->filter(fn ($e) => $e->description === 'tasks.remind')->values();

    expect($matches)->toHaveCount(1);

    return $matches->first();
}

it('registers tasks.remind every thirty minutes with a thirty-minute overlap lock, and runs RemindPendingWork', function () {
    $event = scheduledRemindEvent();

    expect($event->expression)->toBe('*/30 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);

    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now()]);

    travelToVn('2026-10-14 14:00');
    $event->run(app());

    expect(taskReminderMails('staff.task.reminder.awaiting', $this->assistant))->toBe(1);
});

it('reminds the assignee once after 24 business hours, not before, and never twice', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn khởi kiện', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(),
    ]);

    // 24 giờ ĐỒNG HỒ sau (Thứ Ba 09:00) chưa tới 24 giờ làm việc.
    travelToVn('2026-10-13 09:00');
    remindNow();
    travelToVn('2026-10-14 13:59');
    remindNow();

    expect(taskReminderMails())->toBe(0)->and(reminderBells($this->assistant))->toBe([]);

    travelToVn('2026-10-14 14:00');
    remindNow();
    travelToVn('2026-10-14 14:30');
    remindNow();

    expect(taskReminderMails('staff.task.reminder.awaiting', $this->assistant))->toBe(1)
        ->and(reminderBells($this->assistant))->toHaveCount(1)
        ->and(reminderBells($this->assistant)[0]['body'])->toContain('Soạn đơn khởi kiện')
        ->and(reminderBells($this->assistant)[0]['body'])->toContain('VK-2026-DS-0042')
        // Người giao chưa được báo ở bậc 1.
        ->and(taskReminderMails(to: $this->lead))->toBe(0);
});

it('does nothing outside business hours', function () {
    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now()]);

    foreach (['2026-10-14 18:00', '2026-10-17 10:00', '2026-10-18 10:00'] as $closed) {
        travelToVn($closed);
        expect(remindNow())->toBe(['queued' => 0]);
    }

    expect(taskReminderMails())->toBe(0);
});

it('tells the assigner after 48 business hours, and the current lead instead when the assigner is gone', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Nộp án phí', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now(),
    ]);
    $second = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(),
    ]);

    travelToVn('2026-10-19 09:29');
    remindNow();

    expect(taskReminderMails('staff.task.reminder.unaccepted'))->toBe(0);

    // Người giao việc thứ nhất nghỉ việc trước khi tới ngưỡng thứ hai.
    $this->manager->forceFill(['is_active' => false])->save();

    travelToVn('2026-10-19 09:30');
    remindNow();
    remindNow();

    expect(taskReminderMails('staff.task.reminder.unaccepted', $this->manager))->toBe(0)
        // Luật sư phụ trách nhận thay cho việc của quản lý đã nghỉ, và nhận lời báo của việc chính họ giao: 2 thư.
        ->and(taskReminderMails('staff.task.reminder.unaccepted', $this->lead))->toBe(2)
        ->and(collect(reminderBells($this->lead))->pluck('body')->implode(' '))->toContain('Trợ lý Lan');
});

it('sends nothing once the assignee accepts between the run and the job', function () {
    $task = Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now()]);

    travelToVn('2026-10-14 14:00');
    $task->forceFill(['status' => TaskStatus::Accepted, 'accepted_at' => now()])->save();

    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::AWAITING, $this->assistant->id, RemindPendingWork::awaitingKey($task->assigned_at)))
        ->handle(app(ResolveStaffRecipients::class));

    expect(taskReminderMails())->toBe(0)->and(reminderBells($this->assistant))->toBe([]);
});

it('reminds once for a due date tomorrow, again only when the due date moves', function () {
    travelToVn('2026-10-14 09:00');
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp bản khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'assigned_at' => now(), 'due_date' => '2026-10-15',
    ]);

    remindNow();
    travelToVn('2026-10-15 08:00');
    remindNow();

    expect(taskReminderMails('staff.task.reminder.due_soon', $this->assistant))->toBe(1)
        ->and(reminderBells($this->assistant)[0]['body'])->toContain('15/10/2026')
        ->and(taskReminderMails(to: $this->lead))->toBe(0);

    $task->forceFill(['due_date' => '2026-10-16'])->save();
    remindNow();

    expect(taskReminderMails('staff.task.reminder.due_soon', $this->assistant))->toBe(2);
});

it('reminds the assignee and the assigner of an overdue task once a business day', function () {
    travelToVn('2026-10-12 09:00');
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp bản khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'assigned_at' => now(), 'due_date' => '2026-10-13',
    ]);
    // Việc đã trả lại không nhắc ai.
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(), 'due_date' => '2026-10-13',
    ]);

    travelToVn('2026-10-14 08:00');
    remindNow();
    travelToVn('2026-10-14 16:00');
    remindNow();

    expect(taskReminderMails('staff.task.reminder.overdue', $this->assistant))->toBe(1)
        ->and(taskReminderMails('staff.task.reminder.overdue_assigner', $this->lead))->toBe(1);

    travelToVn('2026-10-15 08:00');
    remindNow();

    expect(taskReminderMails('staff.task.reminder.overdue', $this->assistant))->toBe(2)
        ->and(taskReminderMails('staff.task.reminder.overdue_assigner', $this->lead))->toBe(2)
        ->and(collect(reminderBells($this->assistant))->pluck('body')->implode(' '))->toContain('quá hạn 2 ngày');
});

it('never reminds anyone who cannot open a restricted matter, and stops for a closed matter', function () {
    travelToVn('2026-10-12 09:00');
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc bí mật', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id,
        'assigned_at' => now(), 'due_date' => '2026-10-13',
    ]);

    // Vụ siết hạn chế sau khi giao: trợ lý và quản lý không còn mở được vụ.
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    travelToVn('2026-10-14 08:00');
    remindNow();

    expect(taskReminderMails(to: $this->assistant))->toBe(0)
        ->and(taskReminderMails(to: $this->manager))->toBe(0)
        ->and(reminderBells($this->assistant))->toBe([])
        ->and(reminderBells($this->manager))->toBe([])
        // Lời báo "người giao" tới luật sư phụ trách — người mở được vụ.
        ->and(taskReminderMails('staff.task.reminder.overdue_assigner', $this->lead))->toBe(1);

    $this->matter->forceFill(['closed_at' => today()])->save();
    travelToVn('2026-10-15 08:00');
    expect(remindNow())->toBe(['queued' => 0]);
});

it('reminds a member who has not accepted a seat after 24 business hours, then the assigner after 48', function () {
    $member = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->matter->addTeamMember($member, MatterRole::Associate, assignedBy: $this->manager);
    $seat = MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $member->id)->sole();

    travelToVn('2026-10-14 14:00');
    remindNow();
    remindNow();

    expect(taskReminderMails('staff.task.matter.reminder.awaiting', $member))->toBe(1)
        ->and(reminderBells($member, MatterAssignmentAlert::class))->toHaveCount(1)
        ->and(reminderBells($member, MatterAssignmentAlert::class)[0]['title'])->toContain('VK-2026-DS-0042')
        ->and(taskReminderMails('staff.task.matter.reminder.unaccepted'))->toBe(0);

    travelToVn('2026-10-19 10:00');
    remindNow();

    expect(taskReminderMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(1)
        ->and(taskReminderMails('staff.task.matter.reminder.unaccepted', $member))->toBe(0);

    // Đã nhận: không nhắc nữa, kể cả một job còn trong hàng.
    $seat->forceFill(['accepted_at' => now()])->save();
    (new SendWorkReminder(RemindPendingWork::SUBJECT_SEAT, $seat->id, RemindPendingWork::AWAITING, $member->id, 'awaiting@x'))
        ->handle(app(ResolveStaffRecipients::class));
    travelToVn('2026-10-20 10:00');
    remindNow();

    expect(taskReminderMails('staff.task.matter.reminder.awaiting', $member))->toBe(1);
});

/*
 * Hai tầng, mỗi tầng tự đúng: lượt nhắc chỉ XẾP lời nhắc đã tới lúc, và job tự hỏi lại lúc gửi. Hai test dưới
 * đo từng tầng riêng (hàng đợi giả cho lượt nhắc; job gọi thẳng cho lần hỏi lại).
 */
it('queues the assigner notice only once 48 business hours have passed, and never re-queues a reminder already mailed', function () {
    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now()]);
    $this->matter->addTeamMember(User::factory()->withRole(Role::Lawyer)->create(), MatterRole::Associate, assignedBy: $this->lead);

    // Bậc 1 gửi thật (sổ thư có dòng `sent`).
    travelToVn('2026-10-14 14:00');
    remindNow();

    Queue::fake();

    travelToVn('2026-10-19 09:29');
    remindNow();

    Queue::assertNotPushed(SendWorkReminder::class);

    travelToVn('2026-10-19 09:30');
    remindNow();

    Queue::assertPushed(SendWorkReminder::class, 2);
    Queue::assertPushed(SendWorkReminder::class, fn (SendWorkReminder $job): bool => $job->kind === RemindPendingWork::UNACCEPTED);
    Queue::assertNotPushed(SendWorkReminder::class, fn (SendWorkReminder $job): bool => $job->kind === RemindPendingWork::AWAITING);
});

it('re-checks the threshold and the due date when the job runs late', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(), 'due_date' => '2026-10-13',
    ]);
    $member = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($member, MatterRole::Associate, assignedBy: $this->lead);
    $seat = MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $member->id)->sole();
    $resolver = app(ResolveStaffRecipients::class);

    // 30 giờ làm việc: đã quá bậc 1, chưa tới bậc 2 — một job "báo người giao" xếp nhầm không gửi gì.
    travelToVn('2026-10-15 11:00');
    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::UNACCEPTED, $this->lead->id, RemindPendingWork::awaitingKey($task->assigned_at)))->handle($resolver);
    (new SendWorkReminder(RemindPendingWork::SUBJECT_SEAT, $seat->id, RemindPendingWork::UNACCEPTED, $this->lead->id, RemindPendingWork::awaitingKey($seat->assigned_at)))->handle($resolver);
    // Lời nhắc "sắp đến hạn" chạy sau khi hạn đã qua.
    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::DUE_SOON, $this->assistant->id, RemindPendingWork::dueSoonKey($task)))->handle($resolver);

    expect(taskReminderMails())->toBe(0)
        ->and(reminderBells($this->lead))->toBe([])
        ->and(reminderBells($this->lead, MatterAssignmentAlert::class))->toBe([])
        ->and(reminderBells($this->assistant))->toBe([]);
});

it('neither queues nor sends a reminder to someone who can no longer open a restricted matter', function () {
    $task = Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now()]);
    $member = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($member, MatterRole::Associate, assignedBy: $this->lead);
    $seat = MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $member->id)->sole();
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    travelToVn('2026-10-14 14:00');
    Queue::fake();
    remindNow();

    Queue::assertNotPushed(SendWorkReminder::class);

    // Job xếp từ trước khi vụ siết hạn chế: không gửi.
    $resolver = app(ResolveStaffRecipients::class);
    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::AWAITING, $this->assistant->id, RemindPendingWork::awaitingKey($task->assigned_at)))->handle($resolver);
    (new SendWorkReminder(RemindPendingWork::SUBJECT_SEAT, $seat->id, RemindPendingWork::AWAITING, $member->id, RemindPendingWork::awaitingKey($seat->assigned_at)))->handle($resolver);

    expect(taskReminderMails())->toBe(0)
        ->and(reminderBells($this->assistant))->toBe([])
        ->and(reminderBells($member, MatterAssignmentAlert::class))->toBe([]);
});

it('tells a manager, never the new lead themself, when a handed-over lead seat goes unaccepted and the assigner has left', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->forceFill(['lead_lawyer_id' => $newLead->id])->save();
    $this->matter->team()->attach($newLead->id, ['role_in_matter' => MatterRole::Lead->value, ...Matter::seatAssignment($newLead, MatterRole::Lead, $admin)]);
    $admin->forceFill(['is_active' => false])->save();

    travelToVn('2026-10-19 09:30');
    remindNow();

    expect(taskReminderMails('staff.task.matter.reminder.unaccepted', $newLead))->toBe(0)
        ->and(taskReminderMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(1)
        ->and(taskReminderMails('staff.task.matter.reminder.awaiting', $newLead))->toBe(1);
});
