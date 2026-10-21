<?php

use App\Actions\Task\CancelTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\ReopenTask;
use App\Actions\Task\UpdateTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Sửa sau kiểm toán luồng Công việc 2026-10-21 — làn vòng đời (PROGRESS, mục cùng tên). Mỗi phần một phát hiện; mọi
 * lần bấm có màn hình đi qua Livewire. Mỗi test ghi phép thử ngược của nó.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo('2026-10-21 09:00:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->otherManager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Hoà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hà']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'KT-VD-01', 'title' => 'Tranh chấp hợp đồng']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function lcTask(User $assigner, User $holder, TaskStatus $status = TaskStatus::Accepted, array $extra = [], ?Matter $matter = null): Task
{
    $matter ??= test()->matter;

    return Task::factory()->forMatter($matter)->status($status)->create([
        'title' => 'Soạn bản tự khai',
        'assigned_by' => $assigner->id,
        'assignee_id' => $holder->id,
        'due_date' => today()->addDays(5)->toDateString(),
        ...$extra,
    ]);
}

/** @return list<string> các loại `TaskAlert` `$user` đã nhận về `$task` */
function lcBells(User $user, ?Task $task = null): array
{
    return $user->notifications()
        ->where('type', TaskAlert::class)
        ->get()
        ->filter(fn ($n): bool => $task === null || ($n->data['viewData']['task_id'] ?? null) === $task->id)
        ->map(fn ($n): string => (string) $n->data['title'])
        ->values()
        ->all();
}

// =============================================================================================
// MỤC 2 — người nhận (luật sư phụ trách, quản lý) không tự sửa / giao lại / huỷ việc NGƯỜI KHÁC giao cho mình
// =============================================================================================

/**
 * Phép thử ngược: bỏ vế "người nhận của việc người khác giao" trong `TaskPolicy::manages()` → đỏ (nút hiện, Action
 * chạy).
 */
it('does not let the lead lawyer edit, reassign or cancel a task the manager gave them — they decline it instead', function () {
    $task = lcTask($this->manager, $this->lead);

    $this->actingAs($this->lead, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'active'])
        ->assertCanSeeTableRecords([$task])
        ->assertTableActionHidden('edit', $task)
        ->assertTableActionHidden('reassign', $task)
        ->assertTableActionHidden('cancel', $task)
        ->assertTableActionVisible('decline', $task)
        ->assertTableActionVisible('complete', $task);

    expect(fn () => app(UpdateTask::class)->handle($task, $this->lead, 'Đổi tên', null, today()->addDay()->toDateString(), TaskPriority::Urgent, null))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReassignTask::class)->handle($task, $this->lead, $this->assistant))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelTask::class)->handle($task, $this->lead, 'Không làm'))->toThrow(AuthorizationException::class);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($fresh->assignee_id)->toBe($this->lead->id)
        ->and($fresh->due_date->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and($fresh->priority)->toBe(TaskPriority::Normal);
});

/** Phép thử ngược: cùng mutant trên → đỏ (trưởng phòng đang giữ việc quản trị viên giao vẫn sửa được). */
it('applies to a manager holding a task someone else gave them, while another manager still manages it', function () {
    $task = lcTask($this->admin, $this->manager, TaskStatus::Pending);

    expect(Gate::forUser($this->manager)->allows('update', $task))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('reassign', $task))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('cancel', $task))->toBeFalse()
        ->and(Gate::forUser($this->manager)->allows('decline', $task))->toBeTrue()
        ->and(Gate::forUser($this->otherManager)->allows('update', $task))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('cancel', $task))->toBeTrue();
});

/**
 * Các đường có sẵn giữ nguyên: việc riêng (người giao = người giữ — sau một lần nhận thay), việc luật sư phụ trách theo
 * dõi mà người khác giữ. Phép thử ngược: thêm điều kiện "người giữ không quản lý" mà quên vế người giao = người giữ → đỏ.
 */
it('keeps the own-task path and the lead lawyer\'s grip on tasks other people hold', function () {
    $own = lcTask($this->lead, $this->lead);
    $followed = lcTask($this->associate, $this->assistant);

    expect(Gate::forUser($this->lead)->allows('update', $own))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('reassign', $own))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('cancel', $own))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('update', $followed))->toBeTrue()
        ->and(Gate::forUser($this->lead)->allows('reassign', $followed))->toBeTrue();

    // Nhận thay rồi việc thành việc riêng: người nhận thay giao lại / huỷ được bằng quyền người giao.
    $taken = lcTask($this->lead, $this->assistant);
    app(ReassignTask::class)->handle($taken, $this->lead, $this->lead, takeOver: true);
    expect(Gate::forUser($this->lead)->allows('reassign', $taken->fresh()))->toBeTrue();
});

/**
 * Người sửa không phải người giao: người giao nhận MỘT chuông nêu điều đã đổi; người giữ vẫn nhận lời "đã sửa" của họ.
 * Phép thử ngược: bỏ lời báo người giao trong `UpdateTask` → đỏ.
 */
it('rings the assigner once when someone else edits the task they gave', function () {
    $task = lcTask($this->associate, $this->assistant);

    $this->actingAs($this->lead, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('edit', $task, data: [
            'title' => 'Soạn bản tự khai',
            'due_date' => today()->addDays(2)->toDateString(),
            'priority' => TaskPriority::Urgent->value,
            'description' => null,
        ])
        ->assertHasNoTableActionErrors();

    $assignerBells = $this->associate->notifications()->where('type', TaskAlert::class)->get();

    expect($assignerBells)->toHaveCount(1)
        ->and($assignerBells->first()->data['title'])->toBe(__('tasks.notice.updated_by_other.title'))
        ->and($assignerBells->first()->data['body'])->toContain('Luật sư Khang')
        ->and($assignerBells->first()->data['body'])->toContain('26/10/2026 → 23/10/2026')
        ->and(lcBells($this->assistant, $task))->toBe([__('tasks.notice.updated.title')])
        ->and(lcBells($this->lead))->toBe([]);
});

/** Người giao tự sửa, hay việc riêng được sửa bởi chính người giữ: không ai nhận lời báo người giao. */
it('does not ring the assigner about their own edit, nor twice about a private task', function () {
    $task = lcTask($this->associate, $this->assistant);
    app(UpdateTask::class)->handle($task, $this->associate, 'Soạn bản tự khai', null, today()->addDays(3)->toDateString(), TaskPriority::Normal, null);

    expect(lcBells($this->associate))->toBe([]);

    // Việc riêng của luật sư cộng sự, trưởng phòng sửa: người giữ (cũng là người giao) chỉ nhận MỘT lời — "đã sửa".
    $own = lcTask($this->associate, $this->associate);
    app(UpdateTask::class)->handle($own, $this->manager, 'Soạn bản tự khai', null, today()->addDays(3)->toDateString(), TaskPriority::Normal, null);

    expect(lcBells($this->associate, $own))->toBe([__('tasks.notice.updated.title')]);
});

// =============================================================================================
// MỤC 8 — người huỷ chính là người giữ không tự báo mình
// =============================================================================================

/** Phép thử ngược: bỏ vế `assignee_id !== actor` trong `CancelTask` → đỏ (người huỷ nhận chuông "đã huỷ" của mình). */
it('does not ring the holder who cancels their own task', function () {
    $own = lcTask($this->lead, $this->lead);

    $this->actingAs($this->lead, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'active'])
        ->callTableAction('cancel', $own, data: ['reason' => 'Khách rút yêu cầu'])
        ->assertHasNoTableActionErrors();

    expect($own->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and(lcBells($this->lead))->toBe([]);

    // Người khác huỷ thì người giữ vẫn được báo.
    $held = lcTask($this->lead, $this->assistant);
    app(CancelTask::class)->handle($held, $this->lead, 'Khách rút yêu cầu');
    expect(lcBells($this->assistant, $held))->toBe([__('tasks.alert.cancelled.title')]);
});

// =============================================================================================
// MỤC 4 — "Giao lại" không ghi đè lặng lẽ hạn người khác vừa sửa
// =============================================================================================

/**
 * Hộp thoại "Giao lại" mở (hạn điền sẵn 26/10), người giao sửa hạn thành 23/10, rồi người mở hộp thoại bấm lưu: từ
 * chối bằng câu "vừa được thay đổi", hạn 23/10 và người giữ không đổi. Phép thử ngược: bỏ phép so `expectedUpdatedAt`
 * trong `ReassignTask` (hay bỏ ô ẩn `mounted_updated_at`) → đỏ (việc sang người mới với hạn cũ 26/10).
 */
it('refuses a reassignment saved from a dialog opened before someone else changed the task', function () {
    $task = lcTask($this->associate, $this->assistant);

    $this->actingAs($this->lead, 'web');

    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->mountAction(TestAction::make('reassign')->table($task))
        ->assertActionDataSet(['due_date' => '2026-10-26']);

    $this->travel(1)->minutes();
    app(UpdateTask::class)->handle($task->fresh(), $this->associate, 'Soạn bản tự khai', null, '2026-10-23', TaskPriority::Normal, null);

    $page->setActionData(['assignee_id' => $this->otherAssistant->id])
        ->callMountedAction()
        ->assertHasActionErrors(['assignee_id' => __('tasks.validation.changed_meanwhile')]);

    $fresh = $task->fresh();
    expect($fresh->assignee_id)->toBe($this->assistant->id)
        ->and($fresh->due_date->toDateString())->toBe('2026-10-23')
        ->and(Activity::query()->where('event', 'task_reassigned')->count())->toBe(0);

    // Mở lại hộp thoại: hạn mới được điền sẵn, giao lại được.
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->mountAction(TestAction::make('reassign')->table($fresh))
        ->assertActionDataSet(['due_date' => '2026-10-23'])
        ->setActionData(['assignee_id' => $this->otherAssistant->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($task->fresh()->assignee_id)->toBe($this->otherAssistant->id)
        ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-23');
});

// =============================================================================================
// MỤC 5 — "Mở lại" đặt được hạn mới khi hạn cũ đã qua
// =============================================================================================

function lcDone(array $extra = []): Task
{
    return lcTask(test()->associate, test()->assistant, TaskStatus::Completed, [
        'assigned_at' => '2026-10-10 09:00:00',
        'due_date' => '2026-10-15',
        'completed_at' => now()->subHours(2),
        ...$extra,
    ]);
}

/**
 * Hạn cũ 15/10 đã qua (người giữ có 5 ngày từ 10/10): hộp thoại điền sẵn 26/10 — cùng luật "Giao lại"; lưu thì việc về
 * "Đang làm" với hạn mới, nhật ký `task_reopened` ghi hạn cũ/mới. Phép thử ngược: bỏ ô Hạn của hộp thoại (hay
 * `ReopenTask` bỏ qua `$dueDate`) → đỏ (việc mở lại vẫn hạn 15/10 — quá hạn ngay).
 */
it('suggests a new due date when reopening a task whose due date has passed, and logs the change', function () {
    $task = lcDone();

    $this->actingAs($this->associate, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountAction(TestAction::make('reopen')->table($task))
        ->assertActionDataSet(['due_date' => '2026-10-26'])
        ->setActionData(['reason' => 'Thiếu phụ lục 2'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $fresh = $task->fresh();
    $event = Activity::query()->where('event', 'task_reopened')->sole();

    expect($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($fresh->due_date->toDateString())->toBe('2026-10-26')
        ->and($event->getExtraProperty('due_date_from'))->toBe('2026-10-15')
        ->and($event->getExtraProperty('due_date_to'))->toBe('2026-10-26');
});

/** Người mở lại chọn hạn khác, hay giữ hạn cũ đã qua; hạn không đổi thì nhật ký không có hạn cũ/mới. */
it('lets the person reopening pick the due date, and logs nothing about it when it is unchanged', function () {
    $task = lcDone();

    app(ReopenTask::class)->handle($task, $this->associate, 'Làm lại', '2026-10-24', setDueDate: true);
    expect($task->fresh()->due_date->toDateString())->toBe('2026-10-24');

    $future = lcDone(['title' => 'Việc còn hạn', 'due_date' => '2026-10-30']);
    $this->actingAs($this->associate, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->mountAction(TestAction::make('reopen')->table($future))
        ->assertActionDataSet(['due_date' => '2026-10-30'])
        ->setActionData(['reason' => 'Làm lại'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $event = Activity::query()->where('event', 'task_reopened')->where('subject_id', $future->id)->sole();
    expect($future->fresh()->due_date->toDateString())->toBe('2026-10-30')
        ->and($event->getExtraProperty('due_date_from'))->toBeNull();

    // Một ngày đã qua khác hạn cũ: từ chối dưới ô Hạn (cùng luật "Giao lại").
    $again = lcDone(['title' => 'Việc thứ ba']);
    expect(fn () => app(ReopenTask::class)->handle($again, $this->associate, 'Làm lại', '2026-10-20', setDueDate: true))
        ->toThrow(ValidationException::class);
    expect($again->fresh()->status)->toBe(TaskStatus::Completed);
});

/**
 * Người nhận tự mở lại (bấm nhầm, trong 24 giờ) không đổi hạn của chính mình: hộp thoại không có ô Hạn, Action từ chối
 * một hạn khác. Phép thử ngược: bỏ vế "người tự mở lại" trong `ReopenTask` → đỏ.
 */
it('keeps the due date when the holder reopens their own task by mistake', function () {
    $task = lcDone();

    $this->actingAs($this->assistant, 'web');

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->mountAction(TestAction::make('reopen')->table($task))
        ->assertFormFieldDoesNotExist('due_date')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted)
        ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-15');

    $other = lcDone(['title' => 'Việc khác']);
    expect(fn () => app(ReopenTask::class)->handle($other, $this->assistant, null, '2026-10-30', setDueDate: true))
        ->toThrow(ValidationException::class);
    expect($other->fresh()->status)->toBe(TaskStatus::Completed);
});
