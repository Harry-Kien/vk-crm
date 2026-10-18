<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\Matter\RestoreMatter;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Livewire\Features\SupportTesting\Testable;

/*
 * Gộp integ-fa (làn fa3 × làn fa1/fa2): hộp thoại "Huỷ hồ sơ mở nhầm" nói việc giao nội bộ còn mở và ghế chưa
 * nhận hồ sơ, như hộp thoại kết thúc vụ (`MatterOpenWork::closingLines()`, làn tk F1). Hồ sơ huỷ thì việc trên
 * nó rời khỏi mọi danh sách (`Task::visibleTo()` đi qua `listableBy()` — vụ xoá mềm không ra), thôi nhắc
 * (`RemindPendingWork`, `Task::isOnLiveMatter()`) và không còn chặn nghỉ việc (`OpenTaskWork`) — người bấm cần
 * biết trước. Chỉ số đếm, cùng tập "còn mở" của làn fa1 (`TaskStatus::openValues()`). Hàm mang tiền tố `ifCmt`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

/** @return string các dòng chữ đang hiện trong hộp thoại huỷ */
function ifCmtCancelTexts(Matter $matter): string
{
    /** @var Testable $page */
    $page = test()->livewire(EditMatter::class, ['record' => $matter->getKey()])->mountAction('cancelMatter');
    /** @var Schema $schema */
    $schema = $page->instance()->{$page->instance()->getMountedActionSchemaName()};

    return collect($schema->getFlatComponents())
        ->filter(fn ($component): bool => $component instanceof Text && $component->isVisible())
        ->map(fn (Text $component): string => (string) $component->getContent())
        ->implode("\n");
}

function ifCmtTask(Matter $matter, User $assignee, User $assigner, TaskStatus $status): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'assignee_id' => $assignee->id, 'assigned_by' => $assigner->id,
    ]);
}

it('lists the open internal tasks and the seats nobody accepted on the cancel dialog, counting only open tasks', function () {
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Pending);
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted);
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Declined);
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed);
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Cancelled);
    // Việc của vụ khác không đếm.
    ifCmtTask(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]), $this->assistant, $this->lead, TaskStatus::Pending);
    $this->matter->addTeamMember(User::factory()->withRole(Role::Lawyer)->create(), MatterRole::Associate, assignedBy: $this->lead);

    $this->actingAs($this->admin, 'web');

    expect(ifCmtCancelTexts($this->matter))
        ->toContain(__('lifecycle.open_work.tasks_cancelling', ['count' => 3]))
        ->toContain(__('lifecycle.open_work.seats_cancelling', ['count' => 1]))
        // Câu của hộp thoại kết thúc vụ ("sau khi kết thúc…") không lẫn vào hộp thoại huỷ.
        ->not->toContain(__('lifecycle.open_work.tasks', ['count' => 3]));
});

it('says nothing about internal work on the cancel dialog when every task is done and every seat accepted', function () {
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed);
    ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Cancelled);

    $this->actingAs($this->admin, 'web');

    $texts = ifCmtCancelTexts($this->matter);

    expect($texts)->not->toContain(__('lifecycle.open_work.tasks_cancelling', ['count' => 2]))
        ->not->toContain(__('lifecycle.open_work.tasks_cancelling', ['count' => 1]))
        ->not->toContain(__('lifecycle.open_work.seats_cancelling', ['count' => 1]));
});

it('is true to what the dialog says: the cancelled matter takes its open task off the holder\'s list, a restore brings it back', function () {
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant, assignedBy: $this->lead);
    $task = ifCmtTask($this->matter, $this->assistant, $this->lead, TaskStatus::Accepted);

    expect(__('lifecycle.open_work.tasks_cancelling', ['count' => 1]))->toContain('khôi phục');

    $this->actingAs($this->assistant, 'web');
    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertCanSeeTableRecords([$task]);

    app(CancelMatter::class)->handle($this->matter, $this->admin, 'Mở nhầm khách hàng.');

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertCanNotSeeTableRecords([$task]);

    app(RestoreMatter::class)->handle(Matter::withTrashed()->findOrFail($this->matter->id), $this->admin, 'Huỷ nhầm.');

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->assertCanSeeTableRecords([$task]);
    expect($task->fresh()->status)->toBe(TaskStatus::Accepted);
});
