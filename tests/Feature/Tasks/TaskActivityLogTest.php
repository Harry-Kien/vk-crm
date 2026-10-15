<?php

use App\Actions\Task\AcceptTask;
use App\Actions\Task\AssignTask;
use App\Actions\Task\DeclineTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterActivityRelationManager;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 1 — các dòng nhật ký `task_*` trên HAI màn hình nhật ký: trang "Nhật ký hệ thống"
 * (`auditLog.view`: admin, quản lý) và tab "Nhật ký" của một vụ (luật sư phụ trách). Dòng của việc
 * thuộc một vụ mang `properties.matter_id`, nên `ActivityOwningMatter` quy nó về vụ đó: một quản lý
 * không xem được vụ `restricted` không đọc được tiêu đề việc, lý do trả lại hay người làm của nó ở
 * đây. Dòng của việc CHUNG không thuộc vụ nào — quản lý (người giao việc chung) thấy.
 *
 * Các Action chỉ dựng dữ liệu; khẳng định đi qua màn hình (Livewire).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
});

it('hides every row of a restricted matter\'s task from a manager on the system log, and shows it to an admin', function () {
    $secret = app(AssignTask::class)->handle($this->admin, $this->restricted, $this->lead, 'Rà soát hồ sơ mật');
    app(DeclineTask::class)->handle($secret, $this->lead, 'Xung đột lợi ích với bên kia');

    $rows = Activity::query()->whereIn('event', ['task_assigned', 'task_declined'])->get();
    expect($rows)->toHaveCount(2);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ActivityLogPage::class)
        ->assertCanNotSeeTableRecords($rows)
        ->assertDontSee('Xung đột lợi ích với bên kia');

    $this->actingAs($this->admin, 'web');
    $this->livewire(ActivityLogPage::class)->assertCanSeeTableRecords($rows);
});

it('shows a manager the rows of a normal matter\'s task and of a general task, with Vietnamese labels', function () {
    $onMatter = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn');
    app(AcceptTask::class)->handle($onMatter, $this->assistant);
    app(AssignTask::class)->handle($this->manager, null, $this->accountant, 'Đối chiếu công nợ');

    $rows = Activity::query()->where('event', 'like', 'task_%')->get();
    expect($rows)->toHaveCount(3);

    $this->actingAs($this->manager, 'web');
    $this->livewire(ActivityLogPage::class)
        ->assertCanSeeTableRecords($rows)
        ->assertSee(__('activity.events.task_assigned'))
        ->assertSee(__('activity.events.task_accepted'));
});

it('lists a matter\'s task rows in that matter\'s log tab for its lead, and none of another matter\'s', function () {
    $here = app(AssignTask::class)->handle($this->lead, $this->matter, $this->assistant, 'Soạn đơn');
    app(AcceptTask::class)->handle($here, $this->assistant);
    app(AssignTask::class)->handle($this->admin, $this->restricted, $this->lead, 'Việc ở vụ kín');

    $mine = Activity::query()->where('event', 'like', 'task_%')->where('properties->matter_id', $this->matter->id)->get();
    $elsewhere = Activity::query()->where('event', 'like', 'task_%')->where('properties->matter_id', $this->restricted->id)->get();
    expect($mine)->toHaveCount(2)->and($elsewhere)->toHaveCount(1);

    $this->actingAs($this->lead, 'web');
    $this->livewire(MatterActivityRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($elsewhere);
});
