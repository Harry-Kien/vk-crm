<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 1 — trang "Công việc" (`/admin/tasks`): bốn tab (Chờ tôi nhận, Đang làm, Tôi đã giao,
 * Tất cả), các nút theo quyền trên từng dòng, "Giao việc chung" cho quản lý/quản trị, và — điều quan
 * trọng nhất — KHÔNG lộ việc của vụ hạn chế cho ai không xem được vụ, kể cả qua tìm kiếm và bộ lọc.
 * Mọi khẳng định đi qua Livewire (màn hình), không gọi thẳng Action.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Quản lý Minh']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->accountant = User::factory()->withRole(Role::Accountant)->create(['name' => 'Kế toán Ngân']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->lead->id,
        'title' => 'Vụ kín của tập đoàn X',
    ]);

    $this->pending = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn khởi kiện',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'due_date' => today()->subDays(2),
        'priority' => TaskPriority::Urgent,
    ]);
    $this->accepted = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Thu thập chứng cứ',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
    ]);
    $this->declined = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Liên hệ nhân chứng',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->otherAssistant->id,
    ]);
    $this->secret = Task::factory()->forMatter($this->restricted)->create([
        'title' => 'Việc bí mật của vụ kín',
        'assigned_by' => $this->admin->id,
        'assignee_id' => $this->lead->id,
    ]);
    $this->general = Task::factory()->create([
        'title' => 'Đối chiếu công nợ quý 3',
        'assigned_by' => $this->manager->id,
        'assignee_id' => $this->accountant->id,
    ]);
});

function tkListPage(?string $tab = null)
{
    return test()->livewire(ListTasks::class, $tab === null ? [] : ['activeTab' => $tab]);
}

// =============================================================================================
// TRUY CẬP VÀ TAB
// =============================================================================================

it('opens the task page for every staff role', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create(), 'web')
        ->get(TaskResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee(__('tasks.tabs.awaiting'))
        ->assertSee(__('tasks.tabs.assigned'));
})->with(Role::cases());

it('never opens the task page for a portal account', function () {
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);

    $this->actingAs($clientUser, 'client')
        ->get(TaskResource::getUrl('index', panel: 'admin'))
        ->assertRedirect();
});

it('splits the assistant\'s work into awaiting, in progress and assigned, and shows the overdue one in red', function () {
    $this->actingAs($this->assistant, 'web');

    tkListPage('awaiting')
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->accepted, $this->declined, $this->secret, $this->general])
        ->assertSee(__('tasks.due.overdue', ['count' => 2]))
        ->assertSeeHtml('var(--danger-600)');

    tkListPage('active')
        ->assertCanSeeTableRecords([$this->accepted])
        ->assertCanNotSeeTableRecords([$this->pending, $this->declined]);

    tkListPage('assigned')->assertCountTableRecords(0);
});

it('shows the lead the tasks they assigned, including the declined one with its reason', function () {
    // Việc hoàn thành trong `Task::RECENTLY_COMPLETED_DAYS` ngày qua còn ở "Tôi đã giao": lời báo "đã
    // hoàn thành" trỏ tới tab này, người giao xem kết quả và ghi chú ở đó. Cũ hơn, hay đã huỷ: không.
    $done = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Việc đã xong',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'completed_at' => now()->subDays(Task::RECENTLY_COMPLETED_DAYS)->addHour(),
        'completion_note' => 'Đã nộp ở toà, biên nhận trong tab Tài liệu',
    ]);
    $doneLongAgo = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Việc xong từ lâu',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
        'completed_at' => now()->subDays(Task::RECENTLY_COMPLETED_DAYS)->subHour(),
    ]);
    $cancelled = Task::factory()->forMatter($this->matter)->status(TaskStatus::Cancelled)->create([
        'title' => 'Việc đã huỷ',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
    ]);
    $this->actingAs($this->lead, 'web');

    tkListPage('assigned')
        ->assertCanSeeTableRecords([$this->pending, $this->accepted, $this->declined, $done])
        ->assertCanNotSeeTableRecords([$this->secret, $this->general, $doneLongAgo, $cancelled])
        ->assertSee('Đang quá tải việc')
        // Ghi chú hoàn thành hiện ngay dưới trạng thái, như lý do trả lại.
        ->assertSee('Đã nộp ở toà, biên nhận trong tab Tài liệu');

    // Việc của vụ hạn chế giao cho chính luật sư phụ trách: ở "Chờ tôi nhận" của họ.
    tkListPage('awaiting')->assertCanSeeTableRecords([$this->secret]);
});

it('shows a manager every task they may see, but never the restricted matter\'s, not even by searching for it', function () {
    $this->actingAs($this->manager, 'web');

    tkListPage('all')
        ->assertCanSeeTableRecords([$this->pending, $this->accepted, $this->declined, $this->general])
        ->assertCanNotSeeTableRecords([$this->secret])
        ->assertDontSee('Việc bí mật của vụ kín')
        ->assertDontSee($this->restricted->code)
        ->searchTable('bí mật')
        ->assertCountTableRecords(0)
        ->searchTable($this->restricted->code)
        ->assertCountTableRecords(0);
});

it('shows the accountant only the general task given to them, and no matter task at all', function () {
    $this->actingAs($this->accountant, 'web');

    tkListPage('all')
        ->assertCanSeeTableRecords([$this->general])
        ->assertCanNotSeeTableRecords([$this->pending, $this->accepted, $this->declined, $this->secret]);
});

it('shows an admin the restricted task, which the manager could not see', function () {
    $this->actingAs($this->admin, 'web');

    tkListPage('all')->assertCanSeeTableRecords([$this->secret, $this->general, $this->pending]);
});

it('counts the tasks waiting for me on the navigation badge, and shows none when there are none', function () {
    $this->actingAs($this->assistant, 'web');
    expect(TaskResource::getNavigationBadge())->toBe('1');

    $this->actingAs($this->manager, 'web');
    expect(TaskResource::getNavigationBadge())->toBeNull();
});

it('lets a manager filter by overdue and find the tasks whose assignee has left', function () {
    $this->otherAssistant->forceFill(['is_active' => false])->save();
    $this->actingAs($this->manager, 'web');

    tkListPage('all')
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$this->pending])
        ->assertCanNotSeeTableRecords([$this->accepted, $this->declined, $this->general]);

    tkListPage('all')
        ->filterTable('needs_reassignment')
        ->assertCanSeeTableRecords([$this->declined])
        ->assertCanNotSeeTableRecords([$this->pending, $this->accepted, $this->general])
        ->assertSee(__('tasks.columns.assignee_inactive'));
});

it('flags the open task of someone taken off the matter team as needing reassignment, and offers the lead the button', function () {
    $onTeam = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của người còn trong đội',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->otherAssistant->id,
    ]);
    $leftTeam = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc của người đã rời đội',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
    ]);
    $finished = Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create([
        'title' => 'Việc đã xong của người đã rời đội',
        'assigned_by' => $this->lead->id,
        'assignee_id' => $this->assistant->id,
    ]);
    // Chuyển sang ghế "người theo dõi" cũng là rời đội ngũ GIỮ VIỆC.
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    $this->actingAs($this->manager, 'web');
    tkListPage('all')
        ->filterTable('needs_reassignment')
        ->assertCanSeeTableRecords([$this->pending, $this->accepted, $leftTeam])
        ->assertCanNotSeeTableRecords([$onTeam, $this->declined, $this->general, $finished])
        ->assertSee(__('tasks.columns.assignee_off_team'));

    $this->actingAs($this->lead, 'web');
    tkListPage('assigned')
        ->assertSee(__('tasks.columns.assignee_off_team'))
        ->assertTableActionVisible('reassign', $leftTeam)
        ->callTableAction('reassign', $leftTeam, data: ['assignee_id' => $this->otherAssistant->id])
        ->assertHasNoTableActionErrors();

    expect($leftTeam->fresh())
        ->assignee_id->toBe($this->otherAssistant->id)
        ->status->toBe(TaskStatus::Pending);
});

it('lets a manager filter by matter, offering only the codes of matters whose tasks they can see', function () {
    $other = Matter::factory()->create();
    $untasked = Matter::factory()->create();
    $onOther = Task::factory()->forMatter($other)->create([
        'title' => 'Việc ở vụ khác',
        'assigned_by' => $this->manager->id,
        'assignee_id' => $this->lead->id,
    ]);
    $this->actingAs($this->manager, 'web');

    $page = tkListPage('all');
    $options = $page->instance()->getTable()->getFilter('matter_id')->getOptions();

    ksort($options);

    expect($options)->toBe([$this->matter->id => $this->matter->code, $other->id => $other->code])
        ->not->toHaveKey($this->restricted->id)
        ->not->toHaveKey($untasked->id);

    $page->filterTable('matter_id', $other->id)
        ->assertCanSeeTableRecords([$onOther])
        ->assertCanNotSeeTableRecords([$this->pending, $this->accepted, $this->declined, $this->general]);

    // Id của vụ hạn chế gửi tay lên bộ lọc: không ra dòng nào, không lộ mã.
    tkListPage('all')
        ->filterTable('matter_id', $this->restricted->id)
        ->assertCountTableRecords(0)
        ->assertDontSee($this->restricted->code);
});

it('offers the accountant no matter at all in the matter filter', function () {
    $this->actingAs($this->accountant, 'web');

    expect(tkListPage('all')->instance()->getTable()->getFilter('matter_id')->getOptions())->toBe([]);
});

// =============================================================================================
// NÚT TRÊN DÒNG
// =============================================================================================

it('accepts a task with one click and tells the person so in Vietnamese', function () {
    $this->actingAs($this->assistant, 'web');

    tkListPage('awaiting')
        ->assertTableActionVisible('accept', $this->pending)
        ->callTableAction('accept', $this->pending)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.actions.accept_success'));

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Accepted)
        ->and(Activity::query()->where('event', 'task_accepted')->count())->toBe(1);
});

it('requires a reason to decline, and passes it on', function () {
    $this->actingAs($this->assistant, 'web');

    tkListPage('awaiting')
        ->callTableAction('decline', $this->pending, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Pending);

    tkListPage('awaiting')
        ->callTableAction('decline', $this->pending, data: ['reason' => 'Xung đột lợi ích với khách cũ'])
        ->assertHasNoTableActionErrors();

    expect($this->pending->fresh())
        ->status->toBe(TaskStatus::Declined)
        ->decline_reason->toBe('Xung đột lợi ích với khách cũ');
});

it('completes an accepted task with an optional note', function () {
    $this->actingAs($this->assistant, 'web');

    tkListPage('active')
        ->callTableAction('complete', $this->accepted, data: ['note' => 'Đã lưu ở tab tài liệu'])
        ->assertHasNoTableActionErrors();

    expect($this->accepted->fresh())
        ->status->toBe(TaskStatus::Completed)
        ->completion_note->toBe('Đã lưu ở tab tài liệu');
});

it('shows the assignee only accept, decline, complete and view — never edit, reassign or cancel', function () {
    $this->actingAs($this->assistant, 'web');

    tkListPage('awaiting')
        ->assertTableActionVisible('accept', $this->pending)
        ->assertTableActionVisible('decline', $this->pending)
        // Kiểm toán màn hình 2026-10-21: "Hoàn thành" một việc chưa nhận nằm trong "⋮" (`completePending`) — dòng chờ
        // nhận chỉ có một nút chính, "Đã nhận".
        ->assertTableActionHidden('complete', $this->pending)
        ->assertTableActionVisible('completePending', $this->pending)
        ->assertTableActionVisible('view', $this->pending)
        ->assertTableActionHidden('edit', $this->pending)
        ->assertTableActionHidden('reassign', $this->pending)
        ->assertTableActionHidden('cancel', $this->pending);
});

it('shows the assigner edit, reassign and cancel, but not accept', function () {
    $this->actingAs($this->lead, 'web');

    tkListPage('assigned')
        ->assertTableActionHidden('accept', $this->pending)
        ->assertTableActionVisible('edit', $this->pending)
        ->assertTableActionVisible('reassign', $this->declined)
        ->assertTableActionVisible('cancel', $this->declined);
});

it('reassigns a declined task from the list, offering only the eligible team members', function () {
    $this->actingAs($this->lead, 'web');

    // Ô chọn chỉ nhận người trong danh sách: một id ngoài đội ngũ bị chặn ngay ở form.
    tkListPage('assigned')
        ->callTableAction('reassign', $this->declined, data: ['assignee_id' => $this->manager->id])
        ->assertHasTableActionErrors(['assignee_id']);

    expect($this->declined->fresh()->status)->toBe(TaskStatus::Declined);

    tkListPage('assigned')
        ->callTableAction('reassign', $this->declined, data: ['assignee_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    expect($this->declined->fresh())
        ->status->toBe(TaskStatus::Pending)
        ->assignee_id->toBe($this->assistant->id);
});

it('edits a task from the list, with the form limits equal to the columns', function () {
    $this->actingAs($this->lead, 'web');

    tkListPage('assigned')
        ->callTableAction('edit', $this->accepted, data: ['title' => str_repeat('a', Task::TITLE_MAX_LENGTH + 1)])
        ->assertHasTableActionErrors(['title' => 'max']);

    tkListPage('assigned')
        ->callTableAction('edit', $this->accepted, data: [
            'title' => 'Thu thập chứng cứ (bổ sung)',
            'priority' => TaskPriority::High->value,
            'due_date' => today()->addDays(5)->toDateString(),
        ])
        ->assertHasNoTableActionErrors();

    expect($this->accepted->fresh())
        ->title->toBe('Thu thập chứng cứ (bổ sung)')
        ->priority->toBe(TaskPriority::High);
});

it('cancels with a reason from the list', function () {
    $this->actingAs($this->lead, 'web');

    tkListPage('assigned')
        ->callTableAction('cancel', $this->accepted, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    tkListPage('assigned')
        ->callTableAction('cancel', $this->accepted, data: ['reason' => 'Khách đã tự nộp'])
        ->assertHasNoTableActionErrors();

    expect($this->accepted->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('turns a refusal of the action into a Vietnamese message instead of an error page', function () {
    $this->actingAs($this->assistant, 'web');
    // Kiểm toán 2026-10-21 (N2): tab "Tất cả" — "Chờ tôi nhận" chỉ có việc người đó còn giữ được (tài khoản còn hoạt
    // động), nên dòng của một người vừa bị vô hiệu rời tab đó ở lần vẽ lại.
    $component = tkListPage('all');

    // Tài khoản bị vô hiệu trong lúc màn hình đang mở: nút còn hiện (policy không đọc `is_active`), Action
    // từ chối dưới khoá, và người dùng đọc một câu tiếng Việt thay vì một trang lỗi.
    $this->assistant->forceFill(['is_active' => false])->save();

    $component->callTableAction('accept', $this->pending)
        ->assertNotified(__('actions.failed_title'));

    expect($this->pending->fresh()->status)->toBe(TaskStatus::Pending);
});

it('shows the task details, with the matter code but never the client\'s name', function () {
    $this->pending->forceFill(['description' => 'Theo mẫu số 23-DS'])->save();
    $this->actingAs($this->assistant, 'web');

    $component = tkListPage('awaiting')->mountTableAction('view', $this->pending);
    $modal = (string) $component->instance()->getMountedActions()[0]->getModalContent()->render();

    expect($modal)->toContain('Theo mẫu số 23-DS')
        ->toContain($this->matter->code)
        ->toContain('Luật sư Khang')
        ->not->toContain(e($this->matter->client->name));
});

// =============================================================================================
// GIAO VIỆC CHUNG
// =============================================================================================

it('lets a manager give a general task to the accountant, with the title limit of the column', function () {
    $this->actingAs($this->manager, 'web');

    tkListPage()
        ->callAction('create', data: [
            'title' => str_repeat('a', Task::TITLE_MAX_LENGTH + 1),
            'assignee_id' => $this->accountant->id,
        ])
        ->assertHasActionErrors(['title' => 'max']);

    tkListPage()
        ->callAction('create', data: [
            'title' => 'Lập báo cáo thuế tháng 10',
            'assignee_id' => $this->accountant->id,
            'priority' => TaskPriority::High->value,
            'due_date' => today()->addDays(3)->toDateString(),
            'description' => 'Gửi trước ngày 20',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('tasks.actions.assign_success'));

    $task = Task::query()->where('title', 'Lập báo cáo thuế tháng 10')->sole();
    expect($task->matter_id)->toBeNull()
        ->and($task->assignee_id)->toBe($this->accountant->id)
        ->and($task->assigned_by)->toBe($this->manager->id)
        ->and($task->priority)->toBe(TaskPriority::High);
});

it('shows the "Giao việc" button to lawyers and assistants, and hides it from the accountant', function (string $who, bool $visible) {
    // Làn fa1 (phán quyết 3): mọi nhân sự có `matter.view` giao được việc chung.
    $this->actingAs(test()->{$who}, 'web');

    $visible ? tkListPage()->assertActionVisible('create') : tkListPage()->assertActionHidden('create');
})->with([['lead', true], ['assistant', true], ['accountant', false]]);
