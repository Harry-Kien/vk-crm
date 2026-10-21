<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\AssignTask;
use App\Actions\Task\CancelTask;
use App\Actions\Task\CompleteTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\ReopenTask;
use App\Actions\Task\RestoreTask;
use App\Actions\Task\UpdateTask;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\BusinessHours;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Kiểm toán luồng Công việc 2026-10-21 — làn nhắc việc và con số, nhóm "mốc thời gian" của lịch `tasks.remind`:
 *
 *  - Q1: "Khôi phục" một việc đã quá hạn giữ `assigned_at` gốc (tỉ lệ đúng hạn), nhưng đồng hồ CHỜ NHẬN chạy từ lần
 *    khôi phục (`Task::awaitingSince()`) — lượt chọn, lần hỏi lại lúc gửi, khoá `awaiting@…` và câu "đã chờ".
 *  - Q2: "sắp đến hạn" = từ hôm nay tới hết NGÀY LÀM VIỆC kế tiếp (gồm ngày nghỉ xen giữa) — hạn Thứ Hai được nhắc
 *    sáng Thứ Sáu, hạn Chủ nhật cũng vậy; vẫn một lần cho mỗi hạn.
 *  - Q5: không "sắp đến hạn"/"quá hạn" cho một việc mà lần giao, giao lại, sửa hạn, mở lại hay khôi phục gần nhất xảy
 *    ra hôm nay (`Task::rescheduledToday()`), và không "quá hạn (người giao)" cho người đã thao tác trên việc hôm nay.
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30. Thứ Hai 12/10/2026 … Thứ Hai 19/10/2026.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    rtaTravel('2026-10-12 09:00');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'RTA-01']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function rtaTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function rtaRemind(): array
{
    return app(RemindPendingWork::class)->handle();
}

function rtaMails(string $kind, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.task.reminder.'.$kind)
        ->where('recipient', $to->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

function rtaHeld(TaskStatus $status, array $extra = []): Task
{
    return Task::factory()->forMatter(test()->matter)->status($status)->create([
        'title' => 'Soạn bản tự khai', 'assignee_id' => test()->assistant->id, 'assigned_by' => test()->lead->id, ...$extra,
    ]);
}

// =============================================================================================
// Q1 — đồng hồ chờ nhận của việc được khôi phục
// =============================================================================================

/**
 * Mutation probe: `awaitingSince()` trả `assigned_at` (bỏ vế "lần khôi phục") → đỏ (lời nhắc "57 giờ" 30 phút sau
 * khôi phục). Bỏ chỉ ở lượt chọn hay chỉ ở lần hỏi lại lúc gửi cũng đỏ: lời nhắc đi qua cả hai.
 */
it('starts the acceptance clock of a restored overdue task at the restore, not at the first assignment', function () {
    $task = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Nộp bản tự khai', dueDate: '2026-10-14');
    $assignedAt = $task->fresh()->assigned_at->toDateTimeString();

    rtaTravel('2026-10-13 10:00');
    app(CancelTask::class)->handle($task, $this->lead, 'Huỷ nhầm hồ sơ khác');

    // Thứ Hai tuần sau, hạn (14/10) đã qua: khôi phục giữ mốc giao gốc cho tỉ lệ đúng hạn.
    rtaTravel('2026-10-19 10:00');
    app(RestoreTask::class)->handle($task->fresh(), $this->lead, 'Việc vẫn phải làm');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending)
        ->and($task->assigned_at->toDateTimeString())->toBe($assignedAt)
        ->and($task->awaitingSince()->toDateTimeString())->toBe(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Ho_Chi_Minh')->toDateTimeString());

    rtaTravel('2026-10-19 10:30');
    // Không lời nhắc nào được xếp: chưa tới 24 giờ chờ nhận, và việc vừa khôi phục hôm nay không nhắc quá hạn (Q5).
    expect(rtaRemind()['queued'])->toBe(0);

    expect(rtaMails('awaiting', $this->assistant))->toBe(0)
        ->and(rtaMails('unaccepted', $this->lead))->toBe(0)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->where('data->title', __('tasks.reminder.alert.awaiting.title'))->count())->toBe(0);

    // 24 giờ làm việc sau lần khôi phục: Thứ Tư 21/10 15:00 — câu "đã chờ" đếm từ lần khôi phục.
    rtaTravel('2026-10-21 14:59');
    rtaRemind();
    expect(rtaMails('awaiting', $this->assistant))->toBe(0);

    rtaTravel('2026-10-21 15:00');
    rtaRemind();

    expect(rtaMails('awaiting', $this->assistant))->toBe(1)
        ->and(rtaMails('unaccepted', $this->lead))->toBe(0);

    $bell = $this->assistant->notifications()->where('type', TaskReminderAlert::class)
        ->where('data->title', __('tasks.reminder.alert.awaiting.title'))->sole();
    expect($bell->data['body'])->toContain('24 giờ')
        ->and($bell->data['viewData']['reminder_key'])->toContain(RemindPendingWork::awaitingKey($task->awaitingSince()));
});

/** Mutation probe: lần hỏi lại lúc gửi đọc `assigned_at` → một job đã xếp theo khoá cũ vẫn gửi → đỏ. */
it('drops at sending time a pending-acceptance reminder keyed to the assignment before the restore', function () {
    $task = rtaHeld(TaskStatus::Cancelled, ['due_date' => '2026-10-14', 'assigned_at' => now()]);
    $oldKey = RemindPendingWork::awaitingKey($task->assigned_at);

    rtaTravel('2026-10-19 10:00');
    app(RestoreTask::class)->handle($task, $this->lead, 'Việc vẫn phải làm');

    expect(RemindPendingWork::taskReminderHolds($task->fresh(), RemindPendingWork::AWAITING, $oldKey))->toBeFalse();
});

it('keeps the first assignment as the acceptance clock when the task was never restored', function () {
    $task = rtaHeld(TaskStatus::Pending, ['assigned_at' => now()]);

    expect($task->awaitingSince()->toDateTimeString())->toBe($task->assigned_at->toDateTimeString());

    rtaTravel('2026-10-14 14:00');
    rtaRemind();

    expect(rtaMails('awaiting', $this->assistant))->toBe(1);
});

// =============================================================================================
// Q2 — "sắp đến hạn" đếm theo ngày làm việc
// =============================================================================================

it('knows the next working day after a day, skipping the weekend', function () {
    $hours = BusinessHours::fromConfig();
    $day = fn (string $d) => $hours->nextWorkingDay(CarbonImmutable::parse($d.' 10:00', 'Asia/Ho_Chi_Minh'))->toDateString();

    expect($day('2026-10-15'))->toBe('2026-10-16')
        ->and($day('2026-10-16'))->toBe('2026-10-19')
        ->and($day('2026-10-17'))->toBe('2026-10-19')
        ->and($day('2026-10-18'))->toBe('2026-10-19');
});

/** Mutation probe: cửa sổ về `today()->addDay()` → hai ca đầu đỏ. */
it('reminds on Friday a task due on Monday or on Sunday, once per due date', function (string $due) {
    rtaHeld(TaskStatus::Accepted, ['due_date' => $due, 'assigned_at' => now()]);

    rtaTravel('2026-10-16 09:00');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);

    // Sáng Thứ Hai: cùng hạn, không nhắc lần hai.
    rtaTravel('2026-10-19 08:30');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);
})->with(['2026-10-19', '2026-10-18']);

/** Mutation probe: cửa sổ rộng hơn một ngày làm việc (vd. `addDays(3)`) → đỏ. */
it('does not remind on Thursday a task due the next Monday', function () {
    rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-19', 'assigned_at' => now()]);

    rtaTravel('2026-10-15 09:00');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(0);
});

it('still reminds on Wednesday a task due on Thursday', function () {
    rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-15', 'assigned_at' => now()]);

    rtaTravel('2026-10-14 09:00');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);
});

// =============================================================================================
// Q5 — không báo thừa ngay sau thao tác
// =============================================================================================

/** Mutation probe: bỏ vế `rescheduledToday()` khỏi lượt chọn "sắp đến hạn" → đỏ. */
it('does not send "due soon" half an hour after assigning a task due tomorrow, and sends it on the due day', function () {
    rtaTravel('2026-10-12 10:00');
    app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Nộp án phí', dueDate: '2026-10-13');

    rtaTravel('2026-10-12 10:30');
    expect(rtaRemind()['queued'])->toBe(0);
    rtaTravel('2026-10-12 16:00');
    expect(rtaRemind()['queued'])->toBe(0);

    expect(rtaMails('due_soon', $this->assistant))->toBe(0);

    rtaTravel('2026-10-13 08:00');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);
});

it('does not send "due soon" right after the due date was edited to tomorrow', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-30', 'assigned_at' => now()->subWeek()]);

    rtaTravel('2026-10-14 10:00');
    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-15', TaskPriority::Normal, null);

    rtaTravel('2026-10-14 10:30');
    expect(rtaRemind()['queued'])->toBe(0);

    expect(rtaMails('due_soon', $this->assistant))->toBe(0);
});

/** Mutation probe: lần hỏi lại lúc gửi không có vế `rescheduledToday()` → một job đã xếp trước lần sửa hạn vẫn gửi → đỏ. */
it('drops at sending time a "due soon" reminder for a task whose due date was just edited', function () {
    $untouched = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-13', 'assigned_at' => now()->subWeek()]);
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-20', 'assigned_at' => now()->subWeek()]);
    $key = 'due-soon@2026-10-13';

    rtaTravel('2026-10-12 09:30');
    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-13', TaskPriority::Normal, null);

    expect(RemindPendingWork::taskReminderHolds($untouched, RemindPendingWork::DUE_SOON, $key))->toBeTrue()
        ->and(RemindPendingWork::taskReminderHolds($task->fresh(), RemindPendingWork::DUE_SOON, $key))->toBeFalse();
});

/** Mutation probe: chỉ đọc lần "Sửa" có đổi hạn — một lần sửa chỉ đổi ưu tiên không được tính là đặt lại lịch → đỏ ở ca dưới. */
it('still sends "due soon" when today\'s edit did not touch the due date', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-13', 'assigned_at' => now()->subWeek()]);

    rtaTravel('2026-10-12 10:00');
    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-13', TaskPriority::High, null);

    rtaTravel('2026-10-12 10:30');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);
});

/** Mutation probe: bỏ vế `rescheduledToday()` khỏi lượt chọn "quá hạn" → đỏ. */
it('does not send overdue reminders half an hour after an overdue task was reopened, and does the next working day', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    app(CompleteTask::class)->handle($task, $this->assistant, 'Đã nộp');

    rtaTravel('2026-10-12 10:00');
    app(ReopenTask::class)->handle($task->fresh(), $this->lead, 'Thiếu bản sao công chứng');

    rtaTravel('2026-10-12 10:30');
    expect(rtaRemind()['queued'])->toBe(0);

    expect(rtaMails('overdue_assigner', $this->lead))->toBe(0)
        ->and(rtaMails('overdue', $this->assistant))->toBe(0);

    rtaTravel('2026-10-13 08:00');
    rtaRemind();

    expect(rtaMails('overdue_assigner', $this->lead))->toBe(1)
        ->and(rtaMails('overdue', $this->assistant))->toBe(1);
});

it('does not send overdue reminders on the day an overdue task was restored', function () {
    $task = rtaHeld(TaskStatus::Cancelled, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    rtaTravel('2026-10-12 10:00');
    app(RestoreTask::class)->handle($task, $this->lead, 'Huỷ nhầm');

    rtaTravel('2026-10-12 10:30');
    rtaRemind();

    expect(rtaMails('overdue_assigner', $this->lead))->toBe(0)
        ->and(rtaMails('overdue', $this->assistant))->toBe(0);
});

/** Mutation probe: vế "người nhận lời báo người giao đã thao tác trên việc hôm nay" bị bỏ → đỏ. */
it('does not tell the assigner a task is overdue on a day they already acted on it, but still reminds the holder', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    rtaTravel('2026-10-12 10:00');
    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-09', TaskPriority::Urgent, null);

    rtaTravel('2026-10-12 10:30');
    // Lượt chọn chỉ xếp lời nhắc người giữ (không lời báo người giao).
    expect(rtaRemind()['queued'])->toBe(1);

    expect(rtaMails('overdue_assigner', $this->lead))->toBe(0)
        ->and(rtaMails('overdue', $this->assistant))->toBe(1);
});

/** Mutation probe: bỏ vế `assignerActedToday()` khỏi `stillAddressedTo()` (lần hỏi lại lúc gửi) → đỏ. */
it('drops at sending time an overdue note to an assigner who acted on the task after it was queued', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    $job = new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::OVERDUE_ASSIGNER, $this->lead->id, RemindPendingWork::overdueKey());

    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-09', TaskPriority::Urgent, null);
    $job->handle(app(ResolveStaffRecipients::class));

    expect(rtaMails('overdue_assigner', $this->lead))->toBe(0)
        ->and(RemindPendingWork::stillAddressedTo($task->fresh(), RemindPendingWork::OVERDUE, $this->assistant))->toBeTrue();
});

/** Phép thử ngược của ranh giới "hôm nay": thao tác hôm qua không chặn lời nhắc hôm nay. */
it('reminds as usual when the last reschedule happened yesterday', function () {
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-20', 'assigned_at' => now()->subWeek()]);

    rtaTravel('2026-10-12 16:00');
    app(UpdateTask::class)->handle($task, $this->lead, $task->title, null, '2026-10-13', TaskPriority::Normal, null);

    rtaTravel('2026-10-13 08:00');
    rtaRemind();

    expect(rtaMails('due_soon', $this->assistant))->toBe(1);
});

/** Mutation probe: dòng "Chờ nhận từ …" của bảng đọc `assigned_at` → đỏ. */
it('shows a restored overdue task as waiting since the restore on the task list', function () {
    Filament\Facades\Filament::setCurrentPanel('admin');
    $task = rtaHeld(TaskStatus::Cancelled, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    rtaTravel('2026-10-12 10:00');
    app(RestoreTask::class)->handle($task, $this->lead, 'Huỷ nhầm');
    rtaTravel('2026-10-12 10:30');

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->assertSee(__('tasks.waiting', ['since' => now()->subMinutes(30)->diffForHumans()]))
        ->assertDontSee(__('tasks.waiting', ['since' => now()->subWeeks(2)->subMinutes(90)->diffForHumans()]));
});

/** Mutation probe: bỏ vế `rescheduledToday()` khỏi lần hỏi lại lúc gửi của lời nhắc quá hạn → đỏ. */
it('drops at sending time an overdue reminder for a task that was reassigned today', function () {
    $other = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->matter->addTeamMember($other, MatterRole::Assistant);
    $task = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);
    $untouched = rtaHeld(TaskStatus::Accepted, ['due_date' => '2026-10-09', 'assigned_at' => now()->subWeeks(2)]);

    app(ReassignTask::class)->handle($task, $this->lead, $other);

    expect(RemindPendingWork::taskReminderHolds($untouched, RemindPendingWork::OVERDUE, RemindPendingWork::overdueKey()))->toBeTrue()
        ->and(RemindPendingWork::taskReminderHolds($task->fresh(), RemindPendingWork::OVERDUE, RemindPendingWork::overdueKey()))->toBeFalse()
        ->and(RemindPendingWork::taskReminderHolds($task->fresh(), RemindPendingWork::OVERDUE_ASSIGNER, RemindPendingWork::overdueKey()))->toBeFalse();
});
