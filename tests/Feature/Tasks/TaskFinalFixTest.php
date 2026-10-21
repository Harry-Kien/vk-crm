<?php

use App\Actions\Schedule\RemindPendingWork;
use App\Actions\Task\AssignTask;
use App\Actions\Task\TaskAssignees;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\MyActiveTasksWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\TasksIAssignedWidget;
use App\Jobs\SendReassignmentDigest;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Mail\Staff\MatterReassigned;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\TaskReminderAlert;
use App\Support\Tasks\SeatInbox;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Làn tk, vòng sửa cuối 1 — các chỗ nối của luồng giao – nhận nội bộ với main đã gộp (fm: hộp thoại kết thúc
 * vụ; kd: vai Nhân viên kinh doanh; fi: đổi chức danh khi nghỉ) và các chỗ hai Task của làn chưa khớp nhau:
 *  - F1 hộp thoại kết thúc vụ nêu việc nội bộ còn mở và người chưa nhận hồ sơ; nhân viên kinh doanh nhận việc
 *    chung như mọi nhân sự;
 *  - F2 chuông, thư nhắc, thư bàn giao và ô bảng điều khiển không bao giờ trỏ quản trị viên (hay kế toán,
 *    kinh doanh) tới "Việc của tôi" — trang đó là 404 với họ;
 *  - F3 người đã trả lại hồ sơ không nhận việc mới của hồ sơ đó, việc họ còn giữ hiện "Cần giao lại";
 *  - F4 người giữ việc (hay người giao một việc bị trả lại) không còn mở được hồ sơ thì việc "Cần giao lại";
 *  - F5 câu chữ và đường dẫn nói đúng điều hệ thống làm; F6 cổng 404 của ba khối "Việc của tôi".
 * Màn hình qua Livewire hay HTTP; lịch nhắc qua chính lượt lịch.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    tkFfTravel('2026-10-12 09:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0077']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function tkFfTravel(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at, 'Asia/Ho_Chi_Minh'));
}

function tkFfTask(Matter $matter, User $assignee, User $assigner, TaskStatus $status = TaskStatus::Pending, array $extra = []): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'assignee_id' => $assignee->id, 'assigned_by' => $assigner->id, 'assigned_at' => now(), ...$extra,
    ]);
}

/** Danh sách "Công việc", tab "Tất cả", bật bộ lọc "Cần giao lại" — như người quản lý thấy. */
function tkFfNeedsReassignment(User $viewer): Testable
{
    test()->actingAs($viewer, 'web');

    return test()->livewire(ListTasks::class, ['activeTab' => 'all'])->filterTable('needs_reassignment', true);
}

function tkFfReason(Task $task): ?string
{
    return $task->fresh()->load(['assignee' => fn ($q) => $q->withTrashed(), 'assigner' => fn ($q) => $q->withTrashed(), 'matter.team'])->reassignmentReason();
}

function tkFfTeamTab(Matter $matter): Testable
{
    return test()->livewire(TeamRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function tkFfHandOver(Matter $matter, User $actor, User $newLead): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => false,
            'reason' => 'Luật sư cũ nghỉ việc.',
        ])
        ->assertHasNoActionErrors();
}

/** @return list<string>|null chữ của khối "Trước khi kết thúc vụ việc", `null` khi khối ẩn (cùng cách `MatterCloseSafeguardsTest`). */
function tkFfClosingTexts(Testable $component): ?array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};
    $section = $schema->getComponent('closingSafeguards', withHidden: true);

    expect($section)->toBeInstanceOf(Section::class);

    if (! $section->isVisible()) {
        return null;
    }

    return collect($section->getChildSchema()->getFlatComponents(withHidden: false))
        ->filter(fn ($c) => $c instanceof Text)
        ->map(fn ($c) => (string) $c->getContent())
        ->values()
        ->all();
}

// =============================================================================================
// F1 — chỗ nối với main đã gộp
// =============================================================================================

it('lists the open internal tasks and the seats nobody accepted in the closing dialog', function () {
    tkFfTask($this->matter, $this->assistant, $this->lead);
    tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted);
    tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Declined);
    tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed);
    tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Cancelled);
    $this->matter->addTeamMember(User::factory()->withRole(Role::Lawyer)->create(), MatterRole::Associate, assignedBy: $this->lead);

    $this->actingAs($this->lead, 'web');

    $texts = implode("\n", tkFfClosingTexts($this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $this->matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')->setTableActionData(['to_stage' => 'closed'])) ?? []);

    expect($texts)->toContain(__('lifecycle.open_work.tasks', ['count' => 3]))
        ->toContain(__('lifecycle.open_work.seats_pending', ['count' => 1]));
});

it('says nothing about internal work in the closing dialog when every task is done and every seat accepted', function () {
    tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed);

    $this->actingAs($this->lead, 'web');

    $texts = tkFfClosingTexts($this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $this->matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('transitionStage')->setTableActionData(['to_stage' => 'closed'])) ?? [];

    expect(implode("\n", $texts))->not->toContain(__('lifecycle.open_work.tasks', ['count' => 1]))
        ->not->toContain(__('lifecycle.open_work.seats_pending', ['count' => 1]));
});

it('lets a manager give a sales colleague a general task, which they see, accept and find on the dashboard without a seats tile', function () {
    $sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh doanh Minh']);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ListTasks::class)
        ->callAction('create', data: ['assignee_id' => $sales->id, 'title' => 'Gọi lại cho khách tiềm năng'])
        ->assertHasNoActionErrors();
    $task = Task::query()->where('title', 'Gọi lại cho khách tiềm năng')->sole();

    $this->actingAs($sales, 'web');

    Livewire::test(MyWorkQueueWidget::class)
        ->assertSee(__('tasks.dashboard.awaiting'))
        ->assertDontSee(__('tasks.dashboard.seats'))
        ->assertDontSeeHtml(e(TeamMember::getUrl(['user' => $sales->id], panel: 'admin')));

    // Trang "Việc của tôi" không mở cho nhân viên kinh doanh — nên không ô nào được trỏ về đó.
    $this->get(TeamMember::getUrl(['user' => $sales->id], panel: 'admin'))->assertNotFound();

    $this->livewire(ListTasks::class, ['activeTab' => 'awaiting'])
        ->assertCanSeeTableRecords([$task])
        ->callTableAction('accept', $task)
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Accepted);
});

// =============================================================================================
// F2 — người không mở được "Việc của tôi" được dẫn tới tab "Đội ngũ"
// =============================================================================================

it('sends a colleague promoted to admin while a seat waits to the matter\'s team tab, never to the 404 of "Việc của tôi"', function () {
    Mail::fake();
    $promoted = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $colleague = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Tú']);
    $this->matter->addTeamMember($promoted, MatterRole::Associate, assignedBy: $this->lead);
    $this->matter->addTeamMember($colleague, MatterRole::Associate, assignedBy: $this->lead);

    $this->actingAs($this->admin, 'web');
    $this->livewire(EditUser::class, ['record' => $promoted->getRouteKey()])
        ->fillForm(['position' => UserPosition::Admin->value])
        ->call('save')
        ->assertHasNoFormErrors();
    $promoted->refresh();
    expect($promoted->hasRole(Role::Admin->value))->toBeTrue();

    tkFfTravel('2026-10-14 14:00');
    app(RemindPendingWork::class)->handle();

    $teamTab = SeatInbox::teamTabUrl($this->matter->id);
    $bell = $promoted->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data;

    expect($bell['actions'][0]['url'])->toBe($teamTab)
        ->and($bell['body'])->toContain(__('tasks.seat.where.team_tab'))
        ->and($bell['body'])->not->toContain(__('tasks.seat.where.my_work'));

    // Người còn mở được "Việc của tôi" vẫn được trỏ về đó.
    $other = $colleague->notifications()->where('type', MatterAssignmentAlert::class)->sole()->data;
    expect($other['actions'][0]['url'])->toBe(TeamMember::getUrl(['user' => $colleague->id], panel: 'admin'))
        ->and($other['body'])->toContain(__('tasks.seat.where.my_work'));

    Mail::assertSent(MatterAssignmentNotice::class, function (MatterAssignmentNotice $mail) use ($promoted, $teamTab): bool {
        $html = $mail->render();

        return $mail->hasTo($promoted->email)
            && str_contains($html, e($teamTab))
            && str_contains($html, e(__('tasks.seat.email.action.reminder.awaiting_team_tab')))
            && ! str_contains($html, e(TeamMember::getUrl(['user' => $promoted->id], panel: 'admin')));
    });

    $this->actingAs($promoted, 'web');
    $this->get(TeamMember::getUrl(['user' => $promoted->id], panel: 'admin'))->assertNotFound();
    $this->get($teamTab)->assertOk();

    tkFfTeamTab($this->matter)
        ->callTableAction('acceptSeat', $promoted)
        ->assertHasNoTableActionErrors();

    expect(MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $promoted->id)->sole()->accepted_at)->not->toBeNull();
});

it('shows an admin who holds work the dashboard tiles without the seats tile, whose page is a 404 for them', function () {
    Task::factory()->create(['assignee_id' => $this->admin->id, 'assigned_by' => User::factory()->withRole(Role::Admin)->create()->id]);

    $this->actingAs($this->admin, 'web');

    Livewire::test(MyWorkQueueWidget::class)
        ->assertSee(__('tasks.dashboard.awaiting'))
        ->assertDontSee(__('tasks.dashboard.seats'))
        ->assertDontSeeHtml(e(TeamMember::getUrl(['user' => $this->admin->id], panel: 'admin')));

    $this->get(TeamMember::getUrl(['user' => $this->admin->id], panel: 'admin'))->assertNotFound();
});

// =============================================================================================
// F5 — câu chữ và đường dẫn nói đúng điều hệ thống làm
// =============================================================================================

it('asks the new lead to accept the handed-over matter only while their seat still waits, and gives each moved task its status', function () {
    Mail::fake();
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    tkFfTask($this->matter, $this->lead, $this->manager, extra: ['title' => 'Soạn bản tự khai']);

    tkFfHandOver($this->matter, $this->manager, $newLead);

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->hasTo($newLead->email)
        && str_contains($mail->render(), e(__('tasks.handover.email.accept_hint')))
        && str_contains($mail->render(), e(__('tasks.handover.email.task_line', ['title' => 'Soạn bản tự khai', 'status' => TaskStatus::Pending->label(), 'due' => __('tasks.email.no_due_date')]))));

    // Lead mới bấm nhận trước khi thư (thử lại) chạy: không còn gì để bấm.
    $this->actingAs($newLead, 'web');
    tkFfTeamTab($this->matter)->callTableAction('acceptSeat', $newLead)->assertHasNoTableActionErrors();

    Mail::fake();
    (new SendReassignmentDigest($newLead->id, [$this->matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => '']]))->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => ! str_contains($mail->render(), e(__('tasks.handover.email.accept_hint')))
        && ! str_contains($mail->render(), e(__('tasks.handover.email.accept_hint_team_tab'))));
});

it('words the handover digest\'s accept hint for the team tab when the reader has no "Việc của tôi"', function () {
    $mail = new MatterReassigned($this->admin, [[
        'matter' => $this->matter, 'deadlines' => collect(), 'client_requests_moved' => 0,
        'tasks' => collect(), 'followed_tasks' => collect(), 'seat_pending' => true, 'reason' => '',
    ]]);

    expect($mail->render())->toContain(e(__('tasks.handover.email.accept_hint_team_tab')))
        ->not->toContain(e(__('tasks.handover.email.accept_hint')));

    $mine = new MatterReassigned($this->lead, [[
        'matter' => $this->matter, 'deadlines' => collect(), 'client_requests_moved' => 0,
        'tasks' => collect(), 'followed_tasks' => collect(), 'seat_pending' => true, 'reason' => '',
    ]]);

    expect($mine->render())->toContain(e(__('tasks.handover.email.accept_hint')));
});

it('does not ask a manager who handed the matter to themself to accept it', function () {
    Mail::fake();

    tkFfHandOver($this->matter, $this->manager, $this->manager);

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->hasTo($this->manager->email)
        && ! str_contains($mail->render(), e(__('tasks.handover.email.accept_hint'))));
});

it('opens, from each dashboard tile, exactly the rows its number counts', function () {
    $pendingLate = tkFfTask($this->matter, $this->assistant, $this->lead, extra: ['title' => 'Việc chưa nhận đã trễ', 'due_date' => today()->subDays(2)]);
    $acceptedLate = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Việc đang làm đã trễ', 'due_date' => today()->subDay()]);
    $returnedLate = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Declined, ['title' => 'Việc trả lại đã trễ', 'due_date' => today()->subDay()]);
    $onTime = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Việc đang làm còn hạn', 'due_date' => today()->addDays(3)]);

    $links = MyWorkQueueWidget::links($this->assistant);
    expect(MyWorkQueueWidget::countsFor($this->assistant)['overdue'])->toBe(2);

    $this->actingAs($this->assistant, 'web')
        ->get($links['overdue'])
        ->assertOk()
        ->assertSee($pendingLate->title)
        ->assertSee($acceptedLate->title)
        ->assertDontSee($returnedLate->title)
        ->assertDontSee($onTime->title);

    $leadLinks = MyWorkQueueWidget::links($this->lead);
    expect(MyWorkQueueWidget::countsFor($this->lead)['returned'])->toBe(1)
        ->and(MyWorkQueueWidget::countsFor($this->lead)['overdue'])->toBe(0);

    // Lead thấy mọi việc của vụ ở tab "Tất cả" — ô "Việc của tôi đã quá hạn" chỉ mở việc CỦA lead.
    $this->actingAs($this->lead, 'web')
        ->get($leadLinks['overdue'])
        ->assertOk()
        ->assertDontSee($pendingLate->title)
        ->assertDontSee($acceptedLate->title);

    $this->actingAs($this->lead, 'web')
        ->get($leadLinks['returned'])
        ->assertOk()
        ->assertSee($returnedLate->title)
        ->assertDontSee($pendingLate->title)
        ->assertDontSee($onTime->title);

    $this->actingAs($this->assistant, 'web');
    Livewire::test(MyWorkQueueWidget::class)
        ->assertSeeHtml(e($links['overdue']))
        ->assertSeeHtml(e($links['seats']));
});

// Kiểm toán 2026-10-21 (Q3): hai việc quá hạn của cùng một người trong một lượt là MỘT chuông gộp — mỗi ca một việc để
// giữ đúng điều test này ghim (đường dẫn của lời nhắc một việc theo trạng thái).
it('points the overdue reminder of a task nobody accepted yet to "Chờ tôi nhận", where it is listed', function (TaskStatus $status, string $title, string $tab) {
    tkFfTask($this->matter, $this->assistant, $this->lead, $status, ['title' => $title, 'due_date' => '2026-10-13']);

    tkFfTravel('2026-10-14 09:00');
    app(RemindPendingWork::class)->handle();

    $urls = $this->assistant->notifications()->where('type', TaskReminderAlert::class)->get()
        ->filter(fn ($n): bool => $n->data['title'] === __('tasks.reminder.alert.overdue.title'))
        ->mapWithKeys(fn ($n): array => [Task::query()->findOrFail($n->data['viewData']['task_id'])->title => $n->data['actions'][0]['url']])
        ->all();

    expect($urls)->toEqual([$title => TaskResource::getUrl('index', ['tab' => $tab], panel: 'admin')]);
})->with([
    'chưa nhận' => [TaskStatus::Pending, 'Nộp bản sao', 'awaiting'],
    'đang làm' => [TaskStatus::Accepted, 'Đối chiếu chứng cứ', 'active'],
]);

// =============================================================================================
// F6 — cổng 404 của ba khối "Việc của tôi" và ô bảng điều khiển
// =============================================================================================

it('answers 404 to a portal account for every "Việc của tôi" block and the dashboard tile', function (string $widget) {
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->matter->client_id]);
    $this->actingAs($clientUser, 'client');

    Livewire::test($widget)->assertStatus(404);
})->with([
    MatterAssignmentsAwaitingMeWidget::class,
    TasksAwaitingMeWidget::class,
    MyActiveTasksWidget::class,
    TasksIAssignedWidget::class,
    MyWorkQueueWidget::class,
]);

it('answers 404 for the seats block to the roles that have no "Việc của tôi"', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create(), 'web');

    Livewire::test(MatterAssignmentsAwaitingMeWidget::class)->assertStatus(404);
})->with([Role::Admin, Role::Accountant, Role::Sales]);

it('keeps the dashboard tile off the accountant dashboard when there is nothing to do, and a late load shows zeros, not an error', function () {
    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');

    expect(MyWorkQueueWidget::canView())->toBeFalse();
    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertDontSeeLivewire(MyWorkQueueWidget::class);
    // Gộp làn tk (2026-10-16): lần tải trễ (số vừa về 0) không 404 — 404 là hộp báo lỗi giữa trang chủ.
    Livewire::test(MyWorkQueueWidget::class)->assertOk();
});

it('shows every role holding a general task the dashboard tile, with the seats tile only where "Việc của tôi" opens', function (Role $role) {
    $user = User::factory()->withRole($role)->create();
    Task::factory()->create(['assignee_id' => $user->id, 'assigned_by' => $this->admin->is($user) ? $this->manager->id : $this->admin->id]);

    $this->actingAs($user, 'web');

    $component = Livewire::test(MyWorkQueueWidget::class)->assertSee(__('tasks.dashboard.awaiting'));

    in_array($role, [Role::Lawyer, Role::Assistant, Role::Manager], true)
        ? $component->assertSee(__('tasks.dashboard.seats'))
        : $component->assertDontSee(__('tasks.dashboard.seats'));
})->with(Role::cases());

it('renders no tile at all when the only task sits on a matter that became restricted', function () {
    tkFfTask($this->matter, $this->assistant, $this->lead);
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    $this->actingAs($this->assistant, 'web');

    Livewire::test(MyWorkQueueWidget::class)->assertOk()->assertDontSee($this->matter->code);
    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertDontSeeLivewire(MyWorkQueueWidget::class);
});

// =============================================================================================
// F3 — ghế đã trả lại
// =============================================================================================

it('neither offers nor accepts, for new work on the matter, a colleague who returned the seat, and flags what they still hold', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hùng']);
    $this->matter->addTeamMember($associate, MatterRole::Associate, assignedBy: $this->lead);
    $held = tkFfTask($this->matter, $associate, $this->lead, TaskStatus::Accepted, ['title' => 'Nghiên cứu án lệ']);

    $this->actingAs($associate, 'web');
    tkFfTeamTab($this->matter)
        ->callTableAction('declineSeat', $associate, data: ['reason' => 'Xung đột lợi ích với bên bị đơn.'])
        ->assertHasNoTableActionErrors();

    // Ô chọn người nhận của hộp "Giao việc" (cùng `TaskAssignees::options()` mà form đọc) không bày người đó.
    expect(app(TaskAssignees::class)->options($this->matter, $this->lead))->not->toHaveKey($associate->id)
        ->toHaveKey($this->assistant->id);

    $this->actingAs($this->lead, 'web');
    $tab = $this->livewire(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);
    $tab->callTableAction('assign', data: ['assignee_id' => $associate->id, 'title' => 'Việc mới'])
        ->assertHasTableActionErrors(['assignee_id']);

    expect(Task::query()->where('title', 'Việc mới')->exists())->toBeFalse();

    tkFfNeedsReassignment($this->lead)
        ->assertCanSeeTableRecords([$held])
        ->assertSee(__('tasks.columns.assignee_seat_declined'));

    expect(tkFfReason($held))->toBe('seat_declined');
});

it('refuses, under the lock, a colleague whose seat was returned after the form was opened', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($associate, MatterRole::Associate, assignedBy: $this->lead);
    MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $associate->id)
        ->update(['declined_at' => now(), 'decline_reason' => 'Xung đột lợi ích.']);

    // Màn hình đã không bày người này (test trên); đây là lần hỏi lại dưới khoá của chính Action.
    expect(fn () => app(AssignTask::class)->handle(
        actor: $this->lead, matter: $this->matter, assignee: $associate, title: 'Việc mới',
        description: null, dueDate: null, priority: TaskPriority::Normal,
    ))->toThrow(ValidationException::class);

    expect(Task::query()->where('title', 'Việc mới')->exists())->toBeFalse();
});

it('flags the tasks a handover moved to a new lead who then returns the seat', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    $moved = tkFfTask($this->matter, $this->lead, $this->manager, TaskStatus::Accepted, ['title' => 'Soạn bản tự khai']);

    tkFfHandOver($this->matter, $this->manager, $newLead);
    expect($moved->fresh()->assignee_id)->toBe($newLead->id);

    $this->actingAs($newLead, 'web');
    tkFfTeamTab($this->matter)
        ->callTableAction('declineSeat', $newLead, data: ['reason' => 'Đang quá tải.'])
        ->assertHasNoTableActionErrors();

    tkFfNeedsReassignment($this->manager)->assertCanSeeTableRecords([$moved]);
    expect(tkFfReason($moved))->toBe('seat_declined');
});

// =============================================================================================
// F4 — không còn mở được hồ sơ
// =============================================================================================

it('never lets a matter turn restricted under a task holder who would lose it, so that path cannot hide a task', function () {
    $held = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Sao y hồ sơ']);

    $this->actingAs($this->lead, 'web');
    $this->livewire(EditMatter::class, ['record' => $this->matter->getKey()])
        ->fillForm(['confidentiality' => Confidentiality::Restricted->value])
        ->call('save')
        ->assertHasFormErrors(['confidentiality']);

    expect($this->matter->fresh()->confidentiality)->toBe(Confidentiality::Normal)
        ->and(tkFfReason($held))->toBeNull();
});

it('flags a matter task whose holder cannot open the restricted matter it sits on', function () {
    // Không màn hình nào dựng được ca này hôm nay (đổi sang hạn chế bị chặn ở test trên; hạ một quản trị viên
    // bị chặn ở `GuardsStaffOffboarding`) — dữ liệu cũ hay một đường ghi sau này thì việc vẫn không lặng lẽ mất.
    $held = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Sao y hồ sơ']);
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    tkFfNeedsReassignment($this->lead)
        ->assertCanSeeTableRecords([$held])
        ->assertSee(__('tasks.columns.assignee_off_access'));

    expect(tkFfReason($held))->toBe('off_access');
});

it('does not flag the lead\'s own task, or an admin\'s, on a restricted matter', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $leads = tkFfTask($restricted, $this->lead, $this->admin, TaskStatus::Accepted);
    // Việc bị trả lại về tay người giao là quản trị viên: họ vẫn mở được vụ hạn chế dù không ngồi ghế nào.
    $returnedToAdmin = tkFfTask($restricted, $this->lead, $this->admin, TaskStatus::Declined);

    tkFfNeedsReassignment($this->admin)->assertCanNotSeeTableRecords([$leads, $returnedToAdmin]);
    expect(tkFfReason($leads))->toBeNull()
        ->and(tkFfReason($returnedToAdmin))->toBeNull();
});

it('flags a matter task once its holder is moved to a position without access to matters', function (UserPosition $target) {
    $held = tkFfTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted, ['title' => 'Lập bảng kê']);

    $this->actingAs($this->admin, 'web');
    // Làn fa2 (phán quyết 2): màn hình nhân sự nay CHẶN đổi sang Kế toán / Nhân viên kinh doanh khi người đó còn
    // giữ việc nội bộ (StaffOffboardingTasksTest) — trạng thái này chỉ còn tới qua đường ngoài màn hình (dữ liệu cũ,
    // đổi vai trực tiếp), nên dựng thẳng; luật "Cần giao lại" vẫn phải bắt được nó.
    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['position' => $target->value])
        ->call('save')
        ->assertHasFormErrors(['position']);
    $this->assistant->forceFill(['position' => $target])->save();
    $this->assistant->assignRoleFromPosition();

    tkFfNeedsReassignment($this->lead)->assertCanSeeTableRecords([$held]);
    expect(tkFfReason($held))->toBe('off_access');
})->with([
    'accountant' => UserPosition::Accountant,
    'sales' => UserPosition::Sales,
]);

it('flags a returned task whose assigner is still active but can no longer open the matter', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hùng']);
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    $returned = tkFfTask($this->matter, $this->assistant, $associate, TaskStatus::Declined, ['title' => 'Việc bị trả lại']);
    $held = tkFfTask($this->matter, $this->assistant, $associate, TaskStatus::Accepted, ['title' => 'Việc đang làm']);

    tkFfNeedsReassignment($this->lead)->assertCanNotSeeTableRecords([$returned, $held]);

    $this->matter->team()->detach($associate->id);

    tkFfNeedsReassignment($this->lead)
        ->assertCanSeeTableRecords([$returned])
        ->assertCanNotSeeTableRecords([$held])
        ->assertSee(__('tasks.columns.assigner_off_access'));

    expect(tkFfReason($returned))->toBe('assigner_off_access')
        ->and(tkFfReason($held))->toBeNull();
});

/*
 * Gộp integ-fa (rà soát chéo 2026-10-18, mục 1): việc lead cũ giao cho lead mới thành việc RIÊNG của lead mới khi bàn
 * giao (người giao đổi sang lead mới) — trước đó nó giữ người giao là lead cũ, và lead mới trả lại thì nó thành "Cần
 * giao lại" vì người giao không còn mở được hồ sơ. Nay không có ai để trả về: không nút "Trả lại", không "Cần giao lại".
 */
it('makes a task the old lead gave the new lead the new lead\'s own task after the handover, with nobody to return it to', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Bình']);
    $this->matter->addTeamMember($newLead, MatterRole::Associate);
    $given = tkFfTask($this->matter, $newLead, $this->lead, extra: ['title' => 'Việc lead cũ giao']);

    tkFfHandOver($this->matter, $this->manager, $newLead);
    expect($given->fresh()->assigned_by)->toBe($newLead->id);

    $this->actingAs($newLead, 'web');
    $this->livewire(TasksAwaitingMeWidget::class)
        ->assertCanSeeTableRecords([$given])
        ->assertTableActionHidden('decline', $given);

    tkFfNeedsReassignment($this->manager)->assertCanNotSeeTableRecords([$given]);
    expect(tkFfReason($given))->toBeNull();
});
