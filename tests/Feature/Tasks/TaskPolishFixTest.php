<?php

use App\Actions\Matter\SeatsNeedingReassignment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Performance\BuildTaskWorkload;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Jobs\SendReassignmentDigest;
use App\Jobs\SendSeatReminderDigest;
use App\Jobs\SendTaskNotice;
use App\Jobs\SendWorkReminder;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Tasks\SeatInbox;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Làn tk, vòng sửa polish 1 — chín chỗ còn treo sau vòng sửa cuối 1 (rà soát Task 1: m5, m8; Task 2: m6 +
 * n2, m7, m8; cổng 403 của trang Công việc; lý do trả lại đọc lúc gửi; "Việc cần giao lại" trên Theo dõi
 * đội ngũ; tiếng ồn của bàn giao hàng loạt — theo phán quyết controller (3): chuông lô gộp vào thư tổng hợp,
 * lời nhắc và lời báo lên người giao gộp theo người, ghế của người đã nghỉ hiện "Cần phân công lại"). Màn
 * hình qua Livewire hay HTTP; lịch nhắc qua chính lượt lịch.
 *
 * Giờ làm việc mặc định: Thứ Hai–Thứ Sáu 08:00–17:30. Giao 09:00 Thứ Hai 12/10/2026 → 24 giờ làm việc chạm
 * lúc 14:00 Thứ Tư 14/10; 48 giờ làm việc chạm lúc 09:30 Thứ Hai 19/10.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    tkPolTravel('2026-10-12 09:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->other = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Minh']);
    $this->matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0091']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->other, MatterRole::Assistant);
});

function tkPolTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function tkPolTask(Matter $matter, User $assignee, User $assigner, TaskStatus $status = TaskStatus::Pending, array $extra = []): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'assignee_id' => $assignee->id, 'assigned_by' => $assigner->id, 'assigned_at' => now(), ...$extra,
    ]);
}

function tkPolList(User $viewer, string $tab): Testable
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(ListTasks::class, ['activeTab' => $tab]);
}

function tkPolMails(string $template, ?User $to = null, OutboundStatus $status = OutboundStatus::Sent): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', $template)
        ->when($to !== null, fn ($q) => $q->where('recipient', $to->email))
        ->where('status', $status)
        ->count();
}

class TkPolishBrokenTransport implements TransportInterface
{
    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        throw new TransportException('SMTP không trả lời');
    }

    public function __toString(): string
    {
        return 'tk-polish-broken://';
    }
}

function tkPolBreakMail(): void
{
    config()->set('mail.mailers.tk_polish_broken', ['transport' => 'tk_polish_broken']);
    Mail::extend('tk_polish_broken', fn (): TransportInterface => new TkPolishBrokenTransport);
    config(['mail.default' => 'tk_polish_broken']);
}

// =============================================================================================
// 1. Rà soát Task 1, m5 — người không còn giữ việc được không bấm "Đã nhận" / "Hoàn thành"
// =============================================================================================

it('hides "Đã nhận" and "Hoàn thành" from a holder moved to observer, keeps "Trả lại", and flags the task', function () {
    $pending = tkPolTask($this->matter, $this->assistant, $this->lead, extra: ['title' => 'Soạn bản tự khai']);
    $accepted = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Nộp đơn']);
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    // Kiểm toán 2026-10-21 (N2): "Chờ tôi nhận"/"Đang làm" chỉ có việc người đó còn giữ được (cùng định nghĩa ô, huy
    // hiệu, Theo dõi đội ngũ); việc này ở tab "Tất cả" của họ, nơi họ vẫn "Trả lại" được.
    tkPolList($this->assistant, 'awaiting')->assertCanNotSeeTableRecords([$pending]);
    tkPolList($this->assistant, 'active')->assertCanNotSeeTableRecords([$accepted]);

    tkPolList($this->assistant, 'all')
        ->assertCanSeeTableRecords([$pending, $accepted])
        ->assertTableActionHidden('accept', $pending)
        ->assertTableActionHidden('complete', $pending)
        ->assertTableActionVisible('decline', $pending)
        ->assertTableActionHidden('complete', $accepted)
        ->assertTableActionVisible('decline', $accepted);

    tkPolList($this->manager, 'all')
        ->filterTable('needs_reassignment')
        ->assertCanSeeTableRecords([$pending, $accepted]);
});

it('hides them too from a holder who returned their own seat on the matter', function () {
    $pending = tkPolTask($this->matter, $this->assistant, $this->lead);
    $this->matter->team()->updateExistingPivot($this->assistant->id, ['accepted_at' => null, 'declined_at' => now(), 'decline_reason' => 'Xung đột lợi ích']);

    // Kiểm toán 2026-10-21 (N2): tab "Tất cả" — xem ca trên.
    tkPolList($this->assistant, 'all')
        ->assertTableActionHidden('accept', $pending)
        ->assertTableActionHidden('complete', $pending)
        ->assertTableActionVisible('decline', $pending);
});

it('still lets a holder on a working seat accept and complete, and a general task\'s holder too', function () {
    $pending = tkPolTask($this->matter, $this->assistant, $this->lead);
    $general = Task::factory()->create(['matter_id' => null, 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now()]);

    tkPolList($this->assistant, 'awaiting')
        ->assertTableActionVisible('accept', $pending)
        ->assertTableActionVisible('complete', $pending)
        ->assertTableActionVisible('accept', $general)
        ->callTableAction('accept', $pending)
        ->assertHasNoTableActionErrors();

    expect($pending->fresh()->status)->toBe(TaskStatus::Accepted);
});

it('refuses a stale "Đã nhận" from a screen opened before the holder became an observer', function () {
    $pending = tkPolTask($this->matter, $this->assistant, $this->lead);
    // Kiểm toán 2026-10-21 (N2): tab "Tất cả" — sau khi thành người theo dõi, việc rời "Chờ tôi nhận".
    $page = tkPolList($this->assistant, 'all')->assertTableActionVisible('accept', $pending);

    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    // Lần bấm kế tiếp trên CÙNG màn hình hỏi lại policy phía máy chủ: nút không còn, không gì đổi.
    $page->call('$refresh')
        ->assertTableActionHidden('accept', $pending)
        ->assertTableActionHidden('complete', $pending);

    expect($pending->fresh()->status)->toBe(TaskStatus::Pending);
});

// =============================================================================================
// 2. Rà soát Task 1, m8 — "Tất cả" và "Tôi đã giao": việc còn mở trước, việc đã đóng sau
// =============================================================================================

it('lists open tasks first by due date (no due date last), then closed ones, on "Tất cả" and "Tôi đã giao"', function () {
    $doneEarly = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed, ['due_date' => today()->subDays(5), 'completed_at' => now()]);
    $openLate = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['due_date' => today()->addDays(9)]);
    $openNoDue = tkPolTask($this->matter, $this->assistant, $this->lead, extra: ['due_date' => null]);
    $openSoon = tkPolTask($this->matter, $this->other, $this->lead, extra: ['due_date' => today()->addDay()]);
    $returned = tkPolTask($this->matter, $this->other, $this->lead, TaskStatus::Declined, ['due_date' => today()->addDays(3), 'decline_reason' => 'Quá tải']);

    $expected = [$openSoon, $returned, $openLate, $openNoDue, $doneEarly];

    tkPolList($this->manager, 'all')->assertCanSeeTableRecords($expected, inOrder: true);
    tkPolList($this->lead, 'assigned')->assertCanSeeTableRecords($expected, inOrder: true);
});

// =============================================================================================
// 3. Rà soát Task 2, m6 + n2 — lời nhắc hỏi lại "còn là người nhận / người giao" lúc gửi
// =============================================================================================

it('does not send an overdue reminder to someone who no longer holds the task when the job runs', function () {
    // Trưởng phòng ngồi ghế luật sư cộng sự và giữ việc; sau khi giao lại họ VẪN xem được việc (quản lý).
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);
    $task = tkPolTask($this->matter, $this->manager, $this->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(2)]);

    Queue::fake([SendWorkReminder::class]);
    app(RemindPendingWork::class)->handle();

    $jobs = Queue::pushed(SendWorkReminder::class, fn (SendWorkReminder $job): bool => $job->kind === RemindPendingWork::OVERDUE && $job->recipientId === $this->manager->id);
    expect($jobs)->toHaveCount(1);

    // Giao lại cho trợ lý khác trước khi job chạy.
    $this->actingAs($this->lead, 'web');
    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->other->id])
        ->assertHasNoTableActionErrors();

    $jobs->each(fn (SendWorkReminder $job) => $job->handle(app(ResolveStaffRecipients::class)));

    expect($this->manager->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and(tkPolMails('staff.task.reminder.overdue', $this->manager))->toBe(0);
});

it('does not send an assigner reminder to someone who is no longer the assigner (or its stand-in) when the job runs', function () {
    // Trưởng phòng là luật sư phụ trách và người giao; bàn giao chuyển người giao sang lead mới. Trưởng phòng
    // vẫn xem được vụ (quyền xem mọi vụ).
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->manager->id, 'code' => 'VK-2026-DS-0092']);
    $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $matter->addTeamMember($this->lead, MatterRole::Associate);
    $task = tkPolTask($matter, $this->assistant, $this->manager, TaskStatus::Accepted, ['due_date' => today()->subDays(2)]);

    Queue::fake([SendWorkReminder::class]);
    app(RemindPendingWork::class)->handle();

    $jobs = Queue::pushed(SendWorkReminder::class, fn (SendWorkReminder $job): bool => $job->kind === RemindPendingWork::OVERDUE_ASSIGNER && $job->recipientId === $this->manager->id);
    expect($jobs)->toHaveCount(1);

    $this->actingAs($this->admin, 'web');
    Livewire::test(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: ['new_lead_id' => $this->lead->id, 'keep_old_lead_as_associate' => false, 'reason' => 'Đổi người phụ trách.'])
        ->assertHasNoActionErrors();

    expect($task->fresh()->assigned_by)->toBe($this->lead->id);

    $jobs->each(fn (SendWorkReminder $job) => $job->handle(app(ResolveStaffRecipients::class)));

    expect($this->manager->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and(tkPolMails('staff.task.reminder.overdue_assigner', $this->manager))->toBe(0);
});

it('still sends the reminder when the recipient is still the holder and still the assigner', function () {
    $task = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(2)]);

    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.reminder.overdue', $this->assistant))->toBe(1)
        ->and(tkPolMails('staff.task.reminder.overdue_assigner', $this->lead))->toBe(1);
});

// =============================================================================================
// 4. Rà soát Task 2, m7 — thư nhắc hỏng hẳn không bị xếp lại mỗi 30 phút
// =============================================================================================

it('tries a failing reminder mail once per recipient per item per day, then again the next day', function () {
    $task = tkPolTask($this->matter, $this->assistant, $this->lead);
    $arrayMailer = config('mail.default');
    tkPolBreakMail();

    tkPolTravel('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.reminder.awaiting', $this->assistant, OutboundStatus::Failed))->toBe(1)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'work_reminder_failed')->where('subject_id', $task->id)->count())->toBe(1);

    foreach (['2026-10-14 14:30', '2026-10-14 15:00', '2026-10-14 17:00'] as $at) {
        tkPolTravel($at);
        app(RemindPendingWork::class)->handle();
    }

    expect(tkPolMails('staff.task.reminder.awaiting', $this->assistant, OutboundStatus::Failed))->toBe(1);

    config(['mail.default' => $arrayMailer]);
    tkPolTravel('2026-10-15 08:00');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.reminder.awaiting', $this->assistant))->toBe(1)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1);
});

it('logs the permanent failure with ids only — no title, no address', function () {
    $task = tkPolTask($this->matter, $this->assistant, $this->lead, extra: ['title' => 'ZZTIEUDE bí mật']);

    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::AWAITING, $this->assistant->id, 'awaiting@x'))
        ->failed(new RuntimeException('hết lượt thử'));

    $row = Activity::query()->where('event', 'work_reminder_failed')->sole();

    expect($row->subject_id)->toBe($task->id)
        ->and($row->properties['recipient_id'])->toBe($this->assistant->id)
        ->and(json_encode($row->properties))->not->toContain('ZZTIEUDE')
        ->and(json_encode($row->properties))->not->toContain($this->assistant->email)
        ->and(__('activity.events.work_reminder_failed'))->not->toBe('activity.events.work_reminder_failed');
});

// =============================================================================================
// 5. Rà soát Task 2, m8 — "quá hạn" là một định nghĩa: không vụ đã kết thúc
// =============================================================================================

it('leaves overdue tasks of a closed matter out of the dashboard tile, its list, the team page and the reminders', function () {
    $closed = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0093']);
    $closed->addTeamMember($this->assistant, MatterRole::Assistant);
    $onClosed = tkPolTask($closed, $this->assistant, $this->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(3)]);
    $closed->forceFill(['closed_at' => now()])->save();
    $onOpen = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(3)]);

    expect(MyWorkQueueWidget::countsFor($this->assistant)['overdue'])->toBe(1);

    $this->actingAs($this->assistant, 'web');
    Livewire::test(MyWorkQueueWidget::class)->assertSee(__('tasks.dashboard.overdue_hint'));

    $query = [];
    parse_str((string) parse_url(MyWorkQueueWidget::links($this->assistant)['overdue'], PHP_URL_QUERY), $query);
    Livewire::withQueryParams($query)->test(ListTasks::class)
        ->assertCanSeeTableRecords([$onOpen])
        ->assertCanNotSeeTableRecords([$onClosed]);

    $this->actingAs($this->manager, 'web');
    expect(Livewire::test(TeamOverview::class)->instance()->getTableRecords()[$this->assistant->id]['tasksOverdue'])->toBe(1);

    app(RemindPendingWork::class)->handle();

    expect(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.task.reminder.overdue')->where('related_id', $onClosed->id)->count())->toBe(0)
        ->and(OutboundMessage::query()->withoutGlobalScopes()->where('template', 'staff.task.reminder.overdue')->where('related_id', $onOpen->id)->count())->toBe(1);
});

it('keeps the dashboard tile off when the only overdue work is on a closed matter', function () {
    $onClosed = tkPolTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(3)]);
    $this->matter->forceFill(['closed_at' => now()])->save();

    $this->actingAs($this->assistant, 'web');

    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertDontSeeLivewire(MyWorkQueueWidget::class);
    expect($onClosed->fresh()->isOpen())->toBeTrue();
});

// =============================================================================================
// 6. Trang Công việc: từ chối là 404 (SPEC §10.10), không 403
// =============================================================================================

it('answers 404, not 403, when the task list is asked for without a staff account', function () {
    Auth::guard('web')->logout();

    try {
        TaskResource::getEloquentQuery();
        $this->fail('Không ném gì.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
});

// =============================================================================================
// 7. Lý do "Trả lại" là lý do của LẦN trả lại đó, kể cả khi việc đã được giao lại trước khi job chạy
// =============================================================================================

it('carries the decline reason of the event into the bell and the mail even after a reassignment cleared it', function () {
    $task = tkPolTask($this->matter, $this->assistant, $this->lead, extra: ['title' => 'Soạn đơn kháng cáo']);
    Queue::fake([SendTaskNotice::class]);

    tkPolList($this->assistant, 'awaiting')
        ->callTableAction('decline', $task, data: ['reason' => 'Đang quá tải tuần này'])
        ->assertHasNoTableActionErrors();

    tkPolList($this->lead, 'assigned')
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->other->id])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->decline_reason)->toBeNull();

    Mail::fake();
    Queue::pushed(SendTaskNotice::class, fn (SendTaskNotice $job): bool => $job->kind === TaskAlert::DECLINED)
        ->each(fn (SendTaskNotice $job) => $job->handle(app(ResolveStaffRecipients::class)));

    $bell = $this->lead->notifications()->where('type', TaskAlert::class)->sole();

    expect($bell->data['body'])->toContain('Đang quá tải tuần này');
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->lead->email)
        && str_contains($mail->render(), 'Đang quá tải tuần này'));
});

// =============================================================================================
// 8. Theo dõi đội ngũ: "Việc cần giao lại: n" cho người giao lại được, kể cả việc của người đã nghỉ
// =============================================================================================

it('shows "Việc cần giao lại" on Theo dõi đội ngũ with a link to the filtered list, counting work of deactivated people', function () {
    $ofLeaver = tkPolTask($this->matter, $this->other, $this->lead, TaskStatus::Accepted);
    $offTeam = tkPolTask($this->matter, $this->assistant, $this->lead);
    $third = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($third, MatterRole::Assistant);
    $fine = tkPolTask($this->matter, $third, $this->lead);
    $this->other->forceFill(['is_active' => false])->save();
    $this->matter->team()->updateExistingPivot($this->assistant->id, ['role_in_matter' => MatterRole::Observer->value]);
    // Việc của vụ hạn chế mà trưởng phòng không mở được: không vào số của họ, vào số của quản trị viên.
    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $secret->addTeamMember($this->other, MatterRole::Assistant);
    $hidden = tkPolTask($secret, $this->other, $this->lead);

    $url = TaskResource::getUrl('index', ['tab' => 'all', 'filters' => ['needs_reassignment' => ['isActive' => true]]], panel: 'admin');

    $this->actingAs($this->manager, 'web');
    $this->get(TeamOverview::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('tasks.workload.needs_reassignment', ['count' => 2]))
        ->assertSee('color:var(--danger-600);font-weight:600">'.__('tasks.workload.needs_reassignment', ['count' => 2]), false)
        ->assertSee(e($url), false);

    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    Livewire::withQueryParams($query)->test(ListTasks::class)
        ->assertCanSeeTableRecords([$ofLeaver, $offTeam])
        ->assertCanNotSeeTableRecords([$fine, $hidden])
        ->assertCountTableRecords(2);

    $this->actingAs($this->admin, 'web');
    $this->get(TeamOverview::getUrl(panel: 'admin'))->assertSee(__('tasks.workload.needs_reassignment', ['count' => 3]));
});

it('shows "Việc cần giao lại: 0" in plain colour when nothing needs reassigning', function () {
    $this->actingAs($this->manager, 'web');

    $this->get(TeamOverview::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('tasks.workload.needs_reassignment', ['count' => 0]))
        ->assertDontSee('var(--danger-600);font-weight:600">'.__('tasks.workload.needs_reassignment', ['count' => 0]), false);
});

it('computes the figure only for a viewer who may reassign every task they see', function () {
    // Trang Theo dõi đội ngũ chỉ mở cho quản lý/quản trị — cả hai giao lại được; Action trả null cho người khác.
    $this->actingAs($this->lead, 'web');
    $this->get(TeamOverview::getUrl(panel: 'admin'))->assertNotFound();

    expect(app(BuildTaskWorkload::class)->needsReassignment($this->lead))->toBeNull()
        ->and(app(BuildTaskWorkload::class)->needsReassignment($this->manager))->toBe(0)
        ->and(app(SeatsNeedingReassignment::class)->handle($this->lead))->toBeNull()
        ->and(app(SeatsNeedingReassignment::class)->handle($this->manager))->toHaveCount(0);
});

// =============================================================================================
// 9. Bàn giao hàng loạt: một chuông cho cả lô; lời nhắc ghế dừng khi người được giao đã nghỉ
// =============================================================================================

/** "Bàn giao hàng loạt" từ lead hiện tại sang `$newLead`, như trưởng phòng bấm trên màn hình. */
function tkPolBulk(iterable $matters, User $from, User $newLead, User $actor): void
{
    test()->actingAs($actor, 'web');

    Livewire::test(BulkReassign::class)
        ->set('data.lead_lawyer_id', $from->id)
        ->set('data.matter_ids', collect($matters)->pluck('id')->all())
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();
}

it('rings the new lead once for a bulk handover of several matters, not once per matter', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);

    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    $bells = $newLead->notifications()->where('type', MatterAssignmentAlert::class)->get();
    $bell = $bells->first()->data;

    expect($bells)->toHaveCount(1)
        ->and($bell['title'])->toBe(__('tasks.seat.alert.assigned_batch.title', ['count' => 3]))
        ->and($bell['body'])->toContain('Trưởng phòng Hà')
        ->and($bell['body'])->not->toContain('…')
        ->and($bell['actions'][0]['url'])->toBe(TeamMember::getUrl(['user' => $newLead->id], panel: 'admin'));

    foreach ($matters as $matter) {
        expect($bell['body'])->toContain($matter->code);
    }
});

it('lists at most ten codes in the batch bell, then "…"', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matters = Matter::factory()->count(11)->create(['lead_lawyer_id' => $this->lead->id]);

    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    $body = $newLead->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data['body'];
    $codes = $matters->pluck('code')->sort()->values();

    expect($body)->toContain('…')
        ->and($body)->toContain($codes[9])
        ->and($body)->not->toContain($codes[10]);
});

it('rings the plain one-matter bell when a bulk batch has a single matter, or only one still waits when the job runs', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    tkPolBulk([$this->matter], $this->lead, $newLead, $this->manager);

    expect($newLead->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data['title'])
        ->toBe(__('tasks.seat.alert.assigned.title', ['code' => 'VK-2026-DS-0091']));

    // Hai vụ; người nhận đã bấm "Đã nhận hồ sơ" một vụ trước khi job chạy.
    $second = User::factory()->withRole(Role::Lawyer)->create();
    $a = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0101']);
    $b = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0102']);
    Queue::fake([SendReassignmentDigest::class]);
    tkPolBulk([$a, $b], $this->lead, $second, $this->manager);
    $a->team()->updateExistingPivot($second->id, ['accepted_at' => now()]);

    // Chạy hai lần (một lần thử lại): vẫn một chuông.
    Queue::pushed(SendReassignmentDigest::class)->each(fn (SendReassignmentDigest $job) => $job->handle());
    Queue::pushed(SendReassignmentDigest::class)->each(fn (SendReassignmentDigest $job) => $job->handle());

    expect($second->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data['title'])
        ->toBe(__('tasks.seat.alert.assigned.title', ['code' => 'VK-2026-DS-0102']));
});

it('rings nobody for the batch when the new lead was deactivated before the job ran', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    Queue::fake([SendReassignmentDigest::class]);
    tkPolBulk([$this->matter, $other], $this->lead, $newLead, $this->manager);
    $newLead->forceFill(['is_active' => false])->save();

    Queue::pushed(SendReassignmentDigest::class)->each(fn (SendReassignmentDigest $job) => $job->handle());

    expect($newLead->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(0);
});

it('still rings once per matter for a single handover', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($this->manager, 'web');
    Livewire::test(ViewMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('reassignMatter', data: ['new_lead_id' => $newLead->id, 'keep_old_lead_as_associate' => false, 'reason' => 'Đổi người.'])
        ->assertHasNoActionErrors();

    expect($newLead->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data['title'])
        ->toBe(__('tasks.seat.alert.assigned.title', ['code' => 'VK-2026-DS-0091']));
});

it('stops the "chưa nhận hồ sơ" escalation once the person handed the matter has been deactivated', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);

    $this->actingAs($this->manager, 'web');
    Livewire::test(ViewMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('reassignMatter', data: ['new_lead_id' => $newLead->id, 'keep_old_lead_as_associate' => false, 'reason' => 'Đổi người.'])
        ->assertHasNoActionErrors();

    $newLead->forceFill(['is_active' => false])->save();

    tkPolTravel('2026-10-19 10:00');
    app(RemindPendingWork::class)->handle();

    expect($this->manager->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(0)
        ->and(tkPolMails('staff.task.matter.reminder.unaccepted'))->toBe(0);
});

it('keeps the escalation for a person still active who has not accepted', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);

    $this->actingAs($this->manager, 'web');
    Livewire::test(ViewMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('reassignMatter', data: ['new_lead_id' => $newLead->id, 'keep_old_lead_as_associate' => false, 'reason' => 'Đổi người.'])
        ->assertHasNoActionErrors();

    tkPolTravel('2026-10-19 10:00');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(1);
});

// =============================================================================================
// 9b. Phán quyết controller (3): bàn giao hàng loạt gộp vào thư tổng hợp; lời nhắc và lời báo lên quản lý
//     là MỘT bản cho mỗi người, liệt kê các hồ sơ; ghế của người đã nghỉ hiện "cần phân công lại"
// =============================================================================================

/** Chuông lời nhắc ghế của `$user` (không tính chuông "được giao" lúc bàn giao). */
function tkPolSeatReminderBells(User $user): Collection
{
    return $user->notifications()->where('type', MatterAssignmentAlert::class)->get()
        ->filter(fn ($bell): bool => str_contains((string) ($bell->data['viewData']['seat_key'] ?? ''), 'reminder.'))
        ->values();
}

it('folds the bulk handover bell into the reassignment digest job: nothing rings until the digest runs', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    Queue::fake([SendReassignmentDigest::class]);

    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    expect($newLead->notifications()->count())->toBe(0);
    Queue::assertPushed(SendReassignmentDigest::class, 1);

    Queue::pushed(SendReassignmentDigest::class)->each(fn (SendReassignmentDigest $job) => $job->handle());

    expect($newLead->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data['title'])
        ->toBe(__('tasks.seat.alert.assigned_batch.title', ['count' => 3]))
        ->and(tkPolMails('staff.matter_reassigned', $newLead))->toBe(1);
});

it('reminds the new lead of a bulk handover once, listing the matters, not once per matter', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    tkPolTravel('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    $bells = tkPolSeatReminderBells($newLead);
    expect($bells)->toHaveCount(1)
        ->and($bells->first()->data['title'])->toBe(__('tasks.seat.alert.reminder_awaiting_batch.title', ['count' => 3]))
        ->and(tkPolMails('staff.task.matter.reminder.awaiting', $newLead))->toBe(1);

    foreach ($matters as $matter) {
        expect($bells->first()->data['body'])->toContain($matter->code);
    }

    // Lượt sau cùng ngày, và hôm sau: lời nhắc chờ nhận chỉ một lần cho mỗi lần giao.
    foreach (['2026-10-14 14:30', '2026-10-15 09:00'] as $at) {
        tkPolTravel($at);
        app(RemindPendingWork::class)->handle();
    }

    expect(tkPolSeatReminderBells($newLead))->toHaveCount(1)
        ->and(tkPolMails('staff.task.matter.reminder.awaiting', $newLead))->toBe(1);
});

it('lists every matter of the batch in the reminder mail', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    tkPolBulk($matters, $this->lead, $newLead, $this->manager);
    Mail::fake();

    tkPolTravel('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    Mail::assertSent(MatterAssignmentNotice::class, 1);
    Mail::assertSent(MatterAssignmentNotice::class, function (MatterAssignmentNotice $mail) use ($matters, $newLead): bool {
        $html = $mail->render();

        return $mail->hasTo($newLead->email)
            && $matters->every(fn (Matter $matter): bool => str_contains($html, $matter->code));
    });
});

it('escalates a bulk handover nobody accepted as one notice per manager, listing the matters', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    tkPolTravel('2026-10-19 10:00');
    app(RemindPendingWork::class)->handle();

    $bells = tkPolSeatReminderBells($this->manager);
    expect($bells)->toHaveCount(1)
        ->and($bells->first()->data['title'])->toBe(__('tasks.seat.alert.reminder_unaccepted_batch.title', ['count' => 3]))
        ->and($bells->first()->data['body'])->toContain('Luật sư Bình')
        ->and(tkPolMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(1);

    foreach ($matters as $matter) {
        expect($bells->first()->data['body'])->toContain($matter->code);
    }

    tkPolTravel('2026-10-19 10:30');
    app(RemindPendingWork::class)->handle();

    expect(tkPolSeatReminderBells($this->manager))->toHaveCount(1)
        ->and(tkPolMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(1);
});

it('re-checks every seat when the folded reminder runs, and falls back to the one-matter form when one is left', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $a = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0201']);
    $b = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0202']);
    tkPolBulk([$a, $b], $this->lead, $newLead, $this->manager);

    tkPolTravel('2026-10-14 14:00');
    Queue::fake([SendSeatReminderDigest::class]);
    app(RemindPendingWork::class)->handle();
    Queue::assertPushed(SendSeatReminderDigest::class, 1);

    // Người nhận bấm "Đã nhận hồ sơ" một hồ sơ trước khi job chạy.
    $a->team()->updateExistingPivot($newLead->id, ['accepted_at' => now()]);
    Queue::pushed(SendSeatReminderDigest::class)->each(fn (SendSeatReminderDigest $job) => $job->handle(app(ResolveStaffRecipients::class)));

    $bells = tkPolSeatReminderBells($newLead);
    expect($bells)->toHaveCount(1)
        ->and($bells->first()->data['title'])->toBe(__('tasks.seat.alert.reminder_awaiting.title', ['code' => 'VK-2026-DS-0202']))
        ->and(tkPolMails('staff.task.matter.reminder.awaiting', $newLead))->toBe(1);
});

it('tries a failing folded reminder once per seat per day, then again the next day', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    tkPolBulk($matters, $this->lead, $newLead, $this->manager);
    $arrayMailer = config('mail.default');
    tkPolBreakMail();

    tkPolTravel('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.matter.reminder.awaiting', $newLead, OutboundStatus::Failed))->toBe(1)
        ->and(Activity::query()->where('event', 'work_reminder_failed')->count())->toBe(3);

    tkPolTravel('2026-10-14 14:30');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.matter.reminder.awaiting', $newLead, OutboundStatus::Failed))->toBe(1);

    config(['mail.default' => $arrayMailer]);
    tkPolTravel('2026-10-15 08:00');
    app(RemindPendingWork::class)->handle();

    expect(tkPolMails('staff.task.matter.reminder.awaiting', $newLead))->toBe(1)
        ->and(tkPolSeatReminderBells($newLead))->toHaveCount(1);
});

it('shows a seat whose holder was deactivated as needing reassignment on the team tab', function () {
    $helper = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Hoa']);
    $this->matter->addTeamMember($helper, MatterRole::Assistant, $this->lead);
    $waiting = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Quế']);
    $this->matter->addTeamMember($waiting, MatterRole::Assistant, $this->lead);
    // Ghế đã "Trả lại" của một người rồi nghỉ việc vẫn hiện "Đã trả lại" (chỉ ghế CHỜ NHẬN đổi cách hiện).
    $returned = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Đào']);
    $this->matter->addTeamMember($returned, MatterRole::Assistant, $this->lead);
    $this->matter->team()->updateExistingPivot($returned->id, ['declined_at' => now(), 'decline_reason' => 'Xung đột lợi ích']);
    $helper->forceFill(['is_active' => false])->save();
    $returned->forceFill(['is_active' => false])->save();

    $this->actingAs($this->lead, 'web');
    Livewire::test(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->assertTableColumnStateSet('assignment', __('tasks.seat.needs_reassignment'), $helper)
        ->assertTableColumnStateSet('assignment', __('tasks.seat.status.pending'), $waiting)
        ->assertTableColumnStateSet('assignment', __('tasks.seat.status.declined'), $returned)
        ->assertSee(__('tasks.seat.needs_reassignment_note'));
});

it('sends nothing when the folded escalation runs after its recipient was deactivated', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matters = collect([$this->matter, ...Matter::factory()->count(2)->create(['lead_lawyer_id' => $this->lead->id])]);
    tkPolBulk($matters, $this->lead, $newLead, $this->manager);

    tkPolTravel('2026-10-19 10:00');
    Queue::fake([SendSeatReminderDigest::class]);
    app(RemindPendingWork::class)->handle();

    $escalations = Queue::pushed(SendSeatReminderDigest::class, fn (SendSeatReminderDigest $job): bool => $job->kind === RemindPendingWork::UNACCEPTED && $job->recipientId === $this->manager->id);
    expect($escalations)->toHaveCount(1);

    $this->manager->forceFill(['is_active' => false])->save();
    $escalations->each(fn (SendSeatReminderDigest $job) => $job->handle(app(ResolveStaffRecipients::class)));

    expect(tkPolSeatReminderBells($this->manager))->toHaveCount(0)
        ->and(tkPolMails('staff.task.matter.reminder.unaccepted', $this->manager))->toBe(0);
});

it('shows "Hồ sơ cần phân công lại" on Theo dõi đội ngũ for seats whose holder can no longer accept', function () {
    $helper = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Hoa']);
    $this->matter->addTeamMember($helper, MatterRole::Assistant, $this->lead);
    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-HS-0777']);
    $secret->addTeamMember($helper, MatterRole::Assistant, $this->lead);
    $fine = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($fine, MatterRole::Assistant, $this->lead);
    $helper->forceFill(['is_active' => false])->save();

    $this->actingAs($this->manager, 'web');
    $this->get(TeamOverview::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('color:var(--danger-600);font-weight:600">'.__('tasks.workload.seats_needing_reassignment', ['count' => 1]), false)
        ->assertSee('VK-2026-DS-0091')
        ->assertSee(e(SeatInbox::teamTabUrl($this->matter->id)), false)
        ->assertDontSee('VK-2026-HS-0777');

    $this->actingAs($this->admin, 'web');
    $this->get(TeamOverview::getUrl(panel: 'admin'))
        ->assertSee(__('tasks.workload.seats_needing_reassignment', ['count' => 2]));
});
