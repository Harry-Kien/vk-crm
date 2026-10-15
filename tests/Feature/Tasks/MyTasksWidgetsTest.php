<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Widgets\Tasks\MyActiveTasksWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\TasksIAssignedWidget;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Làn tk, Task 1 — "Việc của tôi" (trang của chính mình, M13) có thêm ba khối: Chờ tôi nhận (nút "Đã
 * nhận"/"Trả lại" ngay trên dòng), Đang làm (quá hạn tô đỏ), Tôi đã giao (việc bị trả lại để giao lại).
 * Ba khối chỉ hiện trên trang CỦA CHÍNH người xem, và mỗi khối chỉ đọc việc của người đang đăng nhập.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);

    $this->pending = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn khởi kiện',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
    ]);
    $this->overdue = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp án phí',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'due_date' => today()->subDay(),
    ]);
    $this->declined = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Gặp nhân chứng',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->otherAssistant->id,
    ]);
    $this->othersTask = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc của Tùng',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->otherAssistant->id,
    ]);
});

it('puts the three task blocks on my own page, and not on someone else\'s', function () {
    $this->actingAs($this->assistant, 'web');
    $own = $this->livewire(TeamMember::class, ['user' => $this->assistant->id])->instance();

    expect($own->getVisibleHeaderWidgets())->toBe([
        TasksAwaitingMeWidget::class,
        MyActiveTasksWidget::class,
        TasksIAssignedWidget::class,
    ]);

    $this->actingAs($this->manager, 'web');
    $other = $this->livewire(TeamMember::class, ['user' => $this->assistant->id])->instance();

    expect($other->getVisibleHeaderWidgets())->toBe([]);
});

it('still opens my own page over HTTP with the blocks in it', function () {
    $this->actingAs($this->assistant, 'web')
        ->get(TeamMember::getUrl(['user' => $this->assistant->id], panel: 'admin'))
        ->assertOk()
        ->assertSee(__('tasks.my.awaiting.heading'))
        ->assertSee(__('tasks.my.assigned.heading'))
        ->assertSee('Soạn đơn khởi kiện')
        ->assertDontSee('Việc của Tùng');
});

it('lists what waits for me and lets me accept it right there', function () {
    $this->actingAs($this->assistant, 'web');

    $this->livewire(TasksAwaitingMeWidget::class)
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->overdue, $this->declined, $this->othersTask])
        ->callTableAction('accept', $this->pending)
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Accepted);
});

it('lets me decline from the block, with a required reason', function () {
    $this->actingAs($this->assistant, 'web');

    $this->livewire(TasksAwaitingMeWidget::class)
        ->callTableAction('decline', $this->pending, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Pending);

    $this->livewire(TasksAwaitingMeWidget::class)
        ->callTableAction('decline', $this->pending, data: ['reason' => 'Đang làm vụ khác gấp hơn'])
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Declined);
});

it('marks my overdue work in red', function () {
    $this->actingAs($this->assistant, 'web');

    $this->livewire(MyActiveTasksWidget::class)
        ->assertCanSeeTableRecords([$this->overdue])
        ->assertCanNotSeeTableRecords([$this->pending])
        ->assertSee(__('tasks.due.overdue', ['count' => 1]))
        ->assertSeeHtml('var(--danger-600)');
});

it('shows the lead the declined task with its reason, ready to reassign', function () {
    $this->actingAs($this->lead, 'web');

    $this->livewire(TasksIAssignedWidget::class)
        ->assertCanSeeTableRecords([$this->declined, $this->pending, $this->overdue, $this->othersTask])
        ->assertSee('Đang quá tải việc')
        ->assertTableActionVisible('reassign', $this->declined)
        ->callTableAction('reassign', $this->declined, data: ['assignee_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    expect($this->declined->fresh()->assignee_id)->toBe($this->assistant->id);
});

it('keeps a task finished this week in my assigned block, so I can read what was done', function () {
    $done = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Sao y hợp đồng',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'completion_note' => 'Đã sao y 3 bộ',
    ]);
    $old = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Việc cũ đã xong',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'completed_at' => now()->subDays(Task::RECENTLY_COMPLETED_DAYS + 1),
    ]);
    $this->actingAs($this->lead, 'web');

    $this->livewire(TasksIAssignedWidget::class)
        ->set('tableRecordsPerPage', 25)
        ->assertCanSeeTableRecords([$done])
        ->assertCanNotSeeTableRecords([$old])
        ->assertSee('Đã sao y 3 bộ')
        ->assertTableActionHidden('edit', $done)
        ->assertTableActionHidden('reassign', $done)
        ->assertTableActionHidden('cancel', $done);
});

it('drops a matter task from my blocks once I am taken off the team', function () {
    $this->matter->team()->detach($this->assistant->id);
    $this->actingAs($this->assistant, 'web');

    $this->livewire(TasksAwaitingMeWidget::class)->assertCountTableRecords(0);
    $this->livewire(MyActiveTasksWidget::class)->assertCountTableRecords(0);
});

it('never puts the blocks on the dashboard by discovery', function () {
    foreach ([TasksAwaitingMeWidget::class, MyActiveTasksWidget::class, TasksIAssignedWidget::class] as $widget) {
        expect(Filament::getPanel('admin')->getWidgets())->not->toContain($widget);
    }
});
