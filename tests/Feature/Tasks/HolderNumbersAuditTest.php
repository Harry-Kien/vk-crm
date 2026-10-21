<?php

use App\Actions\Performance\BuildTaskWorkload;
use App\Actions\User\SuspendStaffAccess;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\HolderSuspendedAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Kiểm toán luồng Công việc 2026-10-21 — con số và lời báo khi người giữ việc không còn làm được:
 *
 *  - Q4: "Khoá truy cập ngay" một người đang giữ việc — người giao (hay người nhận thay) của mỗi việc nhận MỘT chuông
 *    gộp; ô "Việc tôi giao cần giao lại" trên bảng điều khiển, đếm đúng số dòng nó mở.
 *  - N2: một định nghĩa "chờ tôi nhận"/"tôi đang làm" (`heldBySeatedAssignee()`) cho ô, huy hiệu menu, tab và khối "Chờ
 *    tôi nhận"; việc "cần giao lại vì chính tôi" vẫn ở tab "Tất cả" của họ, nơi họ "Trả lại" được (không thêm ô riêng —
 *    phán quyết làn fa1: người không còn giữ được việc "không còn việc nào phải xử lý", ô trang chủ ẩn).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'Asia/Ho_Chi_Minh'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Vũ']);
    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'HNA-01']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function hnaTask(User $assigner, TaskStatus $status, array $extra = [], ?Matter $matter = null): Task
{
    return Task::factory()->forMatter($matter ?? test()->matter)->status($status)->create([
        'assignee_id' => test()->assistant->id, 'assigned_by' => $assigner->id, ...$extra,
    ]);
}

function hnaRows(User $viewer, string $url): array
{
    test()->actingAs($viewer, 'web');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return Livewire::withQueryParams($query)->test(ListTasks::class)->instance()
        ->getFilteredTableQuery()->pluck('tasks.id')->sort()->values()->all();
}

// =============================================================================================
// Q4 — "Khoá truy cập ngay" người đang giữ việc
// =============================================================================================

/** Mutation probe: `SuspendStaffAccess` không xếp lời báo sau commit → đỏ. */
it('rings each assigner once, listing the tasks the suspended holder kept, and nobody else', function () {
    $noDue = hnaTask($this->lead, TaskStatus::Accepted, ['title' => 'Lưu trữ hồ sơ giấy']);
    $farDue = hnaTask($this->lead, TaskStatus::Pending, ['title' => 'Soạn bản tự khai', 'due_date' => '2026-12-30']);
    $general = Task::factory()->status(TaskStatus::Accepted)->create([
        'title' => 'Đối chiếu công văn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id,
    ]);
    // Việc chính người khoá đã giao: họ vừa thấy số việc trong hộp xác nhận — không tự báo.
    hnaTask($this->admin, TaskStatus::Accepted, ['title' => 'Việc quản trị giao']);

    app(SuspendStaffAccess::class)->handle($this->admin, $this->assistant, 'Nghỉ đột xuất, cần khoá ngay để bàn giao.');

    $bells = $this->lead->notifications()->where('type', HolderSuspendedAlert::class)->get();
    expect($bells)->toHaveCount(1)
        ->and($bells[0]->data['title'])->toBe(__('tasks.holder_suspended.title', ['count' => 2]))
        ->and($bells[0]->data['body'])->toContain('Trợ lý Lan')->toContain('Lưu trữ hồ sơ giấy')->toContain('Soạn bản tự khai');

    // Đường dẫn của chuông mở đúng các việc đó.
    expect(hnaRows($this->lead, $bells[0]->data['actions'][0]['url']))->toBe(collect([$noDue->id, $farDue->id])->sort()->values()->all());

    expect($this->manager->notifications()->where('type', HolderSuspendedAlert::class)->sole()->data['title'])
        ->toBe(__('tasks.holder_suspended.title', ['count' => 1]))
        ->and(hnaRows($this->manager, $this->manager->notifications()->where('type', HolderSuspendedAlert::class)->sole()->data['actions'][0]['url']))
        ->toBe([$general->id])
        ->and($this->admin->notifications()->where('type', HolderSuspendedAlert::class)->count())->toBe(0)
        ->and($this->assistant->notifications()->where('type', HolderSuspendedAlert::class)->count())->toBe(0);
});

/** Mutation probe: nhóm theo việc (mỗi việc một chuông) → đỏ. Bỏ lọc người bấm → đỏ ở ca trên. */
it('rings the lead standing in for an assigner who is gone, once for all such tasks', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cộng Sự Cũ']);
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    hnaTask($associate, TaskStatus::Accepted, ['title' => 'Việc A']);
    hnaTask($associate, TaskStatus::Accepted, ['title' => 'Việc B']);
    $associate->forceFill(['is_active' => false])->save();

    app(SuspendStaffAccess::class)->handle($this->admin, $this->assistant, 'Nghỉ đột xuất, cần khoá ngay để bàn giao.');

    $bell = $this->lead->notifications()->where('type', HolderSuspendedAlert::class)->sole();
    expect($bell->data['title'])->toBe(__('tasks.holder_suspended.title', ['count' => 2]))
        ->and(hnaRows($this->lead, $bell->data['actions'][0]['url']))->toHaveCount(2);
});

/** Mutation probe: bỏ ô "reassign" / đếm khác scope của đường dẫn → đỏ. */
it('shows the assigner a "to reassign" tile whose number equals the rows it opens', function () {
    $kept = hnaTask($this->lead, TaskStatus::Accepted, ['title' => 'Lưu trữ hồ sơ giấy']);
    // Việc bị trả lại của người bị khoá: ở ô "bị trả lại", không đếm hai lần.
    hnaTask($this->lead, TaskStatus::Declined, ['title' => 'Việc đã trả lại']);
    // Việc của người còn làm việc: không cần giao lại.
    $healthy = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->matter->addTeamMember($healthy, MatterRole::Assistant);
    hnaTask($this->lead, TaskStatus::Accepted, ['title' => 'Việc người khác giữ', 'assignee_id' => $healthy->id]);

    expect(MyWorkQueueWidget::countsFor($this->lead)['reassign'])->toBe(0);

    app(SuspendStaffAccess::class)->handle($this->admin, $this->assistant, 'Nghỉ đột xuất, cần khoá ngay để bàn giao.');

    $this->actingAs($this->lead, 'web');
    $counts = MyWorkQueueWidget::countsFor($this->lead);
    $link = MyWorkQueueWidget::links($this->lead)['reassign'];

    expect($counts['reassign'])->toBe(1)
        ->and(MyWorkQueueWidget::canView())->toBeTrue()
        ->and(hnaRows($this->lead, $link))->toBe([$kept->id])
        ->and($link)->toContain('tab=assigned');

    Livewire::test(MyWorkQueueWidget::class)
        ->assertSee(__('tasks.my_queue.reassign'))
        ->assertSeeHtml(e($link));
});

// =============================================================================================
// N2 — một định nghĩa "chờ tôi nhận"
// =============================================================================================

/** Mutation probe: bỏ vế `heldBySeatedAssignee()` khỏi `Task::scopeAwaitingAcceptanceBy()` → đỏ. */
it('gives the observer the same zero everywhere for a pending task they can no longer accept, and keeps it under "Tất cả"', function () {
    $task = hnaTask($this->lead, TaskStatus::Pending, ['title' => 'Việc chờ nhận']);
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    $this->actingAs($this->assistant, 'web');

    $counts = MyWorkQueueWidget::countsFor($this->assistant);
    expect($counts['awaiting'])->toBe(0)
        ->and(TaskResource::getNavigationBadge())->toBeNull()
        ->and(Livewire::test(ListTasks::class, ['activeTab' => 'awaiting'])->instance()->getFilteredTableQuery()->count())->toBe(0)
        ->and(Livewire::test(TasksAwaitingMeWidget::class)->instance()->getTableRecords()->count())->toBe(0);

    $this->actingAs($this->manager, 'web');
    expect(app(BuildTaskWorkload::class)->handle($this->manager, collect([$this->assistant]))[$this->assistant->id]->tasksAwaiting)->toBe(0);

    // Vẫn ở tab "Tất cả" của họ, với nút "Trả lại" (không "Đã nhận").
    $this->actingAs($this->assistant, 'web');
    Livewire::test(ListTasks::class, ['activeTab' => 'all'])
        ->assertCanSeeTableRecords([$task])
        ->assertTableActionHidden('accept', $task)
        ->assertTableActionVisible('decline', $task);
});

it('keeps the observer\'s accepted task out of "Đang làm" too', function () {
    hnaTask($this->lead, TaskStatus::Accepted, ['title' => 'Việc đang làm']);
    $this->matter->team()->detach($this->assistant->id);
    $this->matter->addTeamMember($this->assistant, MatterRole::Observer);

    $this->actingAs($this->assistant, 'web');

    expect(Livewire::test(ListTasks::class, ['activeTab' => 'active'])->instance()->getFilteredTableQuery()->count())->toBe(0);
});

/** Phép thử ngược: người còn giữ ghế thấy việc của mình như cũ. */
it('counts a seated holder as before', function () {
    hnaTask($this->lead, TaskStatus::Pending);

    $this->actingAs($this->assistant, 'web');

    expect(MyWorkQueueWidget::countsFor($this->assistant)['awaiting'])->toBe(1)
        ->and(TaskResource::getNavigationBadge())->toBe('1')
        ->and(Livewire::test(ListTasks::class, ['activeTab' => 'awaiting'])->instance()->getFilteredTableQuery()->count())->toBe(1);
});
