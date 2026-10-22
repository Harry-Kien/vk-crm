<?php

use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\AssignTask;
use App\Actions\Task\DeclineTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\ReopenTask;
use App\Actions\Task\UpdateTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\Tasks\MyActiveTasksWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\TasksIAssignedWidget;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TaskReminderAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Kiểm toán màn hình "Công việc" 2026-10-21 — làn màn hình. Mỗi khối một phát hiện của đợt kiểm toán:
 *
 *  1–2. Bố cục: dưới `md` một cột tóm tắt thay các cột phụ; MỘT nút chính ngoài dòng (Đã nhận / Hoàn thành /
 *       Giao lại khi bị trả lại), mọi nút khác trong "⋮"; cột "Việc" có độ rộng tối thiểu; cột luôn là chính mình
 *       ẩn ở tab và khối tương ứng.
 *  3–4. Thứ tự: cùng mức hạn thì Gấp > Cao > Thường, việc Gấp không hạn đứng cùng việc hạn hôm nay; cột "Ưu tiên"
 *       và cột "Hạn" sắp xếp được thật; khối "Tôi đã giao" xếp việc còn mở trước.
 *  5.   Thông báo mở thẳng khung "Xem" của đúng việc; khung "Xem" có các nút của dòng, mã vụ bấm được, khối "Diễn biến".
 *  6.   Việc "Bị trả lại": "Giao lại" là nút chính của dòng cho người giao.
 *  7.   Tìm theo tên người nhận/người giao.
 *  8.   Bộ lọc nhanh; tab "Tất cả" mặc định chỉ việc còn mở; bộ lọc ở tab "Công việc" của hồ sơ.
 *  9.   Breadcrumb viết hoa; ô "Mức ưu tiên" không có nút xoá; lịch hạn của "Giao việc" không cho ngày đã qua.
 *
 * Mọi khẳng định đi qua Livewire (màn hình) hay qua đúng lớp thông báo/thư; bố cục thật đo bằng Playwright (PROGRESS).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo('2026-10-21 09:00:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->lan = User::factory()->withRole(Role::Assistant)->create(['name' => 'Ngô Thị Lan']);
    $this->tung = User::factory()->withRole(Role::Assistant)->create(['name' => 'Đinh Văn Tùng']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->lan, MatterRole::Assistant);
    $this->matter->addTeamMember($this->tung, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
});

function scrTask(array $attributes = [], TaskStatus $status = TaskStatus::Pending, ?Matter $matter = null): Task
{
    $matter ??= test()->matter;

    return Task::factory()->forMatter($matter)->status($status)->create([
        'assigned_by' => test()->lead->id,
        'assignee_id' => test()->lan->id,
        ...$attributes,
    ]);
}

function scrList(?string $tab = null)
{
    return test()->livewire(ListTasks::class, $tab === null ? [] : ['activeTab' => $tab]);
}

function scrMatterTab(?Matter $matter = null)
{
    return test()->livewire(TasksRelationManager::class, [
        'ownerRecord' => $matter ?? test()->matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Các nút đang mở trên trang, dạng "tên:id dòng" — khung mở từ một đường dẫn (`mountedFromUrl`). */
function scrMounted($component): array
{
    return array_map(
        fn (array $action): string => $action['name'].':'.($action['context']['recordKey'] ?? ''),
        $component->instance()->mountedActions,
    );
}

/** Tên các nút đứng NGOÀI dòng, và tên các nút trong nhóm "⋮", theo thứ tự cấu hình của bảng. */
function scrActionLayout($component): array
{
    $outside = [];
    $grouped = [];

    foreach ($component->instance()->getTable()->getRecordActions() as $action) {
        if ($action instanceof ActionGroup) {
            $grouped = array_map(fn (Action $a): string => $a->getName(), $action->getActions());

            continue;
        }

        $outside[] = $action->getName();
    }

    return [$outside, $grouped];
}

// =============================================================================================
// 3. THỨ TỰ — ưu tiên trong cùng mức hạn, việc Gấp không hạn không chìm, cột sắp xếp được
// =============================================================================================

it('puts an urgent task with no due date above a normal task due in thirty days, and next to today\'s work', function () {
    $normalLater = scrTask(['title' => 'Thường hạn 30 ngày', 'due_date' => today()->addDays(30)]);
    $urgentNoDue = scrTask(['title' => 'Gấp không hạn', 'priority' => TaskPriority::Urgent]);
    $normalToday = scrTask(['title' => 'Thường hạn hôm nay', 'due_date' => today()]);
    $overdue = scrTask(['title' => 'Thường quá hạn', 'due_date' => today()->subDay()]);
    $normalNoDue = scrTask(['title' => 'Thường không hạn']);
    $highNoDue = scrTask(['title' => 'Cao không hạn', 'priority' => TaskPriority::High]);

    $this->actingAs($this->lan, 'web');

    // Quá hạn trước (đã trễ); rồi hạn hôm nay — việc Gấp không hạn đứng ĐẦU nhóm này; rồi hạn xa; việc không hạn
    // cuối, Cao trước Thường.
    scrList('awaiting')->assertCanSeeTableRecords(
        [$overdue, $urgentNoDue, $normalToday, $normalLater, $highNoDue, $normalNoDue],
        inOrder: true,
    );
});

it('orders the same due date by priority: urgent, high, normal', function () {
    $normal = scrTask(['title' => 'Thường', 'due_date' => today()->addDays(3)]);
    $urgent = scrTask(['title' => 'Gấp', 'due_date' => today()->addDays(3), 'priority' => TaskPriority::Urgent]);
    $high = scrTask(['title' => 'Cao', 'due_date' => today()->addDays(3), 'priority' => TaskPriority::High]);

    $this->actingAs($this->lead, 'web');

    scrList('all')->assertCanSeeTableRecords([$urgent, $high, $normal], inOrder: true);
    scrMatterTab()->assertCanSeeTableRecords([$urgent, $high, $normal], inOrder: true);
});

it('keeps closed work below open work whatever the priority', function () {
    $doneUrgent = scrTask(['title' => 'Gấp đã xong', 'priority' => TaskPriority::Urgent, 'due_date' => today()], TaskStatus::Completed);
    $openNormal = scrTask(['title' => 'Thường còn mở', 'due_date' => today()->addDays(9)]);

    $this->actingAs($this->lead, 'web');

    scrList('assigned')->assertCanSeeTableRecords([$openNormal, $doneUrgent], inOrder: true);
});

it('sorts by the priority column, most urgent first, and the other way round', function () {
    $normal = scrTask(['title' => 'Thường', 'due_date' => today()]);
    $urgent = scrTask(['title' => 'Gấp', 'due_date' => today()->addDays(20), 'priority' => TaskPriority::Urgent]);
    $high = scrTask(['title' => 'Cao', 'due_date' => today()->addDays(10), 'priority' => TaskPriority::High]);

    $this->actingAs($this->lead, 'web');

    scrList('all')
        ->sortTable('priority')
        ->assertCanSeeTableRecords([$urgent, $high, $normal], inOrder: true)
        ->sortTable('priority', 'desc')
        ->assertCanSeeTableRecords([$normal, $high, $urgent], inOrder: true);
});

it('really sorts by the due date column when asked (the default order no longer wins over a click)', function () {
    $soon = scrTask(['title' => 'Sớm', 'due_date' => today()->addDay()]);
    $late = scrTask(['title' => 'Muộn', 'due_date' => today()->addDays(20)]);

    $this->actingAs($this->lead, 'web');

    scrList('all')
        ->sortTable('due_date', 'desc')
        ->assertCanSeeTableRecords([$late, $soon], inOrder: true);
});

it('lists open work before finished work in the "I assigned" block of My work', function () {
    // Việc đã xong có hạn sớm hơn việc còn mở: trước bản sửa, khối xếp theo hạn trần nên việc đã xong đứng trên.
    $done = scrTask(['title' => 'Đã xong hôm qua', 'due_date' => today()->subDay()], TaskStatus::Completed);
    $open = scrTask(['title' => 'Còn mở tuần sau', 'due_date' => today()->addDays(7)]);

    $this->actingAs($this->lead, 'web');

    $this->livewire(TasksIAssignedWidget::class)->assertCanSeeTableRecords([$open, $done], inOrder: true);
});

// =============================================================================================
// 1–2, 6. BỐ CỤC — một nút chính, cột tóm tắt dưới md, cột "Việc" đủ rộng, cột luôn là chính mình ẩn
// =============================================================================================

it('keeps exactly one primary button outside the row and puts everything else behind "⋮"', function () {
    $this->actingAs($this->lan, 'web');

    [$outside, $grouped] = scrActionLayout(scrList('awaiting'));

    expect($outside)->toBe(['accept', 'complete', 'reassignReturned'])
        ->and($grouped)->toBe(['view', 'completePending', 'decline', 'edit', 'reassign', 'cancel', 'reopen', 'restore']);

    // Cùng bố cục ở tab "Công việc" của hồ sơ và ở ba khối "Việc của tôi" — một trait, một bố cục.
    $this->actingAs($this->lead, 'web');
    expect(scrActionLayout(scrMatterTab()))->toBe([$outside, $grouped])
        ->and(scrActionLayout($this->livewire(TasksIAssignedWidget::class)))->toBe([$outside, $grouped]);
});

it('shows each state exactly the one primary button its holder needs', function () {
    $pending = scrTask(['title' => 'Chờ nhận']);
    $accepted = scrTask(['title' => 'Đang làm'], TaskStatus::Accepted);
    $declined = scrTask(['title' => 'Bị trả lại'], TaskStatus::Declined);

    $this->actingAs($this->lan, 'web');
    scrList('all')
        ->assertActionVisible(TestAction::make('accept')->table($pending))
        ->assertActionHidden(TestAction::make('complete')->table($pending))
        // "Nhận và xong một lượt" vẫn làm được — trong "⋮", không thành nút chính thứ hai.
        ->assertActionVisible(TestAction::make('completePending')->table($pending))
        ->assertActionHidden(TestAction::make('completePending')->table($accepted))
        ->assertActionHidden(TestAction::make('reassignReturned')->table($pending))
        ->assertActionVisible(TestAction::make('complete')->table($accepted))
        ->assertActionHidden(TestAction::make('accept')->table($accepted))
        // Người đã trả lại không giao lại được — nút chính của dòng bị trả lại là của người giao.
        ->assertActionHidden(TestAction::make('reassignReturned')->table($declined));

    $this->actingAs($this->lead, 'web');
    scrList('assigned')
        ->assertActionVisible(TestAction::make('reassignReturned')->table($declined))
        ->assertActionHidden(TestAction::make('reassignReturned')->table($pending))
        ->assertActionHidden(TestAction::make('reassignReturned')->table($accepted))
        ->assertActionHidden(TestAction::make('accept')->table($pending));
});

it('reassigns a declined task straight from its primary row button', function () {
    $declined = scrTask(['title' => 'Liên hệ nhân chứng'], TaskStatus::Declined);

    $this->actingAs($this->lead, 'web');
    scrList('assigned')
        ->callAction(TestAction::make('reassignReturned')->table($declined), data: ['assignee_id' => $this->tung->id])
        ->assertHasNoFormErrors();

    expect($declined->fresh())
        ->status->toBe(TaskStatus::Pending)
        ->assignee_id->toBe($this->tung->id);
});

it('shows one compact summary cell below xl and keeps the detail columns from xl up', function () {
    $task = scrTask([
        'title' => 'Soạn đơn khởi kiện',
        'due_date' => today()->addDay(),
        'priority' => TaskPriority::Urgent,
    ]);

    $this->actingAs($this->lan, 'web');
    $page = scrList('awaiting');

    $columns = collect($page->instance()->getTable()->getColumns());

    expect($columns->get('summary')?->getHiddenFrom())->toBe('xl')
        ->and($columns->except('summary')->map(fn ($column) => $column->getVisibleFrom())->unique()->values()->all())->toBe(['xl']);

    // Tóm tắt: tiêu đề, trạng thái; hạn · mã vụ · người giao; mức ưu tiên khi không phải "Thường".
    $html = (string) TaskColumns::renderSummary($task->fresh(['matter', 'assignee', 'assigner']));

    expect($html)->toContain('Soạn đơn khởi kiện')
        ->toContain(e(__('tasks.status.pending')))
        ->toContain(e(__('tasks.due.tomorrow')))
        ->toContain(e($this->matter->code))
        ->toContain(e(__('tasks.summary.from', ['name' => 'Luật sư Khang'])))
        ->toContain(e(__('tasks.priority.urgent')));

    $page->assertSee('Soạn đơn khởi kiện');
});

it('escapes the title in the summary cell and says who the task is for when I gave it', function () {
    $task = scrTask(['title' => '<img src=x onerror=alert(1)> Nộp án phí']);

    $this->actingAs($this->lead, 'web');
    $html = (string) TaskColumns::renderSummary($task->load(['matter', 'assignee', 'assigner']));

    expect($html)->not->toContain('<img src=x')
        ->toContain('&lt;img src=x')
        ->toContain(e(__('tasks.summary.to', ['name' => 'Ngô Thị Lan'])));
});

it('flags a task that needs reassigning in the summary cell too', function () {
    $task = scrTask(['title' => 'Việc của người đã nghỉ']);
    $this->lan->update(['is_active' => false]);

    $this->actingAs($this->lead, 'web');
    $html = (string) TaskColumns::renderSummary($task->fresh(['matter', 'assignee', 'assigner']));

    expect($html)->toContain(e(__('tasks.columns.assignee_inactive')))->toContain('var(--danger-600)');
});

it('gives the task title column a minimum width so it never shrinks to one word per line, with the status under it', function () {
    $task = scrTask(['title' => 'Liên hệ nhân chứng', 'decline_reason' => 'Đang trực phiên toà'], TaskStatus::Declined);

    $this->actingAs($this->lead, 'web');
    $page = scrList('all');
    $title = $page->instance()->getTable()->getColumn('title');

    expect($title->getExtraHeaderAttributes()['style'] ?? '')->toContain('min-width:15rem');

    // Trạng thái và lý do trả lại ngay dưới tiêu đề, thay cột "Trạng thái" riêng (đo: tám cột không vừa 1280–1366px).
    expect($page->instance()->getTable()->getColumn('status'))->toBeNull();
    $html = (string) TaskColumns::renderStatus($task);
    expect($html)->toContain(e(__('tasks.status.declined')))->toContain('Đang trực phiên toà');
    $page->assertSee('Đang trực phiên toà');
});

it('hides the column that is always me: assignee on awaiting/active, assigner on assigned', function () {
    scrTask(['title' => 'Một việc']);

    $this->actingAs($this->lan, 'web');
    scrList('awaiting')->assertTableColumnHidden('assignee.name')->assertTableColumnVisible('assigner.name');
    scrList('active')->assertTableColumnHidden('assignee.name')->assertTableColumnVisible('assigner.name');
    scrList('all')->assertTableColumnVisible('assignee.name')->assertTableColumnVisible('assigner.name');

    $this->actingAs($this->lead, 'web');
    scrList('assigned')->assertTableColumnHidden('assigner.name')->assertTableColumnVisible('assignee.name');
    scrMatterTab()->assertTableColumnVisible('assignee.name')->assertTableColumnVisible('assigner.name');

    $this->actingAs($this->lan, 'web');
    $this->livewire(TasksAwaitingMeWidget::class)->assertTableColumnHidden('assignee.name');
    $this->livewire(MyActiveTasksWidget::class)->assertTableColumnHidden('assignee.name');
    $this->livewire(TasksIAssignedWidget::class)->assertTableColumnHidden('assigner.name')->assertTableColumnVisible('assignee.name');
});

// =============================================================================================
// 7. TÌM THEO TÊN NGƯỜI
// =============================================================================================

it('finds tasks by the full name of the assignee or the assigner', function () {
    $forLan = scrTask(['title' => 'Soạn hợp đồng']);
    $forTung = scrTask(['title' => 'Nộp đơn', 'assignee_id' => $this->tung->id]);
    $byLan = Task::factory()->create(['title' => 'Việc chung chị Lan giao', 'assigned_by' => $this->lan->id, 'assignee_id' => $this->tung->id]);

    $this->actingAs($this->manager, 'web');

    scrList('all')
        ->searchTable('Ngô Thị Lan')
        ->assertCanSeeTableRecords([$forLan, $byLan])
        ->assertCanNotSeeTableRecords([$forTung]);

    // "đ" như "d" (LetterD), như mọi ô tìm khác. (Các dấu khác do collation của MariaDB lo; SQLite của bộ test
    // so theo byte — docblock `LetterD`.)
    scrList('all')
        ->searchTable('dinh')
        ->assertCanSeeTableRecords([$forTung, $byLan])
        ->assertCanNotSeeTableRecords([$forLan]);
});

it('finds the tasks of someone who has left the firm by name', function () {
    $task = scrTask(['title' => 'Việc của người đã nghỉ']);
    $this->lan->delete();

    $this->actingAs($this->lead, 'web');

    scrList('all')->searchTable('Ngô Thị Lan')->assertCanSeeTableRecords([$task]);
});

it('never finds a restricted matter\'s task by a person\'s name for someone who cannot see it', function () {
    $secret = scrTask(['title' => 'Việc kín', 'assigned_by' => $this->admin->id, 'assignee_id' => $this->lead->id], matter: $this->restricted);
    $open = scrTask(['title' => 'Việc thường', 'assignee_id' => $this->lead->id, 'assigned_by' => $this->manager->id]);

    $this->actingAs($this->manager, 'web');

    scrList('all')->searchTable('Luật sư Khang')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$secret]);
});

// =============================================================================================
// 8. BỘ LỌC NHANH
// =============================================================================================

it('shows only open work on the "All" tab by default, with a way to see closed work', function () {
    $open = scrTask(['title' => 'Còn mở']);
    $done = scrTask(['title' => 'Đã xong'], TaskStatus::Completed);
    $cancelled = scrTask(['title' => 'Đã huỷ'], TaskStatus::Cancelled);

    $this->actingAs($this->lead, 'web');

    scrList('all')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done, $cancelled])
        ->filterTable('open_only', false)
        ->assertCanSeeTableRecords([$open, $done, $cancelled]);

    // Tab "Tôi đã giao" vẫn giữ việc vừa hoàn thành (ghi chú kết quả) — bộ lọc chỉ áp ở tab "Tất cả".
    scrList('assigned')->assertCanSeeTableRecords([$open, $done]);
});

it('filters work due today and in the next seven days, open work only', function () {
    $today = scrTask(['title' => 'Hạn hôm nay', 'due_date' => today()]);
    $inSeven = scrTask(['title' => 'Hạn sau 7 ngày', 'due_date' => today()->addDays(7)]);
    $inEight = scrTask(['title' => 'Hạn sau 8 ngày', 'due_date' => today()->addDays(8)]);
    $yesterday = scrTask(['title' => 'Hạn hôm qua', 'due_date' => today()->subDay()]);
    $doneToday = scrTask(['title' => 'Xong, hạn hôm nay', 'due_date' => today()], TaskStatus::Completed);
    $noDue = scrTask(['title' => 'Không hạn']);

    $this->actingAs($this->lead, 'web');

    scrList('all')->filterTable('open_only', false)->filterTable('due_today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$inSeven, $inEight, $yesterday, $doneToday, $noDue]);

    scrList('all')->filterTable('open_only', false)->filterTable('due_week')
        ->assertCanSeeTableRecords([$today, $inSeven])
        ->assertCanNotSeeTableRecords([$inEight, $yesterday, $doneToday, $noDue]);
});

it('filters "my open work": what I hold, not what I gave or returned', function () {
    $mine = scrTask(['title' => 'Của tôi, chờ nhận']);
    $mineActive = scrTask(['title' => 'Của tôi, đang làm'], TaskStatus::Accepted);
    $returned = scrTask(['title' => 'Tôi đã trả lại'], TaskStatus::Declined);
    $mineDone = scrTask(['title' => 'Của tôi, đã xong'], TaskStatus::Completed);
    $given = Task::factory()->create(['title' => 'Tôi giao', 'assigned_by' => $this->lan->id, 'assignee_id' => $this->tung->id]);

    $this->actingAs($this->lan, 'web');

    scrList('all')->filterTable('open_only', false)->filterTable('mine_open')
        ->assertCanSeeTableRecords([$mine, $mineActive])
        ->assertCanNotSeeTableRecords([$returned, $mineDone, $given]);
});

it('labels the reassignment filter short and moves the explanation into its help text', function () {
    $this->actingAs($this->lead, 'web');

    expect(__('tasks.filters.needs_reassignment'))->toBe('Cần giao lại')
        ->and(__('tasks.filters.needs_reassignment_help'))->toContain('nghỉ');

    scrList('all')->assertSee(__('tasks.filters.needs_reassignment_help'));
});

it('gives the matter\'s task tab the overdue, needs-reassignment and open filters', function () {
    $overdue = scrTask(['title' => 'Quá hạn', 'due_date' => today()->subDays(2)], TaskStatus::Accepted);
    $fine = scrTask(['title' => 'Còn hạn', 'due_date' => today()->addDays(2)]);
    $done = scrTask(['title' => 'Đã xong', 'due_date' => today()->subDays(2)], TaskStatus::Completed);
    $stuck = scrTask(['title' => 'Người đã nghỉ', 'assignee_id' => $this->tung->id]);
    $this->tung->update(['is_active' => false]);

    $this->actingAs($this->lead, 'web');

    scrMatterTab()->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$fine, $done, $stuck]);

    scrMatterTab()->filterTable('needs_reassignment')
        ->assertCanSeeTableRecords([$stuck])
        ->assertCanNotSeeTableRecords([$overdue, $fine, $done]);

    scrMatterTab()->filterTable('open')
        ->assertCanSeeTableRecords([$overdue, $fine, $stuck])
        ->assertCanNotSeeTableRecords([$done]);
});

// =============================================================================================
// 5. THÔNG BÁO MỞ THẲNG KHUNG "XEM"; KHUNG "XEM" CÓ NÚT, MÃ VỤ BẤM ĐƯỢC, DIỄN BIẾN
// =============================================================================================

it('points every task bell, mail and reminder at the view frame of that very task, on the right tab', function () {
    $task = scrTask(['title' => 'Soạn đơn']);
    $query = 'tableAction=view&tableActionRecord='.$task->id;

    $bell = (new TaskAlert($task, TaskAlert::ASSIGNED, 'Luật sư Khang', 1))->toDatabase($this->lan);
    expect($bell['actions'][0]['url'])->toContain('tab=awaiting')->toContain($query);

    $declinedBell = (new TaskAlert($task, TaskAlert::DECLINED, 'Ngô Thị Lan', 2))->toDatabase($this->lead);
    expect($declinedBell['actions'][0]['url'])->toContain('tab=assigned')->toContain($query);

    expect(TaskReminderAlert::urlFor(RemindPendingWork::AWAITING, $task, null))->toContain('tab=awaiting')->toContain($query);

    // Người vừa mất việc không còn xem được nó: chỉ tab, không id việc.
    $away = (new TaskAlert($task, TaskAlert::REASSIGNED_AWAY, 'Luật sư Khang', 3))->toDatabase($this->tung);
    expect($away['actions'][0]['url'])->not->toContain('tableActionRecord');
});

it('opens the view frame from the link for whoever can see the task, even after it moved tab or closed', function () {
    $task = scrTask(['title' => 'Soạn đơn']);

    $this->actingAs($this->lan, 'web');
    $this->get(TaskResource::taskUrl($task, 'awaiting'))
        ->assertOk()
        ->assertSeeHtml("mountAction('view'")
        ->assertSeeHtml('\\u0022recordKey\\u0022:'.$task->id.',');

    // Nhận việc xong rồi mới bấm lời báo cũ (tab "Chờ tôi nhận"): khung vẫn mở.
    $task->update(['status' => TaskStatus::Accepted, 'accepted_at' => now()]);
    scrList('awaiting')
        ->call('mountAction', 'view', [], ['table' => true, 'recordKey' => (string) $task->id, 'mountedFromUrl' => true])
        ->tap(fn ($page) => expect(scrMounted($page))->toBe(['view:'.$task->id]));

    // Việc đã huỷ, mở từ tab "Tất cả" (mặc định chỉ việc còn mở): khung vẫn mở.
    $task->update(['status' => TaskStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Không cần nữa']);
    $this->actingAs($this->lead, 'web');
    scrList('all')
        ->call('mountAction', 'view', [], ['table' => true, 'recordKey' => (string) $task->id, 'mountedFromUrl' => true])
        ->tap(fn ($page) => expect(scrMounted($page))->toBe(['view:'.$task->id]));
});

it('does not open the view frame from a link for someone who can no longer see the task', function () {
    $secret = scrTask(['title' => 'Việc kín', 'assigned_by' => $this->admin->id, 'assignee_id' => $this->lead->id], matter: $this->restricted);
    $moved = scrTask(['title' => 'Việc đã chuyển']);
    app(ReassignTask::class)->handle($moved, $this->lead, $this->tung);

    $this->actingAs($this->manager, 'web');
    scrList('all')
        ->call('mountAction', 'view', [], ['table' => true, 'recordKey' => (string) $secret->id, 'mountedFromUrl' => true])
        ->tap(fn ($page) => expect(scrMounted($page))->toBe([]))
        ->assertDontSee('Việc kín');

    $this->actingAs($this->lan, 'web');
    scrList('active')
        ->call('mountAction', 'view', [], ['table' => true, 'recordKey' => (string) $moved->id, 'mountedFromUrl' => true])
        ->tap(fn ($page) => expect(scrMounted($page))->toBe([]))
        ->assertDontSee('Việc đã chuyển');
});

it('offers in the view frame exactly the row buttons the viewer may use, and they work', function () {
    $task = scrTask(['title' => 'Soạn đơn']);

    $view = fn (): TestAction => TestAction::make('view')->table($task);

    $this->actingAs($this->lan, 'web');
    scrList('awaiting')
        ->assertActionVisible([$view(), TestAction::make('accept')])
        ->assertActionVisible([$view(), TestAction::make('decline')])
        // "Hoàn thành" ngay khi chưa nhận (nhận và xong một lượt — `TaskPolicy::complete`): cùng cổng của dòng.
        ->assertActionVisible([$view(), TestAction::make('complete')])
        ->assertActionHidden([$view(), TestAction::make('edit')])
        ->assertActionHidden([$view(), TestAction::make('cancelTask')])
        ->assertActionHidden([$view(), TestAction::make('reassign')])
        ->callAction([$view(), TestAction::make('accept')]);

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted);

    $this->actingAs($this->lead, 'web');
    scrList('assigned')
        ->assertActionVisible([TestAction::make('view')->table($task), TestAction::make('edit')])
        ->assertActionVisible([TestAction::make('view')->table($task), TestAction::make('reassign')])
        ->assertActionVisible([TestAction::make('view')->table($task), TestAction::make('cancelTask')])
        ->assertActionHidden([TestAction::make('view')->table($task), TestAction::make('accept')])
        ->assertActionHidden([TestAction::make('view')->table($task), TestAction::make('complete')])
        // Nút "Huỷ việc" ở chân khung là thật (không bị nút "Đóng" của khung đè), và hỏi lý do như trên dòng.
        ->callAction([TestAction::make('view')->table($task), TestAction::make('cancelTask')], data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required'])
        ->setActionData(['reason' => 'Khách rút yêu cầu'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($task->fresh())->status->toBe(TaskStatus::Cancelled)->cancel_reason->toBe('Khách rút yêu cầu');
});

it('links the matter code in the view frame to the matter\'s task tab, and says "general" otherwise', function () {
    $task = scrTask(['title' => 'Soạn đơn']);
    $general = Task::factory()->create(['title' => 'Việc chung', 'assigned_by' => $this->lead->id, 'assignee_id' => $this->lan->id]);

    $this->actingAs($this->lan, 'web');

    scrList('awaiting')
        ->mountAction(TestAction::make('view')->table($task))
        ->assertSeeHtml('href="'.e(TaskColumns::matterTabUrl($this->matter->id)).'"');

    // Chính khung (không phải cột "Vụ việc" của bảng, cùng đường dẫn) mang liên kết.
    $frame = view('filament.admin.tasks.task-detail', ['task' => $task->fresh(), 'viewer' => $this->lan])->render();
    expect($frame)->toContain('href="'.e(TaskColumns::matterTabUrl($this->matter->id)).'"');

    $html = view('filament.admin.tasks.task-detail', ['task' => $general->fresh(), 'viewer' => $this->lan])->render();
    expect($html)->toContain(e(__('tasks.detail.general')))->not->toContain('relation=');
});

it('shows the history of the task in the view frame: assigned, returned with reason, reassigned, reopened with comment, due changed', function () {
    $this->actingAs($this->lead, 'web');
    $task = app(AssignTask::class)->handle(
        actor: $this->lead, matter: $this->matter, assignee: $this->lan, title: 'Soạn đơn',
        description: null, dueDate: today()->addDays(5)->toDateString(), priority: TaskPriority::Normal,
    );
    app(DeclineTask::class)->handle($task->fresh(), $this->lan, 'Đang quá tải tuần này');
    app(ReassignTask::class)->handle($task->fresh(), $this->lead, $this->tung, today()->addDays(9)->toDateString(), setDueDate: true);
    $fresh = $task->fresh();
    $fresh->update(['status' => TaskStatus::Completed, 'accepted_at' => now(), 'completed_at' => now()]);
    app(ReopenTask::class)->handle($task->fresh(), $this->lead, 'Thiếu chữ ký của khách');
    app(UpdateTask::class)->handle(
        task: $task->fresh(), actor: $this->lead, title: 'Soạn đơn', description: null,
        dueDate: today()->addDays(12)->toDateString(), priority: TaskPriority::High,
        expectedUpdatedAt: null,
    );

    $html = view('filament.admin.tasks.task-detail', ['task' => $task->fresh(), 'viewer' => $this->lead])->render();

    expect($html)->toContain(e(__('tasks.detail.history')))
        ->toContain(e(__('tasks.timeline.assigned', ['who' => 'Luật sư Khang', 'assignee' => 'Ngô Thị Lan'])))
        ->toContain(e(__('tasks.timeline.declined', ['who' => 'Ngô Thị Lan'])))
        ->toContain('Đang quá tải tuần này')
        ->toContain(e(__('tasks.timeline.reassigned', ['who' => 'Luật sư Khang', 'from' => 'Ngô Thị Lan', 'to' => 'Đinh Văn Tùng'])))
        ->toContain(e(__('tasks.timeline.reopened', ['who' => 'Luật sư Khang'])))
        ->toContain(e(__('tasks.timeline.comment', ['text' => 'Thiếu chữ ký của khách'])))
        ->toContain(e(__('tasks.timeline.reason', ['text' => 'Đang quá tải tuần này'])))
        ->toContain(e(__('tasks.timeline.due_changed', [
            'from' => today()->addDays(5)->format('d/m/Y'),
            'to' => today()->addDays(9)->format('d/m/Y'),
        ])))
        ->toContain(e(__('tasks.notice.changes.due_date', [
            'from' => today()->addDays(9)->format('d/m/Y'),
            'to' => today()->addDays(12)->format('d/m/Y'),
        ])));
});

it('never shows another task\'s history in the view frame', function () {
    $this->actingAs($this->lead, 'web');
    $mine = scrTask(['title' => 'Việc của tôi']);
    $other = scrTask(['title' => 'Việc khác', 'assignee_id' => $this->tung->id]);
    app(DeclineTask::class)->handle($other, $this->tung, 'Lý do của việc khác');

    $html = view('filament.admin.tasks.task-detail', ['task' => $mine->fresh(), 'viewer' => $this->lan])->render();

    expect($html)->not->toContain('Lý do của việc khác');
});

// =============================================================================================
// 9. BREADCRUMB, Ô ƯU TIÊN, LỊCH HẠN
// =============================================================================================

it('capitalises the breadcrumb like every other page', function () {
    expect(TaskResource::getBreadcrumb())->toBe('Công việc');

    $this->actingAs($this->lead, 'web')
        ->get(TaskResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertDontSee('công việc</a>', false);
});

it('has no clear button on the required priority field and no past dates in the assign calendar', function () {
    $this->actingAs($this->lead, 'web');

    scrList('all')
        ->mountAction('create')
        ->assertSchemaComponentExists('priority', 'mountedActionSchema0', fn ($field): bool => $field->canSelectPlaceholder() === false)
        ->assertSchemaComponentExists('due_date', 'mountedActionSchema0', fn ($field): bool => $field->getMinDate() === today()->toDateString());

    scrMatterTab()
        ->mountAction(TestAction::make('assign')->table())
        ->assertSchemaComponentExists('due_date', 'mountedActionSchema0', fn ($field): bool => $field->getMinDate() === today()->toDateString());

    // "Sửa" giữ nguyên: một việc đã quá hạn vẫn mở được khung sửa với hạn cũ.
    $task = scrTask(['title' => 'Quá hạn', 'due_date' => today()->subDays(3)]);
    scrList('assigned')
        ->mountAction(TestAction::make('edit')->table($task))
        ->assertSchemaComponentExists('due_date', 'mountedActionSchema0', fn ($field): bool => $field->getMinDate() === null)
        ->assertSchemaComponentExists('priority', 'mountedActionSchema0', fn ($field): bool => $field->canSelectPlaceholder() === false);
});
