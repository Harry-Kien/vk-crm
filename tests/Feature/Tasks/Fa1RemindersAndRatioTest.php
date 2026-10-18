<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Jobs\SendWorkReminder;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa1, phán quyết 5, 6 và "Giao lại đặt hạn mới" của phán quyết 7:
 *
 *  - lịch nhắc và số "quá hạn" chỉ tính người còn GIỮ ĐƯỢC việc (`TaskAssignees::holdsSeat` — không người theo
 *    dõi, không ghế đã trả lại, còn mở được hồ sơ); việc đó hiện "Cần giao lại" và người giao được nhắc thay;
 *  - "Việc hoàn thành đúng hạn" = xong đúng hạn ÷ (xong trong kỳ + việc còn giữ đã quá hạn, hạn trong kỳ);
 *  - "Giao lại" đặt được hạn mới.
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    fa1TravelVn('2026-10-12 09:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FA1-NH-01']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function fa1TravelVn(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function fa1ReminderMails(string $template, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', $template)
        ->where('recipient', $to->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

/** Ghế của trợ lý: "Trả lại" hồ sơ (ghế ở lại), hay chuyển sang người theo dõi. */
function fa1LoseSeat(string $how): void
{
    $matter = test()->matter;
    $assistant = test()->assistant;

    if ($how === 'returned') {
        $matter->team()->updateExistingPivot($assistant->id, ['accepted_at' => null, 'declined_at' => now(), 'decline_reason' => 'Quá tải']);

        return;
    }

    $matter->team()->detach($assistant->id);
    $matter->addTeamMember($assistant, MatterRole::Observer);
}

// =============================================================================================
// 5. NHẮC VÀ SỐ QUÁ HẠN CHỈ TÍNH NGƯỜI CÒN GIỮ ĐƯỢC VIỆC
// =============================================================================================

/** Mutation probe: bỏ vế "còn giữ ghế" khỏi lượt chọn / lần hỏi lại của lời nhắc người nhận → đỏ. */
it('stops reminding a holder who returned the matter or became an observer, and reminds the assigner instead', function (string $how) {
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Thu thập hồ sơ', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09',
    ]);
    fa1LoseSeat($how);

    // Lượt chọn chỉ xếp MỘT lời nhắc: lời báo người giao (không lời nhắc người nhận).
    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(1);

    expect(fa1ReminderMails('staff.task.reminder.overdue', $this->assistant))->toBe(0)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and(fa1ReminderMails('staff.task.reminder.overdue_assigner', $this->lead))->toBe(1);

    $bell = $this->lead->notifications()->where('type', TaskReminderAlert::class)->sole();
    expect($bell->data['body'])->toContain(__('tasks.reminder_needs_reassignment', ['who' => 'Trợ lý Lan']));

    expect(Task::query()->whereKey($task->id)->needsReassignment()->exists())->toBeTrue();
})->with(['returned', 'observer']);

/** Mutation probe: bỏ vế "còn giữ ghế" khỏi `RemindPendingWork::stillAddressedTo()` → đỏ (lần hỏi lại lúc gửi). */
it('drops, at sending time, a reminder queued before the holder returned the matter', function () {
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Thu thập hồ sơ', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09',
    ]);
    // Lời nhắc đã vào hàng đợi khi trợ lý còn giữ việc; trợ lý trả lại hồ sơ trước khi job chạy.
    $job = new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::OVERDUE, $this->assistant->id, RemindPendingWork::overdueKey());
    fa1LoseSeat('returned');

    $job->handle(app(ResolveStaffRecipients::class));

    expect(fa1ReminderMails('staff.task.reminder.overdue', $this->assistant))->toBe(0)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0);
});

it('sends no "waiting for you" or "due soon" reminder to a holder who no longer holds the seat', function () {
    Task::factory()->forMatter($this->matter)->create([
        'title' => 'Chờ nhận', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(),
    ]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Sắp đến hạn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-10-15',
    ]);
    fa1LoseSeat('observer');

    fa1TravelVn('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    expect(fa1ReminderMails('staff.task.reminder.awaiting', $this->assistant))->toBe(0)
        ->and(fa1ReminderMails('staff.task.reminder.due_soon', $this->assistant))->toBe(0);
});

/** Mutation probe: bỏ vế "còn mở được hồ sơ" (`whereNot … whereCannotOpenMatter`) khỏi `Task::scopeHeldBySeatedAssignee()` → đỏ. */
it('leaves out of the overdue number the task of a holder who can no longer open the matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-10-09',
    ]);
    // Hồ sơ đổi sang hạn chế: trợ lý còn ngồi ghế nhưng không mở được hồ sơ (vế `off_access` của "Cần giao lại").
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    $this->actingAs($admin, 'web');
    expect(Livewire::test(TeamOverview::class)->instance()->getTableRecords()[$this->assistant->id]['tasksOverdue'])->toBe(0);
});

/** Mutation probe: bỏ vế "còn giữ ghế" khỏi `Task::scopeOverdueToHandle()` → đỏ (số 1 thay vì 0). */
it('leaves such a task out of the person\'s overdue numbers, on the dashboard tile, the list it opens and Theo dõi đội ngũ', function () {
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Thu thập hồ sơ', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09',
    ]);
    $kept = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của người còn giữ', 'assignee_id' => $this->otherAssistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-09',
    ]);
    fa1LoseSeat('returned');

    $this->actingAs($this->manager, 'web');
    $rows = Livewire::test(TeamOverview::class)->instance()->getTableRecords();
    expect($rows[$this->assistant->id]['tasksOverdue'])->toBe(0)
        ->and($rows[$this->otherAssistant->id]['tasksOverdue'])->toBe(1);

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$task]);

    // Trợ lý không còn việc nào phải xử lý: ô "Việc cần xử lý của tôi" không hiện trên bảng điều khiển.
    $this->actingAs($this->assistant, 'web');
    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertDontSeeLivewire(MyWorkQueueWidget::class);
});

// =============================================================================================
// 6. TỈ LỆ ĐÚNG HẠN TÍNH CẢ VIỆC QUÁ HẠN CÒN MỞ
// =============================================================================================

/** Mutation probe: bỏ phần "việc còn giữ đã quá hạn" khỏi mẫu số → đỏ (100% (5/5) thay vì 83,3% (5/6)). */
it('counts a task still overdue at the end of the period against the person, not only the finished ones', function () {
    $this->travelTo('2026-10-15 10:00:00');

    foreach (['2026-09-05', '2026-09-10', '2026-09-15', '2026-09-20', '2026-09-25'] as $due) {
        Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
            'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
            'due_date' => $due, 'completed_at' => $due.' 09:00:00', 'assigned_at' => '2026-09-01 09:00:00',
        ]);
    }
    // Quá hạn từ 10/9, vẫn đang làm: tính là trễ trong tháng 9. (Giao trước hạn — gộp integ-fa: việc nhận sau hạn
    // không vào tỉ lệ.)
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-09-10',
        'assigned_at' => '2026-09-01 09:00:00',
    ]);
    // Không tính: đã huỷ; bị trả lại (không còn trong tay); hạn ngoài kỳ; vụ đã kết thúc.
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Cancelled)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-09-11',
    ]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-09-12',
    ]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-10-01',
    ]);
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'closed_at' => now()]);
    $closed->addTeamMember($this->assistant, MatterRole::Assistant);
    Task::factory()->forMatter($closed)->status(TaskStatus::Accepted)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-09-13',
    ]);

    $this->actingAs($this->manager, 'web');
    $page = Livewire::test(Performance::class);
    $row = $page->instance()->getTableRecords()[$this->assistant->id];

    expect($row['taskOnTime']->label())->toBe('83,3% (5/6)')
        ->and($page->html())->toContain(e(__('tasks.performance.explain')));
    expect(__('tasks.performance.explain'))->toContain('quá hạn');
});

// =============================================================================================
// 7. GIAO LẠI ĐẶT ĐƯỢC HẠN MỚI
// =============================================================================================

it('keeps a future due date when reassigning and suggests a new one when it has passed', function () {
    $this->travelTo('2026-10-15 10:00:00');
    $future = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Còn hạn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-30',
    ]);
    $past = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Đã quá hạn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'assigned_at' => '2026-10-01 09:00:00', 'due_date' => '2026-10-06',
    ]);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountAction(TestAction::make('reassign')->table($future))
        ->assertActionDataSet(['due_date' => '2026-10-30']);

    // Hạn cũ đã qua: gợi ý hôm nay + số ngày người cũ có (1/10 → 6/10: 5 ngày) = 20/10.
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountAction(TestAction::make('reassign')->table($past))
        ->assertActionDataSet(['due_date' => '2026-10-20']);
});

/** Mutation probe: ReassignTask bỏ qua `due_date` → đỏ. */
it('reassigns with a new due date, logs the change, and the new holder\'s mail carries the new date', function () {
    Mail::fake();
    $this->travelTo('2026-10-15 10:00:00');
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Đã quá hạn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
        'due_date' => '2026-10-06',
    ]);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->otherAssistant->id, 'due_date' => '2026-10-22'])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-22')
        ->and($task->fresh()->assignee_id)->toBe($this->otherAssistant->id);

    $row = Activity::query()->where('event', 'task_reassigned')->sole();
    expect($row->properties['due_date_from'])->toBe('2026-10-06')
        ->and($row->properties['due_date_to'])->toBe('2026-10-22');

    Mail::assertSent(TaskNotice::class, fn ($mail): bool => $mail->hasTo($this->otherAssistant->email)
        && str_contains($mail->render(), '22/10/2026'));

    // Hạn đã qua chỉ được giữ nguyên, không được chọn mới.
    $again = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Lần hai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => '2026-10-06',
    ]);
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('reassign', $again, data: ['assignee_id' => $this->otherAssistant->id, 'due_date' => '2026-10-10'])
        ->assertHasTableActionErrors(['due_date']);
});
