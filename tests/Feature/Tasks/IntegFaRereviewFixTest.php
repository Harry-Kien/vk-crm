<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Performance\BuildTaskPerformance;
use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\CancelTask;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\Tasks\MyActiveTasksWidget;
use App\Jobs\SendWorkReminder;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Performance\PerformancePeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/**
 * Gộp integ-fa — rà soát lại vòng sửa chéo (2026-10-18):
 *
 *  1. Việc lead mới đã TRẢ LẠI lead cũ trước khi bàn giao: sau bàn giao người giao = người nhận = lead mới, việc vẫn
 *     "Bị trả lại". Nó phải ở "Tôi đã giao" của lead mới (giao người khác hay huỷ), và ô "Việc tôi giao bị trả lại"
 *     đếm đúng số dòng đường dẫn của nó mở ra.
 *  2. Việc riêng quá hạn mà người giữ không còn làm được việc: luật sư phụ trách nhận lời báo quá hạn thay (như
 *     "sắp đến hạn"), không bao giờ trưởng phòng.
 *  3. Huỷ rồi khôi phục một việc đã trễ hạn không đưa nó ra khỏi tỉ lệ "Việc hoàn thành đúng hạn".
 *  4. "Chờ tôi duyệt": cổng duyệt hỏi mỗi văn bản một lần trong một request.
 *
 * Hàm mang tiền tố `ifr`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    ifrTravel('2026-10-12 09:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Viên']);
    $this->head = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->oldLead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cũ']);
    $this->newLead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $this->assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'IFR-2026-01']);
    $this->matter->addTeamMember($this->newLead, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function ifrTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function ifrTask(Matter $matter, User $holder, User $assigner, TaskStatus $status = TaskStatus::Accepted, array $extra = []): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'title' => 'Việc '.fake()->unique()->numberBetween(1, 99999),
        'assignee_id' => $holder->id,
        'assigned_by' => $assigner->id,
        ...$extra,
    ]);
}

function ifrBulkHandover(User $actor, User $from, array $matters, User $to): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $from->id)
        ->set('data.matter_ids', collect($matters)->pluck('id')->all())
        ->set('data.new_lead_id', $to->id)
        ->set('data.reason', 'Luật sư cũ nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();
}

/** Đúng danh sách mà ô "Việc tôi giao bị trả lại" mở ra: tab "Tôi đã giao", lọc "Bị trả lại". */
function ifrReturnedList(User $viewer): Testable
{
    test()->actingAs($viewer, 'web');

    return test()->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->filterTable('status', [TaskStatus::Declined->value]);
}

// =============================================================================================
// 1. VIỆC LEAD MỚI ĐÃ TRẢ LẠI TRƯỚC KHI BÀN GIAO
// =============================================================================================

/**
 * Mutation probes: bỏ ngoại lệ "Bị trả lại" khỏi `Task::scopeAssignedBy()` → đỏ (không dòng nào); coi việc bị trả lại
 * là việc riêng trong `transferAssigner()` → đỏ (`own_task`, không vào danh sách theo dõi).
 */
it('keeps a task the new lead had returned before the handover in their "Tôi đã giao", counted by the tile as the rows behind its link', function () {
    $returned = ifrTask($this->matter, $this->newLead, $this->oldLead, TaskStatus::Declined, [
        'title' => 'Soạn đơn khởi kiện', 'declined_at' => now(), 'decline_reason' => 'Đang quá tải.',
    ]);

    ifrBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead);

    expect($returned->fresh())
        ->assigned_by->toBe($this->newLead->id)
        ->assignee_id->toBe($this->newLead->id)
        ->status->toBe(TaskStatus::Declined);

    $row = Activity::query()->where('event', 'task_assigner_changed')->where('subject_id', $returned->id)->sole();
    expect($row->properties['own_task'] ?? null)->toBeNull();

    $count = MyWorkQueueWidget::countsFor($this->newLead)['returned'];
    expect($count)->toBe(1);

    ifrReturnedList($this->newLead)
        ->assertCanSeeTableRecords([$returned])
        ->assertCountTableRecords($count)
        // Lead mới quyết: giao người khác (hay huỷ) — bằng quyền người giao.
        ->assertTableActionVisible('cancel', $returned)
        ->callTableAction('reassign', $returned, data: ['assignee_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    expect($returned->fresh())
        ->assignee_id->toBe($this->assistant->id)
        ->status->toBe(TaskStatus::Pending)
        ->and(MyWorkQueueWidget::countsFor($this->newLead)['returned'])->toBe(0);
});

it('keeps the new lead\'s own held tasks out of "Tôi đã giao" — only a returned one is theirs to hand on', function () {
    $held = ifrTask($this->matter, $this->newLead, $this->oldLead, TaskStatus::Accepted, ['title' => 'Việc lead mới đang làm']);
    $returned = ifrTask($this->matter, $this->newLead, $this->oldLead, TaskStatus::Declined, ['title' => 'Việc lead mới đã trả']);

    ifrBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead);

    $this->actingAs($this->newLead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->assertCanSeeTableRecords([$returned])
        ->assertCanNotSeeTableRecords([$held]);
});

/**
 * Đường cũ của TaskFinalFixTest ("trả lại SAU bàn giao"), nay với người khác lead mới: việc lead cũ giao cho trợ lý,
 * trợ lý trả lại sau bàn giao → ở "Tôi đã giao" của lead mới, ô đếm đúng số dòng, không "Cần giao lại".
 */
it('puts a task returned after the handover in the new lead\'s "Tôi đã giao", tile count equal to the rows', function () {
    $given = ifrTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, ['title' => 'Lập bảng kê']);

    ifrBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead);

    $this->actingAs($this->assistant, 'web');
    $this->livewire(MyActiveTasksWidget::class)
        ->callTableAction('decline', $given, data: ['reason' => 'Không đủ hồ sơ để làm.'])
        ->assertHasNoTableActionErrors();

    expect($given->fresh()->status)->toBe(TaskStatus::Declined);

    $count = MyWorkQueueWidget::countsFor($this->newLead)['returned'];
    expect($count)->toBe(1);

    ifrReturnedList($this->newLead)->assertCanSeeTableRecords([$given])->assertCountTableRecords($count);

    $this->actingAs($this->head, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->filterTable('needs_reassignment', true)
        ->assertCanNotSeeTableRecords([$given]);
});

// =============================================================================================
// 2. VIỆC RIÊNG QUÁ HẠN MÀ NGƯỜI GIỮ KHÔNG CÒN LÀM ĐƯỢC
// =============================================================================================

function ifrDisable(string $how, User $holder, Matter $matter): void
{
    match ($how) {
        'suspended' => $holder->forceFill(['is_active' => false])->save(),
        'observer' => $matter->team()->updateExistingPivot($holder->id, ['role_in_matter' => MatterRole::Observer->value]),
    };
}

/** Mutation probe: lượt chọn lại bỏ OVERDUE_ASSIGNER cho MỌI việc riêng → đỏ (luật sư phụ trách không nhận gì). */
it('reminds the lead, never a head, about an overdue own task whose holder can no longer work on it', function (string $how) {
    ifrTask($this->matter, $this->assistant, $this->assistant, TaskStatus::Accepted, ['due_date' => '2026-10-08']);
    ifrDisable($how, $this->assistant, $this->matter);

    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(1);

    expect($this->oldLead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1)
        ->and($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and($this->head->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and($this->admin->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0);
})->with(['suspended', 'observer']);

/** Mutation probe: lần hỏi lại lúc gửi lại bỏ OVERDUE_ASSIGNER cho MỌI việc riêng → đỏ. */
it('still sends, at sending time, the lead\'s overdue reminder for an own task whose holder can no longer work', function () {
    $task = ifrTask($this->matter, $this->assistant, $this->assistant, TaskStatus::Accepted, ['due_date' => '2026-10-08']);
    ifrDisable('suspended', $this->assistant, $this->matter);

    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::OVERDUE_ASSIGNER, $this->oldLead->id, RemindPendingWork::overdueKey()))
        ->handle(app(ResolveStaffRecipients::class));

    expect($this->oldLead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1);

    // Người giữ làm lại được việc trước khi lời báo thứ hai được gửi: không gửi.
    $this->assistant->forceFill(['is_active' => true])->save();
    $other = ifrTask($this->matter, $this->assistant, $this->assistant, TaskStatus::Accepted, ['due_date' => '2026-10-08']);
    (new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $other->id, RemindPendingWork::OVERDUE_ASSIGNER, $this->oldLead->id, RemindPendingWork::overdueKey()))
        ->handle(app(ResolveStaffRecipients::class));

    expect($this->oldLead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1);
});

// =============================================================================================
// 3. HUỶ RỒI KHÔI PHỤC KHÔNG ĐƯA VIỆC TRỄ RA KHỎI TỈ LỆ ĐÚNG HẠN
// =============================================================================================

function ifrCancelAndRestore(Task $task, User $actor): Task
{
    app(CancelTask::class)->handle($task, $actor, 'Huỷ nhầm.');

    test()->actingAs($actor, 'web');
    test()->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh(), data: ['reason' => 'Vẫn cần làm.'])
        ->assertHasNoTableActionErrors();

    return $task->fresh();
}

/** Mutation probe: `RestoreTask` lại đặt `assigned_at = now()` cho việc đã trễ hạn → đỏ. */
it('keeps the original giving time when restoring a task already past its due date, so it stays in the on-time rate', function () {
    $assignedAt = CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Ho_Chi_Minh');
    $task = ifrTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, ['assigned_at' => $assignedAt, 'due_date' => '2026-10-09']);

    $restored = ifrCancelAndRestore($task, $this->oldLead);

    expect($restored->status)->toBe(TaskStatus::Pending)
        ->and($restored->assigned_at->equalTo($assignedAt))->toBeTrue()
        ->and(Task::query()->assignedByDueDate()->whereKey($task->id)->exists())->toBeTrue();

    $period = PerformancePeriod::fromFilters(['period' => PerformancePeriod::THIS_MONTH]);
    $ratio = app(BuildTaskPerformance::class)->handle($this->head, collect([$this->assistant]), $period)['rows'][$this->assistant->id]['taskOnTime'];

    expect($ratio->denominator)->toBe(1)
        ->and($ratio->numerator)->toBe(0);
});

/**
 * Mutation probes: `RestoreTask` giữ mốc giao cũ cả khi hạn còn ở phía trước, hay coi "không hạn" là hạn đã qua → đỏ
 * (đồng hồ "chờ nhận" không chạy lại).
 */
it('restarts the acceptance clock when restoring a task whose due date is still ahead, or that has none', function (?string $due) {
    $task = ifrTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, [
        'assigned_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Ho_Chi_Minh'), 'due_date' => $due,
    ]);

    $restored = ifrCancelAndRestore($task, $this->oldLead);

    expect($restored->assigned_at->equalTo(now()))->toBeTrue();
})->with(['due today' => '2026-10-12', 'due ahead' => '2026-10-20', 'no due date' => null]);

// =============================================================================================
// 4. "CHỜ TÔI DUYỆT": CỔNG DUYỆT MỘT LẦN MỖI VĂN BẢN MỖI REQUEST
// =============================================================================================

/** Mutation probe: bỏ bộ nhớ của `DocumentLibrary` → đỏ (bộ lọc áp hai lần, cổng hỏi hai lần mỗi văn bản). */
it('asks the approval gate once per document per request on the "Chờ tôi duyệt" filter and the dashboard tile', function () {
    $docs = collect(['Đơn khởi kiện', 'Bản tự khai'])->map(fn (string $title): Document => Document::factory()->create([
        'matter_id' => $this->matter->id, 'group' => DocumentGroup::Issued, 'status' => DocumentStatus::PendingApproval,
        'title' => $title, 'client_can_view' => false, 'client_can_download' => false,
    ]));

    $asked = [];
    Gate::after(function ($user, string $ability, $result, array $arguments) use (&$asked): void {
        if ($ability === 'publish' && ($arguments[0] ?? null) instanceof Document) {
            $asked[] = $arguments[0]->getKey();
        }
    });

    $this->actingAs($this->oldLead, 'web');

    $page = $this->livewire(DocumentLibrary::class);
    $asked = [];
    $page->filterTable(DocumentLibrary::AWAITING_MY_APPROVAL, true)->assertCanSeeTableRecords($docs);

    expect(array_count_values($asked))->toBe($docs->mapWithKeys(fn (Document $doc): array => [$doc->id => 1])->all());

    $asked = [];
    $this->livewire(MyWorkQueueWidget::class)->assertSee(__('tasks.dashboard.approvals'));

    expect(array_count_values($asked))->toBe($docs->mapWithKeys(fn (Document $doc): array => [$doc->id => 1])->all());
});
