<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * Làn tk, Task 2 — ba việc còn lại từ rà soát Task 1:
 *  - m4: lời "bị trả lại" không rơi vào khoảng trống khi người giao đã rời vụ / nghỉ việc — luật sư phụ
 *    trách hiện tại nhận thay;
 *  - r1: người giao lại chính là người đang giữ việc không tự nhận chuông "đã giao cho người khác";
 *  - m6: việc của một vụ đã kết thúc không giao lại hay sửa được nữa — chỉ còn huỷ.
 * Qua Livewire (khối "Chờ tôi nhận", trang Công việc).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

it('sends a decline to the current lead when the assigner has left (m4)', function () {
    $oldAssigner = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Đã Nghỉ']);
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $oldAssigner->id,
    ]);
    $oldAssigner->forceFill(['is_active' => false])->save();

    $this->actingAs($this->assistant, 'web');
    $this->livewire(TasksAwaitingMeWidget::class)
        ->callTableAction('decline', $task, data: ['reason' => 'Đang quá tải.'])
        ->assertHasNoTableActionErrors();

    $bells = $this->lead->notifications()->where('type', TaskAlert::class)->get();

    expect($bells)->toHaveCount(1)
        ->and($bells[0]->data['body'])->toContain('Đang quá tải.')
        ->and($oldAssigner->notifications()->count())->toBe(0);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->lead->email) && $mail->kind === TaskNotice::KIND_DECLINED);
});

it('still sends the decline to the assigner when they are still on the matter', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn đơn', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->manager->id,
    ]);

    $this->actingAs($this->assistant, 'web');
    $this->livewire(TasksAwaitingMeWidget::class)
        ->callTableAction('decline', $task, data: ['reason' => 'Đang quá tải.'])
        ->assertHasNoTableActionErrors();

    expect($this->manager->notifications()->where('type', TaskAlert::class)->count())->toBe(1)
        ->and($this->lead->notifications()->where('type', TaskAlert::class)->count())->toBe(0);
});

it('does not ring the person who reassigns a task they were holding themselves (r1)', function () {
    $task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Chuẩn bị hồ sơ', 'assignee_id' => $this->lead->id, 'assigned_by' => $this->manager->id,
    ]);

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])
        ->callTableAction('reassign', $task, data: ['assignee_id' => $this->assistant->id])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->assignee_id)->toBe($this->assistant->id)
        ->and($this->lead->notifications()->where('type', TaskAlert::class)->count())->toBe(0);
});

it('offers only "Huỷ việc" on an open task of a closed matter (m6)', function () {
    $task = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc dở', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id,
    ]);
    $this->matter->forceFill(['closed_at' => today()])->save();

    $this->actingAs($this->lead, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'assigned'])
        ->assertTableActionHidden('reassign', $task)
        ->assertTableActionHidden('edit', $task)
        ->assertTableActionVisible('cancel', $task)
        ->callTableAction('cancel', $task, data: ['reason' => 'Vụ đã kết thúc.'])
        ->assertHasNoTableActionErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});
