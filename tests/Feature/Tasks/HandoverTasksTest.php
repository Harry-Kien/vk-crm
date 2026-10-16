<?php

use App\Actions\Task\MoveTasksOnHandover;
use App\Enums\Confidentiality;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Jobs\SendReassignmentDigest;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Mail\Staff\MatterReassigned;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 2 — bàn giao vụ (một vụ qua "Bàn giao", hàng loạt qua "Bàn giao hàng loạt") nối với giao –
 * nhận nội bộ: ghế luật sư phụ trách mới chờ người nhận bấm "Đã nhận hồ sơ"; việc đang mở của lead cũ trên
 * vụ chuyển sang lead mới (chờ nhận) khi lead cũ rời đội ngũ; thư tổng hợp bàn giao liệt kê các việc đó.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Cũ']);
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id, 'code' => 'VK-2026-HS-0007']);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    // Việc lead cũ đang nhận: một chờ nhận, một đang làm, một đã trả lại (đều do quản lý giao), một do chính
    // lead mới giao, một việc chung; và một việc của trợ lý do lead cũ giao.
    $this->pending = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Soạn bản tự khai', 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->manager->id, 'due_date' => today()->addDays(3),
    ]);
    $this->accepted = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Nộp đơn khởi kiện', 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->manager->id,
    ]);
    $this->declined = Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create([
        'title' => 'Gặp nhân chứng', 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->manager->id,
    ]);
    $this->byNewLead = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc lead mới giao', 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->newLead->id,
    ]);
    $this->general = Task::factory()->create([
        'title' => 'Việc chung', 'matter_id' => null, 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->manager->id,
    ]);
    $this->assistantTask = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc của trợ lý', 'assignee_id' => $this->assistant->id, 'assigned_by' => $this->oldLead->id,
    ]);
});

function handOver(Matter $matter, User $actor, User $newLead, bool $keep = false): void
{
    test()->actingAs($actor, 'web');

    test()->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->callAction('reassignMatter', data: [
            'new_lead_id' => $newLead->id,
            'keep_old_lead_as_associate' => $keep,
            'reason' => 'Luật sư cũ nghỉ việc.',
        ])
        ->assertHasNoActionErrors();
}

it('makes the new lead seat wait for "Đã nhận hồ sơ" and rings the new lead', function () {
    handOver($this->matter, $this->manager, $this->newLead);

    $seat = MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $this->newLead->id)->first();

    expect($seat->role_in_matter)->toBe(MatterRole::Lead)
        ->and($seat->assignmentStatus())->toBe(MatterAssignmentStatus::Pending)
        ->and($seat->assigned_by)->toBe($this->manager->id);

    expect($this->newLead->notifications()->where('type', MatterAssignmentAlert::class)->pluck('data')->pluck('title')->all())
        ->toBe([__('tasks.seat.alert.assigned.title', ['code' => 'VK-2026-HS-0007'])]);

    $this->actingAs($this->newLead, 'web');
    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->assertCanSeeTableRecords([$seat])
        ->callTableAction('acceptSeat', $seat)
        ->assertHasNoTableActionErrors();

    expect($seat->fresh()->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted);
});

it('does not ask a manager who hands a matter to themselves to accept it', function () {
    handOver($this->matter, $this->manager, $this->manager);

    expect(MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $this->manager->id)->first()->assignmentStatus())
        ->toBe(MatterAssignmentStatus::Accepted);
});

it('moves the old lead\'s held tasks on the matter to the new lead as waiting, and leaves the rest', function () {
    handOver($this->matter, $this->manager, $this->newLead);

    foreach ([$this->pending, $this->accepted] as $task) {
        $fresh = $task->fresh();

        expect($fresh->assignee_id)->toBe($this->newLead->id)
            ->and($fresh->status)->toBe(TaskStatus::Pending)
            ->and($fresh->accepted_at)->toBeNull()
            ->and($fresh->assigned_by)->toBe($this->manager->id);

        $row = Activity::query()->where('event', 'task_reassigned')->where('subject_id', $task->id)->sole();

        expect($row->properties['reason'])->toBe(MoveTasksOnHandover::HANDOVER_REASON)
            ->and($row->properties['previous_assignee_id'])->toBe($this->oldLead->id)
            ->and($row->properties['matter_id'])->toBe($this->matter->id)
            ->and($row->causer_id)->toBe($this->manager->id);
    }

    expect($this->declined->fresh()->assignee_id)->toBe($this->oldLead->id)
        ->and($this->byNewLead->fresh()->assignee_id)->toBe($this->oldLead->id)
        ->and($this->general->fresh()->assignee_id)->toBe($this->oldLead->id)
        ->and($this->assistantTask->fresh()->assignee_id)->toBe($this->assistant->id)
        // Việc lead mới tự giao cho lead cũ ở lại và hiện "Cần giao lại" cho chính lead mới.
        ->and(Task::query()->needsReassignment()->whereKey($this->byNewLead->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'matter_reassigned')->latest('id')->first()->properties['tasks_moved'])->toBe(2);
});

it('leaves the tasks with an old lead who stays on as associate', function () {
    handOver($this->matter, $this->manager, $this->newLead, keep: true);

    expect($this->pending->fresh()->assignee_id)->toBe($this->oldLead->id)
        ->and($this->accepted->fresh()->status)->toBe(TaskStatus::Accepted)
        // Ghế cũ của họ không phải nhận lại.
        ->and(MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $this->oldLead->id)->first()->assignmentStatus())
        ->toBe(MatterAssignmentStatus::Accepted);
});

it('lists the moved tasks in the handover digest mail to the new lead', function () {
    handOver($this->matter, $this->manager, $this->newLead);

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail): bool {
        $html = $mail->render();

        return $mail->hasTo($this->newLead->email)
            && str_contains($html, 'Soạn bản tự khai')
            && str_contains($html, 'Nộp đơn khởi kiện')
            && str_contains($html, today()->addDays(3)->format('d/m/Y'))
            && ! str_contains($html, 'Gặp nhân chứng')
            && ! str_contains($html, 'Việc chung')
            && str_contains($html, e(__('tasks.handover.email.accept_hint')));
    });
});

it('moves tasks on every matter of a bulk handover and lists them in the one digest', function () {
    $second = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
    $secondTask = Task::factory()->forMatter($second)->status(TaskStatus::Accepted)->create([
        'title' => 'Việc vụ thứ hai', 'assignee_id' => $this->oldLead->id, 'assigned_by' => $this->manager->id,
    ]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');
    $this->livewire(BulkReassign::class)
        ->set('data.lead_lawyer_id', $this->oldLead->id)
        ->set('data.matter_ids', [$this->matter->id, $second->id])
        ->set('data.new_lead_id', $this->newLead->id)
        ->set('data.reason', 'Nghỉ việc, bàn giao cả lô.')
        ->set('data.keep_old_lead_as_associate', false)
        ->call('reassignSelected')
        ->assertHasNoErrors();

    expect($secondTask->fresh()->assignee_id)->toBe($this->newLead->id)
        ->and($secondTask->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($this->pending->fresh()->assignee_id)->toBe($this->newLead->id)
        ->and(MatterUser::query()->where('user_id', $this->newLead->id)->awaitingAcceptance()->count())->toBe(2);

    Mail::assertSent(MatterReassigned::class, 1);
    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => str_contains($mail->render(), 'Việc vụ thứ hai')
        && str_contains($mail->render(), 'Soạn bản tự khai'));
});

it('tells only admins, never a manager, when a new lead hands back a restricted matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Bình']);
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    handOver($this->matter, $admin, $this->newLead);

    $this->actingAs($this->newLead, 'web');
    $seat = MatterUser::query()->where('matter_id', $this->matter->id)->where('user_id', $this->newLead->id)->first();

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->callTableAction('declineSeat', $seat, data: ['reason' => 'Xung đột lợi ích.'])
        ->assertHasNoTableActionErrors();

    expect($admin->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(1)
        ->and($this->manager->notifications()->where('type', MatterAssignmentAlert::class)->count())->toBe(0);

    Mail::assertSent(MatterAssignmentNotice::class, fn (MatterAssignmentNotice $mail): bool => $mail->hasTo($admin->email));
    Mail::assertNotSent(MatterAssignmentNotice::class, fn (MatterAssignmentNotice $mail): bool => $mail->hasTo($this->manager->email));
});

it('rebuilds the task list when the digest is sent: a task moved on or finished meanwhile is left out', function () {
    handOver($this->matter, $this->manager, $this->newLead);
    Mail::fake();

    // Sau lần bàn giao: một việc đã giao tiếp cho trợ lý, một việc đã hoàn thành.
    $this->pending->forceFill(['assignee_id' => $this->assistant->id])->save();
    $this->accepted->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now()])->save();
    $kept = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Việc vẫn của lead mới', 'assignee_id' => $this->newLead->id, 'assigned_by' => $this->manager->id,
    ]);

    (new SendReassignmentDigest($this->newLead->id, [
        $this->matter->id => [
            'deadline_ids' => [],
            'client_request_ids' => [],
            'task_ids' => [$this->pending->id, $this->accepted->id, $kept->id],
            'reason' => 'Luật sư cũ nghỉ việc.',
        ],
    ]))->handle();

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'Việc vẫn của lead mới')
            && ! str_contains($html, 'Soạn bản tự khai')
            && ! str_contains($html, 'Nộp đơn khởi kiện');
    });
});
