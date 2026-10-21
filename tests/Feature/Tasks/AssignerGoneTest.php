<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\MoveTasksOnHandover;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Jobs\SendReassignmentDigest;
use App\Jobs\SendTaskNotice;
use App\Mail\Staff\MatterReassigned;
use App\Mail\Staff\TaskNotice;
use App\Mail\Staff\TaskReminder;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TaskReminderAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 2, vòng sửa 1 (I1) — việc mà NGƯỜI GIAO đã rời đi (nghỉ việc, rời vụ, bàn giao vụ):
 *  - lời báo "bị trả lại" và hai lời nhắc cho người giao (chưa được nhận, quá hạn) tới luật sư phụ trách
 *    hiện tại bằng câu chữ của NGƯỜI NHẬN THAY ("do :assigner giao"), trỏ về tab "Công việc" của vụ — không
 *    phải "Việc anh/chị giao" và tab "Tôi đã giao" nơi việc đó không có;
 *  - bàn giao vụ (lead cũ rời đội ngũ): việc còn mở lead cũ đã giao trên vụ đó đổi người giao sang lead
 *    mới (ghi nhật ký), nên chúng ở "Tôi đã giao", ô "Việc tôi giao bị trả lại" của lead mới và thư tổng hợp;
 *  - việc "Bị trả lại" mà người giao đã nghỉ hiện "Cần giao lại".
 * Màn hình qua Livewire; lịch nhắc qua chính lượt lịch.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Phụ Trách']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cộng Sự Cũ']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0099']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function tkAgDecline(Task $task, User $by, string $reason = 'Đang quá tải.'): void
{
    test()->actingAs($by, 'web');

    test()->livewire(TasksAwaitingMeWidget::class)
        ->callTableAction('decline', $task, data: ['reason' => $reason])
        ->assertHasNoTableActionErrors();
}

function tkAgHandOver(Matter $matter, User $actor, User $newLead, bool $keep = false): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => $keep,
            'reason' => 'Luật sư cũ nghỉ việc.',
        ])
        ->assertHasNoActionErrors();
}

/** @return list<array<string, mixed>> */
function tkAgBells(User $user, string $type): array
{
    return $user->notifications()->where('type', $type)->get()->pluck('data')->all();
}

function tkAgTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

// =========================================================================================
// (a) Câu chữ và đường dẫn của người nhận thay.
// =========================================================================================

it('tells the current lead, in their own words and with a link to the matter\'s task tab, that a task the departed assigner gave was returned', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    tkAgDecline($task, $this->assistant);

    $bells = tkAgBells($this->lead, TaskAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('tasks.alert.declined_on_behalf.title'))
        ->and($bells[0]['body'])->toContain('Luật sư Cộng Sự Cũ')
        ->and($bells[0]['body'])->toContain('Đang quá tải.')
        ->and($bells[0]['body'])->toContain('VK-2026-DS-0099')
        ->and($bells[0]['actions'][0]['url'])->toBe(TaskColumns::matterTabUrl($this->matter->id));

    Mail::assertSent(TaskNotice::class, function (TaskNotice $mail): bool {
        $html = $mail->render();

        return $mail->hasTo($this->lead->email)
            && $mail->envelope()->subject === __('tasks.email.subject.declined_on_behalf', ['name' => 'Trợ lý Lan'])
            && str_contains($html, e(__('tasks.email.headline.declined_on_behalf', ['name' => 'Trợ lý Lan', 'assigner' => 'Luật sư Cộng Sự Cũ'])))
            && str_contains($html, e(TaskColumns::matterTabUrl($this->matter->id)))
            && ! str_contains($html, e(TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin')));
    });
});

it('keeps the assigner\'s own words and the "Tôi đã giao" link when the assigner is still on the matter', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);

    tkAgDecline($task, $this->assistant);

    $bells = tkAgBells($this->associate, TaskAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('tasks.alert.declined.title'))
        ->and($bells[0]['actions'][0]['url'])->toBe(TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin'))
        ->and(tkAgBells($this->lead, TaskAlert::class))->toBe([]);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email)
        && $mail->envelope()->subject === __('tasks.email.subject.declined', ['name' => 'Trợ lý Lan']));
});

// Kiểm toán 2026-10-21 (Q3): hai lời nhắc cùng loại cho cùng một người trong một lượt là MỘT lời gộp — việc chính luật sư
// phụ trách giao (câu của người giao, tab "Tôi đã giao") tách sang ca riêng để test này vẫn ghim lời nhắc một việc.
it('words the two assigner reminders for the current lead standing in, and links them to the matter\'s task tab', function () {
    tkAgTravel('2026-10-12 09:00');

    $unaccepted = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Nộp án phí', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id, 'assigned_at' => now(),
    ]);
    $overdue = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp bản khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
        'assigned_at' => now(), 'due_date' => '2026-10-16',
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    // 48 giờ làm việc sau 09:00 Thứ Hai 12/10 là 09:30 Thứ Hai 19/10; hạn 16/10 đã qua.
    tkAgTravel('2026-10-19 09:30');
    app(RemindPendingWork::class)->handle();

    $bells = collect(tkAgBells($this->lead, TaskReminderAlert::class))->keyBy(fn (array $bell): int => $bell['viewData']['task_id']);
    $matterTab = TaskColumns::matterTabUrl($this->matter->id);

    expect($bells[$unaccepted->id]['title'])->toBe(__('tasks.reminder.alert.unaccepted_on_behalf.title'))
        ->and($bells[$unaccepted->id]['body'])->toContain('Luật sư Cộng Sự Cũ')
        ->and($bells[$unaccepted->id]['actions'][0]['url'])->toBe($matterTab)
        ->and($bells[$overdue->id]['title'])->toBe(__('tasks.reminder.alert.overdue_assigner_on_behalf.title'))
        ->and($bells[$overdue->id]['body'])->toContain('Luật sư Cộng Sự Cũ')
        ->and($bells[$overdue->id]['actions'][0]['url'])->toBe($matterTab);

    Mail::assertSent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->lead->email)
        && $mail->task->is($unaccepted)
        && $mail->envelope()->subject === __('tasks.reminder.email.subject.unaccepted_on_behalf')
        && str_contains($mail->render(), e($matterTab)));
    Mail::assertSent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->lead->email)
        && $mail->task->is($overdue)
        && $mail->envelope()->subject === __('tasks.reminder.email.subject.overdue_assigner_on_behalf'));
});

it('words the reminder of a task the lead assigned themselves as the assigner\'s, linked to "Tôi đã giao"', function () {
    tkAgTravel('2026-10-12 09:00');

    $own = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(),
    ]);

    tkAgTravel('2026-10-19 09:30');
    app(RemindPendingWork::class)->handle();

    $bell = collect(tkAgBells($this->lead, TaskReminderAlert::class))->sole();

    expect($bell['viewData']['task_id'])->toBe($own->id)
        ->and($bell['title'])->toBe(__('tasks.reminder.alert.unaccepted.title'))
        ->and($bell['actions'][0]['url'])->toBe(TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin'));

    Mail::assertSent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->lead->email)
        && $mail->task->is($own)
        && $mail->envelope()->subject === __('tasks.reminder.email.subject.unaccepted'));
});

/** Kiểm toán 2026-10-21 (Q3): lời gộp trộn việc nhận thay và việc của chính mình — dòng nhận thay ghi "do … giao", nút về tab "Tất cả". */
it('folds an on-behalf and an own unaccepted task into one digest that names the absent assigner and opens "Tất cả"', function () {
    tkAgTravel('2026-10-12 09:00');

    Task::factory()->forMatter($this->matter)->create([
        'title' => 'Nộp án phí', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id, 'assigned_at' => now(),
    ]);
    Task::factory()->forMatter($this->matter)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'assigned_at' => now(),
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    tkAgTravel('2026-10-19 09:30');
    app(RemindPendingWork::class)->handle();

    $bell = collect(tkAgBells($this->lead, TaskReminderAlert::class))->sole();

    expect($bell['title'])->toBe(__('tasks.reminder_digest.alert.unaccepted.title', ['count' => 2]))
        ->and($bell['body'])->toContain('Nộp án phí')->toContain('Gặp nhân chứng')
        ->toContain(__('tasks.reminder_digest.by', ['assigner' => 'Luật sư Cộng Sự Cũ']))
        ->and($bell['actions'][0]['url'])->toBe(TaskResource::getUrl('index', ['tab' => 'all'], panel: 'admin'));

    expect(Mail::sent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->lead->email)))->toHaveCount(1);
    Mail::assertSent(TaskReminder::class, fn (TaskReminder $mail): bool => $mail->hasTo($this->lead->email)
        && $mail->envelope()->subject === __('tasks.reminder_digest.email.subject.unaccepted', ['count' => 2]));
});

// =========================================================================================
// (b) Bàn giao vụ: lead mới thành người giao của việc lead cũ đã giao.
// =========================================================================================

it('makes the new lead the assigner of the open tasks the departing lead gave on the matter, with a log line', function () {
    $newLead = $this->associate;
    $pending = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn bản tự khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    $accepted = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp đơn khởi kiện', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    $declined = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    $completed = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Việc đã xong', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    // Việc lead cũ giao cho chính lead mới: thành việc RIÊNG của lead mới (gộp integ-fa, rà soát chéo mục 1) — không ở
    // "Tôi đã giao", không trong danh sách "theo dõi" của thư tổng hợp.
    $toNewLead = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của lead mới', 'assignee_id' => $newLead->id, 'assigned_by' => $this->lead->id,
    ]);
    // Việc của vụ khác: không thuộc lần bàn giao này.
    $elsewhere = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $elsewhere->addTeamMember($this->assistant, MatterRole::Assistant);
    $other = Task::factory()->forMatter($elsewhere)->create([
        'title' => 'Việc vụ khác', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);

    tkAgHandOver($this->matter, $this->manager, $newLead);

    foreach ([$pending, $accepted, $declined] as $task) {
        $row = Activity::query()->where('event', 'task_assigner_changed')->where('subject_id', $task->id)->sole();

        expect($task->fresh()->assigned_by)->toBe($newLead->id)
            ->and($row->properties['reason'])->toBe(MoveTasksOnHandover::HANDOVER_REASON)
            ->and($row->properties['previous_assigner_id'])->toBe($this->lead->id)
            ->and($row->properties['assigner_id'])->toBe($newLead->id)
            ->and($row->properties['matter_id'])->toBe($this->matter->id)
            ->and($row->causer_id)->toBe($this->manager->id);
    }

    // Trạng thái, người giữ việc và lý do trả lại giữ nguyên.
    expect($pending->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($accepted->fresh()->status)->toBe(TaskStatus::Accepted)
        ->and($declined->fresh()->status)->toBe(TaskStatus::Declined)
        ->and($declined->fresh()->decline_reason)->toBe($declined->decline_reason)
        ->and($declined->fresh()->assignee_id)->toBe($this->assistant->id)
        ->and($completed->fresh()->assigned_by)->toBe($this->lead->id)
        ->and($toNewLead->fresh()->assigned_by)->toBe($newLead->id)
        ->and(Activity::query()->where('event', 'task_assigner_changed')->where('subject_id', $toNewLead->id)->sole()->properties['own_task'] ?? null)->toBeTrue()
        ->and($other->fresh()->assigned_by)->toBe($this->lead->id)
        ->and(Activity::query()->where('event', 'task_assigner_changed')->count())->toBe(4);

    $this->actingAs($newLead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->assertCanSeeTableRecords([$pending, $accepted, $declined])
        ->assertCanNotSeeTableRecords([$completed, $toNewLead, $other]);

    expect(MyWorkQueueWidget::countsFor($newLead)['returned'])->toBe(1);

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($newLead): bool {
        $html = $mail->render();

        return $mail->hasTo($newLead->email)
            && str_contains($html, e(__('tasks.handover.email.followed_heading')))
            && str_contains($html, 'Gặp nhân chứng')
            && str_contains($html, 'Nộp đơn khởi kiện')
            && ! str_contains($html, 'Việc đã xong');
    });
});

it('does the same on "Bàn giao hàng loạt" and lists the tasks in the one digest of the batch', function () {
    $newLead = $this->associate;
    $declined = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    $second = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $second->addTeamMember($this->assistant, MatterRole::Assistant);
    $onSecond = Task::factory()->forMatter($second)->create([
        'title' => 'Soạn đơn vụ thứ hai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);

    $this->actingAs($this->manager, 'web');
    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $this->lead->id)
        ->set('data.matter_ids', [$this->matter->id, $second->id])
        ->set('data.new_lead_id', $newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($declined->fresh()->assigned_by)->toBe($newLead->id)
        ->and($onSecond->fresh()->assigned_by)->toBe($newLead->id);

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($newLead): bool {
        $html = $mail->render();

        return $mail->hasTo($newLead->email)
            && str_contains($html, 'Gặp nhân chứng')
            && str_contains($html, 'Soạn đơn vụ thứ hai');
    });
});

it('rebuilds the followed-task list when the digest is sent: a task finished or handed to someone else meanwhile is left out', function () {
    $finished = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc xong sau bàn giao', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $otherAssigner = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc người khác theo dõi', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id,
    ]);
    $kept = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Việc vẫn do lead mới theo dõi', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    // Một id của vụ khác lọt vào khối này: không in dưới vụ này.
    $elsewhere = Matter::factory()->create(['lead_lawyer_id' => $this->associate->id]);
    $elsewhere->addTeamMember($this->assistant, MatterRole::Assistant);
    $stray = Task::factory()->forMatter($elsewhere)->create([
        'title' => 'Việc của vụ khác', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $finished->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now()])->save();

    (new SendReassignmentDigest($this->associate->id, [
        $this->matter->id => [
            'deadline_ids' => [],
            'client_request_ids' => [],
            'followed_task_ids' => [$finished->id, $otherAssigner->id, $kept->id, $stray->id],
            'reason' => 'Luật sư cũ nghỉ việc.',
        ],
    ]))->handle();

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'Việc vẫn do lead mới theo dõi')
            && str_contains($html, e(TaskStatus::Declined->label()))
            && ! str_contains($html, 'Việc xong sau bàn giao')
            && ! str_contains($html, 'Việc người khác theo dõi')
            && ! str_contains($html, 'Việc của vụ khác');
    });
});

it('leaves the assigner alone when the old lead stays on as associate', function () {
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);

    tkAgHandOver($this->matter, $this->manager, $this->associate, keep: true);

    expect($task->fresh()->assigned_by)->toBe($this->lead->id)
        ->and(Activity::query()->where('event', 'task_assigner_changed')->exists())->toBeFalse();
});

it('sends a later decline of such a task to the new lead as its assigner', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn bản tự khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);

    tkAgHandOver($this->matter, $this->manager, $this->associate);
    tkAgDecline($task, $this->assistant);

    $bells = tkAgBells($this->associate, TaskAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['title'])->toBe(__('tasks.alert.declined.title'))
        ->and($bells[0]['actions'][0]['url'])->toBe(TaskResource::getUrl('index', ['tab' => 'assigned'], panel: 'admin'))
        ->and(tkAgBells($this->lead, TaskAlert::class))->toBe([]);
});

it('delivers a decline queued for the old lead before the handover to the new lead once it runs', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn bản tự khai', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);

    Queue::fake();
    tkAgDecline($task, $this->assistant);
    $event = Activity::query()->where('event', 'task_declined')->where('subject_id', $task->id)->sole();
    Queue::assertPushed(SendTaskNotice::class, fn (SendTaskNotice $job): bool => $job->recipientId === $this->lead->id);

    tkAgHandOver($this->matter, $this->manager, $this->associate);
    $this->lead->forceFill(['is_active' => false])->save();

    (new SendTaskNotice($task->id, TaskAlert::DECLINED, $this->lead->id, $event->id))->handle(app(ResolveStaffRecipients::class));

    expect(tkAgBells($this->associate, TaskAlert::class))->toHaveCount(1)
        ->and(tkAgBells($this->associate, TaskAlert::class)[0]['title'])->toBe(__('tasks.alert.declined.title'))
        ->and(tkAgBells($this->lead, TaskAlert::class))->toBe([]);
});

// =========================================================================================
// (b) Việc "Bị trả lại" mà người giao đã nghỉ: "Cần giao lại".
// =========================================================================================

it('flags a returned task whose assigner has left as needing reassignment on the Công việc page', function () {
    $orphan = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Việc mồ côi', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $tracked = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Việc còn người giao', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id,
    ]);
    // Người giao đã nghỉ nhưng việc còn người làm: không cần giao lại.
    $held = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc đang làm', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->assertSee(__('tasks.columns.assigner_gone'))
        ->filterTable('needs_reassignment', true)
        ->assertCanSeeTableRecords([$orphan])
        ->assertCanNotSeeTableRecords([$tracked, $held]);

    expect($orphan->fresh()->load(['assignee', 'assigner' => fn ($q) => $q->withTrashed(), 'matter.team'])->reassignmentReason())->toBe('assigner_gone')
        ->and($held->fresh()->load(['assignee', 'assigner' => fn ($q) => $q->withTrashed(), 'matter.team'])->reassignmentReason())->toBeNull();
});

it('flags a returned task whose assigner was deleted', function () {
    $orphan = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Việc mồ côi', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->delete();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->filterTable('needs_reassignment', true)
        ->assertCanSeeTableRecords([$orphan]);
});

// =========================================================================================
// (d) Gộp làn tk (2026-10-16): người giao đã rời đi VÀ người trả lại chính là luật sư phụ trách — lời báo
//     "bị trả lại" tới người quản lý của vụ (trưởng phòng; quản trị viên với vụ hạn chế), không rơi vào khoảng
//     không.
// =========================================================================================

it('tells the matter\'s manager when the lead returns a task whose assigner has left', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->lead->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    tkAgDecline($task, $this->lead, 'Xung đột lịch xét xử.');

    $bells = tkAgBells($this->manager, TaskAlert::class);

    expect($bells)->toHaveCount(1)
        // Kiểm toán 2026-10-21 (N1): câu của người quản lý hồ sơ, không "hồ sơ anh/chị phụ trách".
        ->and($bells[0]['title'])->toBe(__('tasks.managed.alert.declined.title', ['code' => $this->matter->code]))
        ->and($bells[0]['body'])->toContain('Xung đột lịch xét xử.')
        ->and(tkAgBells($this->lead, TaskAlert::class))->toBe([]);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->manager->email));
});

it('tells only an admin, never a manager, on a restricted matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->lead->id, 'assigned_by' => $this->associate->id,
    ]);
    $this->associate->forceFill(['is_active' => false])->save();

    tkAgDecline($task, $this->lead);

    expect(tkAgBells($admin, TaskAlert::class))->toHaveCount(1)
        ->and(tkAgBells($this->manager, TaskAlert::class))->toBe([]);
});
