<?php

use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification as FilamentNotification;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa1, Task 1 — vòng sửa 2 (rà soát cuối của làn: F1, F2, và c2, c4). Mọi lần bấm qua trang (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Ho_Chi_Minh'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'FR2-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function fr2Task(TaskStatus $status, array $extra = [], ?Matter $matter = null): Task
{
    return Task::factory()->forMatter($matter ?? test()->matter)->status($status)->create([
        'title' => 'Nộp đơn khởi kiện',
        'assignee_id' => test()->assistant->id,
        'assigned_by' => test()->associate->id,
        ...$extra,
    ]);
}

/** Trợ lý Lan chuyển sang "người theo dõi" của vụ — vẫn xem được vụ, không còn giữ được việc. */
function fr2Observer(): void
{
    test()->matter->team()->detach(test()->assistant->id);
    test()->matter->addTeamMember(test()->assistant, MatterRole::Observer);
}

/** @return list<FilamentNotification> */
function fr2Bells(User $user, string $title): array
{
    return $user->notifications()->where('type', TaskAlert::class)->get()
        ->map(fn ($row) => FilamentNotification::fromDatabase($row))
        ->filter(fn (FilamentNotification $bell): bool => $bell->getTitle() === $title)
        ->values()
        ->all();
}

function fr2Mails(string $template, User $to): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', $template)
        ->where('recipient', $to->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

// =============================================================================================
// F1. SỐ "ĐANG GIỮ" BÊN TÊN NGƯỜI NHẬN — CÙNG ĐỊNH NGHĨA VỚI "QUÁ HẠN" VÀ THEO DÕI ĐỘI NGŨ
// =============================================================================================

/** Mutation probe: bỏ `->heldBySeatedAssignee()` khỏi số "đang giữ" của `TaskAssignees::options()` → đỏ (3 thay vì 1). */
it('does not count, next to a name in "Giao việc", the tasks that person no longer holds', function () {
    fr2Task(TaskStatus::Accepted, ['assigned_by' => $this->lead->id]);
    fr2Task(TaskStatus::Pending, ['assigned_by' => $this->lead->id, 'title' => 'Sao y hồ sơ', 'due_date' => '2026-10-01']);
    Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Việc chung đang làm',
        'assignee_id' => $this->assistant->id,
        'assigned_by' => $this->lead->id,
    ]);
    fr2Observer();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class)->mountAction('create')
        ->assertFormFieldExists('assignee_id', fn (Select $field): bool => str_ends_with(
            $field->getOptions()[test()->assistant->id],
            __('tasks.assign.workload', ['held' => 1, 'overdue' => 0]),
        ));
});

// =============================================================================================
// F2. QUY-TRINH NÓI ĐÚNG AI KHÔNG NHẬN GÌ TRÊN VỤ HẠN CHẾ
// =============================================================================================

it('says in QUY-TRINH that only the assigner who cannot open a restricted matter hears nothing, and the former holder one plain bell', function () {
    $guide = (string) file_get_contents(base_path('docs/QUY-TRINH.md'));
    $flat = preg_replace('/\s+/u', ' ', $guide);

    expect($flat)->not->toContain('Việc đã giao cho người không mở được hồ sơ hạn chế: người đó không nhận lời báo nào')
        ->and($flat)->toContain('Người giao không còn mở được hồ sơ hạn chế không nhận lời báo nào về việc ấy')
        ->and($flat)->toContain('người vừa mất việc chỉ nhận một chuông chung, không tên việc, không mã vụ');
});

// =============================================================================================
// c2. "SỬA" KHÔNG BÁO NGƯỜI KHÔNG CÒN GIỮ ĐƯỢC VIỆC
// =============================================================================================

/** Mutation probe: bỏ lần hỏi `qualifies()` của `UpdateTask` (luôn báo người giữ) → đỏ. */
it('tells nobody when the task edited is held by someone who has since become an observer, and says it needs reassigning', function () {
    $task = fr2Task(TaskStatus::Accepted, ['due_date' => '2026-10-30']);
    fr2Observer();

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('edit', $task, data: [
            'title' => $task->title,
            'description' => $task->description,
            'due_date' => '2026-10-23',
            'priority' => $task->priority->value,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.actions.edit_success_needs_reassignment'));

    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-23')
        ->and(fr2Bells($this->assistant, __('tasks.notice.updated.title')))->toBe([])
        ->and(fr2Mails('staff.task.updated', $this->assistant))->toBe(0)
        ->and(__('tasks.actions.edit_success_needs_reassignment'))->toContain('Cần giao lại')->not->toContain('được báo bằng');
});

/** Mutation probe: luôn trả câu "Cần giao lại" → đỏ. Đối chứng: người còn giữ việc vẫn được báo như trước. */
it('still tells a holder who holds the task what changed, with the usual success line', function () {
    $task = fr2Task(TaskStatus::Accepted, ['due_date' => '2026-10-30']);

    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('edit', $task, data: [
            'title' => $task->title,
            'description' => $task->description,
            'due_date' => '2026-10-23',
            'priority' => $task->priority->value,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.actions.edit_success'));

    expect(fr2Bells($this->assistant, __('tasks.notice.updated.title')))->toHaveCount(1)
        ->and(fr2Mails('staff.task.updated', $this->assistant))->toBe(1);
});

// =============================================================================================
// c4. KHÔI PHỤC VỀ CHÍNH NGƯỜI BẤM
// =============================================================================================

/**
 * Mutation probe: bỏ nhánh "người bấm là người nhận cũ" của `RestoreTask` → đỏ (việc về "Chờ nhận" cho chính mình,
 * câu xong hứa "người nhận cũ được báo").
 */
it('restores to "Đang làm", without a notice to themselves, a task the lead held and a manager cancelled', function () {
    $task = fr2Task(TaskStatus::Pending, ['assignee_id' => $this->lead->id, 'title' => 'Soạn bản tự khai']);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Khách rút yêu cầu'])
        ->assertHasNoTableActionErrors();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh(), data: ['reason' => 'Khách vẫn cần'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.restore.success_own'));

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($fresh->assignee_id)->toBe($this->lead->id)
        ->and($fresh->accepted_at)->not->toBeNull()
        ->and($fresh->cancelled_at)->toBeNull()
        ->and(Activity::query()->where('event', 'task_restored')->sole()->properties['to'])->toBe(TaskStatus::Accepted->value)
        ->and(fr2Bells($this->lead, __('tasks.notice.restored.title')))->toBe([])
        ->and(fr2Mails('staff.task.restored', $this->lead))->toBe(0)
        // Người giao gốc vẫn được báo như mọi lần người khác khôi phục việc của họ.
        ->and(fr2Bells($this->associate, __('tasks.notice.restored_by_other.title')))->toHaveCount(1)
        ->and(__('tasks.restore.success_own'))->toContain('Đang làm')->not->toContain('được báo');
});

/** Mutation probe: bỏ vế `$qualifies` của "khôi phục về chính người bấm" → đỏ (việc về "Đang làm" cho người không còn giữ được nó). */
it('restores a task to the manager who held it but has since become an observer as "Cần giao lại", not "Đang làm"', function () {
    $this->matter->addTeamMember($this->manager, MatterRole::Associate);
    $task = fr2Task(TaskStatus::Accepted, ['assignee_id' => $this->manager->id, 'assigned_by' => $this->lead->id]);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->callTableAction('cancel', $task, data: ['reason' => 'Tạm dừng'])
        ->assertHasNoTableActionErrors();

    $this->matter->team()->detach($this->manager->id);
    $this->matter->addTeamMember($this->manager, MatterRole::Observer);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('restore', $task->fresh(), data: ['reason' => 'Khách vẫn cần'])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.restore.success_needs_reassignment'));

    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(Task::query()->whereKey($task->id)->needsReassignment()->exists())->toBeTrue();
});
