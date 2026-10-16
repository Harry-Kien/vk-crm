<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Livewire\Livewire;

/**
 * Làn tk, Task 2 — "Việc cần xử lý của tôi" trên bảng điều khiển: bốn ô số của chính người đăng nhập, mỗi
 * ô trỏ tới đúng danh sách; chỉ hiện khi có việc; không bao giờ đếm việc của vụ hạn chế người xem không mở.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

it('counts what waits for me, my overdue work, my seats to accept and my returned tasks, each linking to its list', function () {
    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id, 'due_date' => today()->subDay(),
    ]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $other->addTeamMember($this->assistant, MatterRole::Assistant, assignedBy: $this->lead);

    expect(MyWorkQueueWidget::countsFor($this->assistant))->toBe(['awaiting' => 2, 'overdue' => 1, 'seats' => 1, 'returned' => 0])
        ->and(MyWorkQueueWidget::countsFor($this->lead))->toBe(['awaiting' => 0, 'overdue' => 0, 'seats' => 0, 'returned' => 1]);

    $this->actingAs($this->assistant, 'web');

    expect(MyWorkQueueWidget::canView())->toBeTrue();

    // Vòng sửa cuối 1 (F5): ô quá hạn mở tab "Tất cả" lọc sẵn (việc chưa nhận đã trễ cũng có mặt), không
    // tab "Đang làm" — `TaskFinalFixTest` mở từng đường dẫn và so dòng.
    Livewire::test(MyWorkQueueWidget::class)
        ->assertSee(__('tasks.dashboard.heading'))
        ->assertSee(__('tasks.dashboard.awaiting'))
        ->assertSeeHtml(e(TaskResource::getUrl('index', ['tab' => 'awaiting'], panel: 'admin')))
        ->assertSeeHtml(e(MyWorkQueueWidget::links($this->assistant)['overdue']))
        ->assertDontSeeHtml(e(TaskResource::getUrl('index', ['tab' => 'active'], panel: 'admin')))
        ->assertSeeHtml(e(TeamMember::getUrl(['user' => $this->assistant->id], panel: 'admin')));

    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertSeeLivewire(MyWorkQueueWidget::class);
});

it('stays off the dashboard when nothing waits for me', function () {
    $this->actingAs($this->assistant, 'web');

    expect(MyWorkQueueWidget::canView())->toBeFalse();

    $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk()->assertDontSeeLivewire(MyWorkQueueWidget::class);
});

it('never counts a task of a restricted matter I can no longer open', function () {
    Task::factory()->forMatter($this->matter)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    expect(MyWorkQueueWidget::countsFor($this->assistant))->toBe(['awaiting' => 0, 'overdue' => 0, 'seats' => 0, 'returned' => 0]);

    // Gộp làn tk (2026-10-16): người có quyền xem việc nhưng hết việc (vụ vừa thành hạn chế, hay vừa nhận
    // xong việc cuối ở tab khác giữa lúc trang chủ vẽ và lúc khối tải trễ) thấy các ô số 0, KHÔNG 404 —
    // 404 trong một lần tải trễ của Livewire hiện hộp báo lỗi giữa trang chủ. Số vẫn không lộ việc của vụ hạn chế.
    $this->actingAs($this->assistant, 'web');
    Livewire::test(MyWorkQueueWidget::class)
        ->assertOk()
        ->assertDontSee($this->matter->code);
});

it('sits after the office work lists and before the stage chart', function () {
    expect(MyWorkQueueWidget::getSort())->toBeGreaterThan(UnseenUpdatesWidget::getSort())
        ->and(MyWorkQueueWidget::getSort())->toBeLessThan(MattersByStageWidget::getSort());
});
