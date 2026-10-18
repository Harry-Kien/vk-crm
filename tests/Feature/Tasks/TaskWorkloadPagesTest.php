<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Làn tk, Task 2 — giao – nhận nội bộ trên "Theo dõi đội ngũ", trang của một người và "Hiệu suất theo kỳ".
 * Qua Livewire. Số là phép ĐẾM trên phần người xem thấy: việc của một vụ hạn chế (và ghế chờ nhận của nó)
 * không vào số của trưởng phòng, vào số của quản trị.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Bình']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->secret = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'confidentiality' => Confidentiality::Restricted]);
});

function tkTask(Matter $matter, User $assignee, User $assigner, TaskStatus $status, array $extra = []): Task
{
    return Task::factory()->forMatter($matter)->status($status)->create([
        'assignee_id' => $assignee->id, 'assigned_by' => $assigner->id, ...$extra,
    ]);
}

function tkWorld($t): void
{
    tkTask($t->matter, $t->assistant, $t->lead, TaskStatus::Accepted);
    tkTask($t->matter, $t->assistant, $t->lead, TaskStatus::Accepted, ['due_date' => today()->subDays(2)]);
    tkTask($t->matter, $t->assistant, $t->lead, TaskStatus::Pending);
    // Đã trả lại (và đã qua hạn): không còn trong tay trợ lý — không vào "quá hạn" của họ.
    tkTask($t->matter, $t->assistant, $t->lead, TaskStatus::Declined, ['due_date' => today()->subDays(3)]);
    // Việc của vụ hạn chế: quản trị giao cho luật sư phụ trách, đang chờ nhận, đã quá hạn; một việc lead giao bị trả lại.
    tkTask($t->secret, $t->lead, $t->admin, TaskStatus::Pending, ['due_date' => today()->subDay()]);
    tkTask($t->secret, $t->admin, $t->lead, TaskStatus::Declined);
    // Ghế chờ nhận và ghế đã trả lại của luật sư phụ trách: một trên vụ thường, một trên vụ hạn chế.
    $other = Matter::factory()->create(['lead_lawyer_id' => $t->manager->id]);
    $other->addTeamMember($t->lead, MatterRole::Associate, assignedBy: $t->manager);
    $t->secret->team()->updateExistingPivot($t->lead->id, ['accepted_at' => null, 'declined_at' => now(), 'decline_reason' => 'Bận']);
}

function tkOverviewRow(User $viewer, User $subject): array
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(TeamOverview::class)->instance()->getTableRecords()[$subject->id];
}

it('counts held, waiting, overdue and returned tasks and seats per person on Theo dõi đội ngũ', function () {
    tkWorld($this);

    $assistant = tkOverviewRow($this->manager, $this->assistant);

    expect([$assistant['tasksInProgress'], $assistant['tasksAwaiting'], $assistant['tasksOverdue'], $assistant['tasksReturned']])
        ->toBe([2, 1, 1, 0]);

    $lead = tkOverviewRow($this->manager, $this->lead);

    // Trưởng phòng: việc lead GIAO bị trả lại trên vụ thường = 1; việc của vụ hạn chế và ghế trả lại của nó không vào số.
    expect([$lead['tasksAwaiting'], $lead['tasksOverdue'], $lead['tasksReturned'], $lead['seatsAwaiting'], $lead['seatsReturned']])
        ->toBe([0, 0, 1, 1, 0]);

    $leadForAdmin = tkOverviewRow($this->admin, $this->lead);

    // Làn fa1 (phán quyết 5; vòng sửa 1, m10): việc quá hạn của vụ hạn chế nằm trên ghế luật sư phụ trách ĐÃ TRẢ LẠI
    // (tkWorld) — người đó không còn giữ được việc (việc hiện "Cần giao lại"), nên không vào số quá hạn lẫn số chờ
    // nhận của họ: một định nghĩa "đang giữ" cho ba cột việc.
    expect([$leadForAdmin['tasksAwaiting'], $leadForAdmin['tasksOverdue'], $leadForAdmin['tasksReturned'], $leadForAdmin['seatsAwaiting'], $leadForAdmin['seatsReturned']])
        ->toBe([0, 0, 2, 1, 1]);
});

it('prints the task columns and their explanations on the page, overdue and returned seats in red', function () {
    tkWorld($this);
    $this->actingAs($this->admin, 'web');

    $html = Livewire::test(TeamOverview::class)->html();

    foreach (TeamOverview::TASK_COLUMNS as $column) {
        expect($html)->toContain(e(__("tasks.workload.columns.{$column}")))
            ->and($html)->toContain(e(__("tasks.workload.explain.{$column}")));
    }

    expect($html)->toContain('var(--danger-600)')
        ->and($html)->not->toContain('tasks.workload.');
});

it('keeps the manager\'s page identical whether or not the restricted matter has tasks and seats', function () {
    $render = function (): array {
        $this->actingAs($this->manager->fresh(), 'web');

        return Livewire::test(TeamOverview::class)->instance()->getTableRecords()->all();
    };

    tkTask($this->matter, $this->assistant, $this->lead, TaskStatus::Pending);
    $before = $render();

    tkTask($this->secret, $this->lead, $this->admin, TaskStatus::Pending, ['due_date' => today()->subDay()]);
    tkTask($this->secret, $this->admin, $this->lead, TaskStatus::Declined);
    $this->secret->team()->updateExistingPivot($this->lead->id, ['accepted_at' => null]);

    expect($render())->toBe($before);
});

it('shows my own task numbers on my page, in the same words as the team page', function () {
    tkWorld($this);
    $this->actingAs($this->assistant, 'web');

    $html = Livewire::test(TeamMember::class, ['user' => $this->assistant->id])->html();

    expect($html)->toContain('data-vk-task-metric="tasksInProgress"')
        ->and($html)->toContain('data-vk-task-metric="seatsReturned"')
        ->and($html)->toContain(e(__('tasks.workload.explain.tasksOverdue')));

    preg_match('/data-vk-task-metric="tasksInProgress".*?<dd[^>]*>\s*(\d+)\s*</s', $html, $match);

    expect($match[1] ?? null)->toBe('2');
});

function tkCompleted(Matter $matter, User $assignee, User $assigner, string $due, string $done): Task
{
    return tkTask($matter, $assignee, $assigner, TaskStatus::Completed, [
        'due_date' => $due, 'completed_at' => Carbon::parse($done), 'accepted_at' => Carbon::parse($done),
    ]);
}

it('shows "Việc hoàn thành đúng hạn" for the period with numerator and denominator, and the number of hand-backs', function () {
    // Tháng 9/2026 (kỳ mặc định "tháng trước"): bốn việc xong đúng hạn (kể cả xong lúc 23:00 đúng ngày hạn), một trễ.
    foreach (['2026-09-05', '2026-09-10', '2026-09-15', '2026-09-20'] as $due) {
        tkCompleted($this->matter, $this->assistant, $this->lead, $due, $due.' 23:00:00');
    }
    tkCompleted($this->matter, $this->assistant, $this->lead, '2026-09-21', '2026-09-22 08:00:00');
    // Ngoài kỳ, và không có hạn: không tính.
    tkCompleted($this->matter, $this->assistant, $this->lead, '2026-10-01', '2026-10-02 08:00:00');
    tkTask($this->matter, $this->assistant, $this->lead, TaskStatus::Completed, ['completed_at' => Carbon::parse('2026-09-10 10:00')]);

    // Hai lần trả lại trong kỳ (nhật ký), một ngoài kỳ.
    foreach (['2026-09-03 10:00', '2026-09-25 10:00', '2026-10-03 10:00'] as $at) {
        $this->travelTo(Carbon::parse($at));
        $task = tkTask($this->matter, $this->assistant, $this->lead, TaskStatus::Declined);
        Audit::record('task_declined', $task, ['matter_id' => $this->matter->id, 'reason' => 'Bận'], causer: $this->assistant);
    }
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->actingAs($this->manager, 'web');
    $page = Livewire::test(Performance::class);
    $row = $page->instance()->getTableRecords()[$this->assistant->id];

    expect($row['taskOnTime']->label())->toBe('80% (4/5)')
        ->and($row['tasksDeclined'])->toBe(2)
        ->and($page->html())->toContain(e(__('tasks.performance.declined', ['count' => 2])))
        ->and($page->html())->toContain(e(__('tasks.performance.explain')));
});

it('keeps restricted tasks out of the manager\'s performance numbers and in the admin\'s', function () {
    foreach (['2026-09-05', '2026-09-10', '2026-09-15', '2026-09-20', '2026-09-25'] as $due) {
        tkCompleted($this->secret, $this->lead, $this->admin, $due, $due.' 09:00:00');
    }
    $this->travelTo(Carbon::parse('2026-09-12 10:00'));
    $declined = tkTask($this->secret, $this->lead, $this->admin, TaskStatus::Declined);
    Audit::record('task_declined', $declined, ['matter_id' => $this->secret->id, 'reason' => 'Bận'], causer: $this->lead);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->actingAs($this->manager, 'web');
    $managerRow = Livewire::test(Performance::class)->instance()->getTableRecords()[$this->lead->id];

    expect($managerRow['taskOnTime']->label())->toBe(__('performance.ratio.insufficient', ['n' => 0]))
        ->and($managerRow['tasksDeclined'])->toBe(0);

    $this->actingAs($this->admin, 'web');
    $adminRow = Livewire::test(Performance::class)->instance()->getTableRecords()[$this->lead->id];

    expect($adminRow['taskOnTime']->label())->toBe('100% (5/5)')
        ->and($adminRow['tasksDeclined'])->toBe(1);
});

it('says "not enough data" under five tasks, and counts the reference row over everyone the manager sees', function () {
    tkCompleted($this->matter, $this->assistant, $this->lead, '2026-09-05', '2026-09-04 10:00:00');
    tkCompleted($this->matter, $this->lead, $this->manager, '2026-09-05', '2026-09-09 10:00:00');

    $this->actingAs($this->manager, 'web');
    $records = Livewire::test(Performance::class)->instance()->getTableRecords();

    expect($records[$this->assistant->id]['taskOnTime']->label())->toBe(__('performance.ratio.insufficient', ['n' => 1]))
        ->and($records[Performance::REFERENCE_KEY]['taskOnTime']->numerator)->toBe(1)
        ->and($records[Performance::REFERENCE_KEY]['taskOnTime']->denominator)->toBe(2);

    // Luật sư xem trang của mình: không dòng "Chung".
    $this->actingAs($this->assistant, 'web');
    $own = Livewire::test(Performance::class)->instance()->getTableRecords();

    expect($own->keys()->all())->toBe([$this->assistant->id]);
});
