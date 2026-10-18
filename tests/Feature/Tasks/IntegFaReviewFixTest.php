<?php

use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Performance\BuildTaskPerformance;
use App\Actions\Schedule\RemindPendingWork;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Jobs\SendWorkReminder;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\StaffWorkTransferAlert;
use App\Notifications\Staff\TaskReminderAlert;
use App\Notifications\Staff\TeamDepartureAlert;
use App\Support\OpenTaskWork;
use App\Support\Performance\PerformancePeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Gộp integ-fa — rà soát chéo các làn fa1/fa2/fa3 (2026-10-18), các mục về việc nội bộ:
 *
 *  1. Bàn giao xanh trọn thì người phụ trách cũ nghỉ việc được: việc lead mới đang giữ mà lead cũ đã giao, và việc
 *     lead cũ tự giữ (việc riêng), thành việc RIÊNG của lead mới; không nhắc "chưa được nhận" tới mọi trưởng phòng.
 *  2. Các đường chuyển hàng loạt không để người mới nhận một hạn đã qua; tỉ lệ đúng hạn bỏ việc giao sau hạn.
 *  3. Việc riêng (người giao = người giữ) không bao giờ báo "quá hạn" cho người giao, không lên mọi trưởng phòng.
 *  6. Chuông "đã chuyển" cho người giao chỉ là tin: không rơi tới trưởng phòng.
 *  7. Người bị tạm khoá: việc họ giữ là "Cần giao lại", không đồng thời là "quá hạn" của họ.
 *  8. Câu tóm tắt "Chuyển việc nội bộ" không đếm hồ sơ người đó đang phụ trách.
 *  9. Gỡ thành viên trên hồ sơ đã kết thúc: chỉ "Để Cần giao lại".
 * 11. Chữ của công tắc "giữ làm cộng sự" và dòng is_lead khớp điều bàn giao làm.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    ifaTravel('2026-10-12 09:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Viên']);
    $this->head = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->oldLead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cũ']);
    $this->newLead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $this->assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->successor = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Nhận']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'IFA-2026-01']);
    $this->matter->addTeamMember($this->newLead, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function ifaTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function ifaBulkHandover(User $actor, User $from, array $matters, User $to): void
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

function ifaTask(Matter $matter, User $holder, User $assigner, TaskStatus $status = TaskStatus::Accepted, array $extra = []): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'title' => 'Việc '.fake()->unique()->numberBetween(1, 99999),
        'assignee_id' => $holder->id,
        'assigned_by' => $assigner->id,
        ...$extra,
    ]);
}

// =============================================================================================
// 1. BÀN GIAO XANH TRỌN → NGHỈ VIỆC QUA
// =============================================================================================

/** Mutation probe: bỏ nhánh "việc lead mới đang giữ" của `transferAssigner()` → đỏ (lead cũ vẫn còn "đã giao"). */
it('lets the old lead be deactivated after a fully green handover: their own task and what they gave the new lead become the new lead\'s own', function () {
    $own = ifaTask($this->matter, $this->oldLead, $this->oldLead);
    $givenToNew = ifaTask($this->matter, $this->newLead, $this->oldLead, TaskStatus::Pending);
    $givenToAssistant = ifaTask($this->matter, $this->assistant, $this->oldLead);

    ifaBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead);

    expect($own->fresh())
        ->assignee_id->toBe($this->newLead->id)
        ->assigned_by->toBe($this->newLead->id)
        ->status->toBe(TaskStatus::Pending)
        ->and($givenToNew->fresh())
        ->assigned_by->toBe($this->newLead->id)
        ->status->toBe(TaskStatus::Pending)
        ->and($givenToAssistant->fresh()->assigned_by)->toBe($this->newLead->id)
        ->and(OpenTaskWork::forUser($this->oldLead)->isEmpty())->toBeTrue();

    $row = Activity::query()->where('event', 'task_assigner_changed')->where('subject_id', $givenToNew->id)->sole();
    expect($row->properties['own_task'] ?? null)->toBeTrue()
        ->and($row->properties['previous_assigner_id'])->toBe($this->oldLead->id);

    $this->actingAs($this->admin, 'web');
    $this->livewire(EditUser::class, ['record' => $this->oldLead->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->oldLead->fresh()->is_active)->toBeFalse();
});

/** Mutation probe: `forTaskAssigner()` lại rơi tới người quản lý cho việc riêng → đỏ. */
it('never escalates the new lead\'s own waiting tasks to every department head after 48 working hours', function () {
    ifaTask($this->matter, $this->oldLead, $this->oldLead);
    ifaTask($this->matter, $this->newLead, $this->oldLead, TaskStatus::Pending);

    ifaBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead);

    ifaTravel('2026-10-20 10:00');
    app(RemindPendingWork::class)->handle();

    expect($this->head->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and($this->admin->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0);
});

/** Mutation probe: bỏ nhánh nhận thay của `handle()` → đỏ (việc vẫn "Chờ nhận"). */
it('moves the old lead\'s tasks straight to "Đang làm" when the person handing over takes the matter themselves', function () {
    $held = ifaTask($this->matter, $this->oldLead, $this->assistant);

    ifaBulkHandover($this->head, $this->oldLead, [$this->matter], $this->head);

    expect($held->fresh())
        ->assignee_id->toBe($this->head->id)
        ->status->toBe(TaskStatus::Accepted)
        ->accepted_at->not->toBeNull();

    $row = Activity::query()->where('event', 'task_reassigned')->where('subject_id', $held->id)->sole();
    expect($row->properties['to'])->toBe(TaskStatus::Accepted->value)
        ->and($row->properties['taken_over'] ?? null)->toBeTrue();
});

// =============================================================================================
// 2. HẠN ĐÃ QUA KHÔNG ĐI THEO VIỆC CHUYỂN HÀNG LOẠT; TỈ LỆ ĐÚNG HẠN BỎ VIỆC GIAO SAU HẠN
// =============================================================================================

/** Hạn đã qua (người cũ có 4 ngày: giao 2026-10-05, hạn 2026-10-09) → hạn mới hôm nay + 4 ngày. */
function ifaLateTask(Matter $matter, User $holder, User $assigner): Task
{
    return ifaTask($matter, $holder, $assigner, TaskStatus::Accepted, [
        'assigned_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Ho_Chi_Minh'),
        'due_date' => '2026-10-09',
    ]);
}

/** Mutation probe: bỏ `setDueDate` của đường hàng loạt → đỏ. */
it('gives the new holder a fresh due date, the same rule as "Giao lại", on every bulk path', function (string $path) {
    $task = match ($path) {
        'handover' => ifaLateTask($this->matter, $this->oldLead, $this->assistant),
        default => ifaLateTask($this->matter, $this->assistant, $this->oldLead),
    };

    match ($path) {
        'handover' => ifaBulkHandover($this->admin, $this->oldLead, [$this->matter], $this->newLead),
        'transfer' => (function () {
            $this->actingAs($this->admin, 'web');
            $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
                ->set('staff.recipient_id', $this->successor->id)
                ->callAction('transferStaffWork', data: ['reason' => 'Trợ lý nghỉ việc.'])
                ->assertHasNoActionErrors();
        })->call($this),
        'removal' => (function () {
            $this->actingAs($this->oldLead, 'web');
            $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
                ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->newLead->id])
                ->assertHasNoTableActionErrors();
        })->call($this),
    };

    $fresh = $task->fresh();
    expect($fresh->assignee_id)->not->toBe($task->assignee_id)
        ->and($fresh->due_date->toDateString())->toBe('2026-10-16')
        ->and($fresh->isOverdue())->toBeFalse();

    $row = Activity::query()->where('event', 'task_reassigned')->where('subject_id', $task->id)->sole();
    expect($row->properties['due_date_from'])->toBe('2026-10-09')
        ->and($row->properties['due_date_to'])->toBe('2026-10-16');
})->with(['handover', 'transfer', 'removal']);

it('keeps a due date still ahead on a bulk path', function () {
    $task = ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, ['due_date' => '2026-10-20']);

    $this->actingAs($this->oldLead, 'web');
    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->newLead->id])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-20');
});

/** Mutation probe: bỏ vế "giao không sau hạn" khỏi tập của `BuildTaskPerformance` → đỏ. */
it('leaves out of the on-time rate a task its holder received after its due date had passed', function () {
    ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, [
        'assigned_at' => CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Ho_Chi_Minh'),
        'due_date' => '2026-10-06',
    ]);
    ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Completed, [
        'assigned_at' => CarbonImmutable::parse('2026-10-08 10:00', 'Asia/Ho_Chi_Minh'),
        'due_date' => '2026-10-07',
        'completed_at' => CarbonImmutable::parse('2026-10-09 10:00', 'Asia/Ho_Chi_Minh'),
    ]);
    // Đối chứng: giao trước hạn, quá hạn — vẫn vào mẫu.
    ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, [
        'assigned_at' => CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Ho_Chi_Minh'),
        'due_date' => '2026-10-07',
    ]);

    $period = PerformancePeriod::fromFilters(['period' => PerformancePeriod::THIS_MONTH]);
    $ratio = app(BuildTaskPerformance::class)->handle($this->head, collect([$this->assistant]), $period)['rows'][$this->assistant->id]['taskOnTime'];

    expect($ratio->denominator)->toBe(1)
        ->and($ratio->numerator)->toBe(0);
});

// =============================================================================================
// 3. VIỆC RIÊNG: KHÔNG "QUÁ HẠN" CHO NGƯỜI GIAO, KHÔNG LÊN TRƯỞNG PHÒNG
// =============================================================================================

/** Mutation probe: bỏ lần bỏ qua OVERDUE_ASSIGNER cho việc riêng → đỏ (luật sư phụ trách nhận). */
it('sends an overdue own task\'s reminder to its holder only — no assigner reminder, no stand-in, no head', function () {
    ifaTask($this->matter, $this->assistant, $this->assistant, TaskStatus::Accepted, ['due_date' => '2026-10-08']);

    // Lượt chọn xếp đúng MỘT lời nhắc (người giữ) — không xếp rồi mới bỏ lúc gửi.
    expect(app(RemindPendingWork::class)->handle()['queued'])->toBe(1);

    expect($this->assistant->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(1)
        ->and($this->oldLead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0)
        ->and($this->head->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0);
});

/** Mutation probe: bỏ vế "việc riêng" khỏi lần hỏi lại lúc gửi (`taskReminderHolds()`) → đỏ. */
it('drops, at sending time, an assigner overdue reminder for a task that has since become the holder\'s own', function () {
    $task = ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, ['due_date' => '2026-10-08']);
    $job = new SendWorkReminder(RemindPendingWork::SUBJECT_TASK, $task->id, RemindPendingWork::OVERDUE_ASSIGNER, $this->oldLead->id, RemindPendingWork::overdueKey());

    // Giữa lúc xếp và lúc gửi, việc thành việc riêng của người đang giữ.
    $task->forceFill(['assigned_by' => $this->assistant->id])->save();

    $job->handle(app(ResolveStaffRecipients::class));

    expect($this->oldLead->notifications()->where('type', TaskReminderAlert::class)->count())->toBe(0);
});

/** Mutation probe: bỏ vế "việc riêng" khỏi bước người quản lý của `forTaskAssigner()` → đỏ. */
it('never hands a decision about the lead\'s own task to every department head', function () {
    $own = ifaTask($this->matter, $this->oldLead, $this->oldLead);

    expect(app(ResolveStaffRecipients::class)->forTaskAssigner($own->fresh())->all())->toBe([]);

    // Đối chứng: việc của người khác mà người giao đã nghỉ, luật sư phụ trách là người giữ → trưởng phòng quyết.
    $gone = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $orphan = ifaTask($this->matter, $this->oldLead, $gone);

    expect(app(ResolveStaffRecipients::class)->forTaskAssigner($orphan->fresh())->pluck('id')->all())->toContain($this->head->id);
});

// =============================================================================================
// 6. CHUÔNG "ĐÃ CHUYỂN" CHO NGƯỜI GIAO LÀ TIN
// =============================================================================================

/** Mutation probe: `decisionNeeded: true` cho việc đã chuyển ở hai job → đỏ. */
it('does not ring the department head about moved tasks whose assigner has left — only a task left for reassignment needs a decision', function (string $path) {
    $gone = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    ifaTask($this->matter, $this->assistant, $gone);

    if ($path === 'removal') {
        $this->actingAs($this->admin, 'web');
        $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
            ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->oldLead->id])
            ->assertHasNoTableActionErrors();

        expect($this->head->notifications()->where('type', TeamDepartureAlert::class)->count())->toBe(0);

        return;
    }

    $this->actingAs($this->admin, 'web');
    $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->set('staff.recipient_id', $this->oldLead->id)
        ->callAction('transferStaffWork', data: ['reason' => 'Trợ lý nghỉ việc.'])
        ->assertHasNoActionErrors();

    expect($this->head->notifications()->where('type', StaffWorkTransferAlert::class)->count())->toBe(0);
})->with(['removal', 'transfer']);

// =============================================================================================
// 7. NGƯỜI BỊ TẠM KHOÁ: "CẦN GIAO LẠI", KHÔNG "QUÁ HẠN" CỦA HỌ
// =============================================================================================

/** Mutation probe: bỏ `users.is_active` khỏi `scopeHeldBySeatedAssignee()` → đỏ. */
it('counts a suspended holder\'s overdue task only as "Cần giao lại", never also as their overdue work', function () {
    $task = ifaTask($this->matter, $this->assistant, $this->oldLead, TaskStatus::Accepted, ['due_date' => '2026-10-08']);
    $this->assistant->forceFill(['is_active' => false])->save();

    expect(Task::query()->whereKey($task->id)->needsReassignment()->exists())->toBeTrue()
        ->and(Task::query()->whereKey($task->id)->overdueToHandle()->exists())->toBeFalse()
        ->and(RemindPendingWork::assigneeHoldsSeat($task))->toBeFalse();

    $period = PerformancePeriod::fromFilters(['period' => PerformancePeriod::THIS_MONTH]);
    $ratio = app(BuildTaskPerformance::class)->handle($this->head, collect([$this->assistant]), $period)['rows'][$this->assistant->id]['taskOnTime'];

    expect($ratio->denominator)->toBe(0);
});

// =============================================================================================
// 8. CÂU TÓM TẮT "CHUYỂN VIỆC NỘI BỘ" KHỚP ĐIỀU SẼ CHUYỂN
// =============================================================================================

/** Mutation probe: bỏ lọc hồ sơ người đó phụ trách khỏi `staffSummary()` → đỏ. */
it('does not count, in the transfer summary, the work on matters the person leads', function () {
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->newLead->id]);
    $other->addTeamMember($this->oldLead, MatterRole::Associate);
    ifaTask($other, $this->oldLead, $this->newLead);
    // Trên hồ sơ người đó PHỤ TRÁCH: không vào câu tóm tắt — phần "Bàn giao" chuyển chúng.
    ifaTask($this->matter, $this->oldLead, $this->assistant);
    ifaTask($this->matter, $this->assistant, $this->oldLead);

    $this->actingAs($this->admin, 'web');
    $this->livewire(BulkReassign::class, ['from' => $this->oldLead->id])
        ->assertSee(__('internal_flow.staff_work.summary', ['held' => 1, 'assigned' => 0, 'seats' => 0, 'matters' => 1]));
});

// =============================================================================================
// 9. GỠ THÀNH VIÊN TRÊN HỒ SƠ ĐÃ KẾT THÚC
// =============================================================================================

/** Mutation probe: bỏ nhánh hồ sơ đã kết thúc của ô chọn / của Action → đỏ. */
it('offers only "Để Cần giao lại" when removing a member from a closed matter, and says why if someone else is sent', function () {
    $task = ifaTask($this->matter, $this->assistant, $this->oldLead);
    $this->matter->forceFill(['closed_at' => today()])->save();

    $this->actingAs($this->oldLead, 'web');

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter->fresh(), 'pageClass' => ViewMatter::class])
        ->mountTableAction('removeMember', $this->assistant)
        ->assertFormFieldExists('task_recipient_id', fn (Select $field): bool => array_keys($field->getOptions()) === ['leave'])
        ->assertTableActionDataSet(['task_recipient_id' => 'leave']);

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter->fresh(), 'pageClass' => ViewMatter::class])
        ->mountTableAction('removeMember', $this->assistant)
        ->setTableActionData(['task_recipient_id' => (string) $this->newLead->id])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['task_recipient_id']);

    expect($task->fresh()->assignee_id)->toBe($this->assistant->id)
        ->and($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeTrue();

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter->fresh(), 'pageClass' => ViewMatter::class])
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => 'leave'])
        ->assertHasNoTableActionErrors();

    expect($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeFalse();
});

/** Mutation probe: bỏ nhánh hồ sơ đã kết thúc của `RemoveTeamMember` → đỏ (câu từ chối chung của "Giao lại"). */
it('refuses, in the action itself, a recipient on a closed matter with the clear sentence under the select', function () {
    ifaTask($this->matter, $this->assistant, $this->oldLead);
    $this->matter->forceFill(['closed_at' => today()])->save();

    try {
        app(RemoveTeamMember::class)->handle($this->matter->fresh(), $this->oldLead, $this->assistant, $this->newLead);
        $this->fail('RemoveTeamMember phải từ chối');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['task_recipient_id' => [__('internal_flow.team_removal.closed_leave_only')]]);
    }

    expect($this->matter->team()->whereKey($this->assistant->id)->exists())->toBeTrue();
});

// =============================================================================================
// 11. CHỮ KHỚP ĐIỀU BÀN GIAO LÀM
// =============================================================================================

it('describes in the switch hint and the "is lead" line what a handover really moves', function () {
    foreach (['reassign.fields.keep_old_lead_as_associate_hint', 'reassign.bulk.fields.keep_old_lead_as_associate_hint'] as $key) {
        expect(__($key))->toContain('việc riêng')->toContain('người giao');
    }

    expect(__('internal_flow.staff_work.results.is_lead'))->toContain('người giao');
});
