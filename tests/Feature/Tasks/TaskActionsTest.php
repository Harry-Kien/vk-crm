<?php

use App\Actions\Task\AcceptTask;
use App\Actions\Task\AssignTask;
use App\Actions\Task\CancelTask;
use App\Actions\Task\CompleteTask;
use App\Actions\Task\DeclineTask;
use App\Actions\Task\ReassignTask;
use App\Actions\Task\TaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mail\Staff\TaskNotice;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\TaskAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 1 — nghiệp vụ giao, nhận, trả lại, hoàn thành, sửa, giao lại, huỷ một việc nội bộ. Tệp
 * này gọi thẳng Action (đo NGHIỆP VỤ: khoá, trạng thái, người nhận thư); hành vi màn hình đo qua
 * Livewire ở `TasksRelationManagerTest`, `TaskResourceTest`, `MyTasksWidgetsTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function tkAssign(User $actor, ?Matter $matter, User $assignee, array $overrides = []): Task
{
    return app(AssignTask::class)->handle(
        actor: $actor,
        matter: $matter,
        assignee: $assignee,
        title: $overrides['title'] ?? 'Soạn đơn khởi kiện',
        description: $overrides['description'] ?? null,
        dueDate: $overrides['dueDate'] ?? null,
        priority: $overrides['priority'] ?? TaskPriority::Normal,
        submissionKey: $overrides['submissionKey'] ?? null,
    );
}

function tkEvents(Task $task): array
{
    return Activity::query()
        ->where('subject_type', 'task')
        ->where('subject_id', $task->id)
        ->orderBy('id')
        ->pluck('event')
        ->all();
}

function tkValidationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Không có ValidationException.');
}

// =============================================================================================
// GIAO VIỆC
// =============================================================================================

it('assigns a matter task to a team member, records it and tells only the assignee, by bell and by mail', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant, [
        'title' => '  Soạn đơn khởi kiện  ',
        'description' => 'Theo mẫu của toà.',
        'dueDate' => today()->addDays(3)->toDateString(),
        'priority' => TaskPriority::Urgent,
    ]);

    expect($task->status)->toBe(TaskStatus::Pending)
        ->and($task->title)->toBe('Soạn đơn khởi kiện')
        ->and($task->matter_id)->toBe($this->matter->id)
        ->and($task->assignee_id)->toBe($this->assistant->id)
        ->and($task->assigned_by)->toBe($this->associate->id)
        ->and($task->priority)->toBe(TaskPriority::Urgent)
        ->and($task->due_date->toDateString())->toBe(today()->addDays(3)->toDateString())
        ->and($task->assigned_at)->not->toBeNull();

    $row = Activity::query()->where('event', 'task_assigned')->sole();
    expect($row->causer_id)->toBe($this->associate->id)
        ->and($row->properties['matter_id'])->toBe($this->matter->id)
        ->and($row->properties['assignee_id'])->toBe($this->assistant->id)
        ->and($row->properties['to'])->toBe('pending');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->assistant->email)
        && $mail->kind === TaskNotice::KIND_ASSIGNED);
    Mail::assertSent(TaskNotice::class, 1);

    expect($this->assistant->notifications()->where('type', TaskAlert::class)->count())->toBe(1)
        ->and($this->associate->notifications()->count())->toBe(0);
});

it('assigns a general task from a manager to the accountant, without a matter id in the log', function () {
    $task = tkAssign($this->manager, null, $this->accountant);

    expect($task->matter_id)->toBeNull();

    $row = Activity::query()->where('event', 'task_assigned')->sole();
    expect($row->properties->has('matter_id'))->toBeFalse();

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->accountant->email));
});

it('refuses to assign work to someone outside the team, an observer, an inactive or deleted account, or oneself', function (string $who) {
    $assignee = match ($who) {
        'outsider' => $this->outsider,
        'observer' => tap(User::factory()->withRole(Role::Lawyer)->create(), fn (User $u) => $this->matter->addTeamMember($u, MatterRole::Observer)),
        'inactive' => tap($this->otherAssistant)->forceFill(['is_active' => false])->save() ? $this->otherAssistant : null,
        'deleted' => tap($this->otherAssistant)->delete() ? $this->otherAssistant : null,
        'self' => $this->associate,
    };

    $errors = tkValidationErrors(fn () => tkAssign($this->associate, $this->matter, $assignee));

    expect($errors)->toHaveKey('assignee_id')
        ->and(Task::query()->count())->toBe(0);
    Mail::assertNothingSent();
})->with(['outsider', 'observer', 'inactive', 'deleted', 'self']);

it('refuses a general task for a deleted account, even one still marked active', function () {
    $this->accountant->delete();

    expect(tkValidationErrors(fn () => tkAssign($this->manager, null, $this->accountant)))->toHaveKey('assignee_id');
});

it('refuses to assign work on a restricted matter to a team member who cannot open it', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    // Dữ liệu cũ: một thành viên khác lead còn sót trong đội ngũ của vụ hạn chế.
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant);

    expect(tkValidationErrors(fn () => tkAssign($this->lead, $restricted, $this->assistant)))->toHaveKey('assignee_id');

    $restricted->addTeamMember($this->admin, MatterRole::Associate);
    expect(tkAssign($this->lead, $restricted, $this->admin)->assignee_id)->toBe($this->admin->id);
});

it('lists as candidates exactly the active working team members who can open the matter, never the actor', function () {
    $observer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($observer, MatterRole::Observer);
    $inactive = User::factory()->withRole(Role::Assistant)->create(['is_active' => false]);
    $this->matter->addTeamMember($inactive, MatterRole::Assistant);

    $candidates = app(TaskAssignees::class)->eligible($this->matter, $this->associate)->pluck('id')->sort()->values()->all();

    expect($candidates)->toBe(collect([$this->lead->id, $this->assistant->id, $this->otherAssistant->id])->sort()->values()->all());

    // Việc chung: mọi nhân sự đang hoạt động, trừ chính người giao.
    $general = app(TaskAssignees::class)->eligible(null, $this->manager)->pluck('id');
    expect($general)->toContain($this->accountant->id, $this->outsider->id, $this->admin->id)
        ->not->toContain($this->manager->id)
        ->not->toContain($inactive->id);
});

it('never offers a stray non-lead member of a restricted matter as a candidate', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant);
    $restricted->addTeamMember($this->admin, MatterRole::Associate);

    expect(app(TaskAssignees::class)->eligible($restricted, $this->lead)->pluck('id')->all())->toBe([$this->admin->id]);
});

it('offers the current holder again when reassigning only once they have declined', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    expect(app(TaskAssignees::class)->options($this->matter, $this->associate, $task->fresh()))->not->toHaveKey($this->assistant->id);

    app(DeclineTask::class)->handle($task, $this->assistant, 'Cần thêm hồ sơ');

    expect(app(TaskAssignees::class)->options($this->matter, $this->associate, $task->fresh()))->toHaveKey($this->assistant->id);
});

it('refuses an actor who may not assign the work, with one generic refusal', function (string $who, bool $general) {
    $actor = match ($who) {
        'assistant' => $this->assistant,
        'outsider' => $this->outsider,
        'lawyer' => $this->lead,
        'accountant' => $this->accountant,
        'inactive manager' => tap($this->manager)->forceFill(['is_active' => false])->save() ? $this->manager : null,
    };

    // Sửa sau kiểm toán 2026-10-21 (mục 1): trên một vụ, lời từ chối gắn vào ô "Vụ việc" — vẫn MỘT câu cho mọi nguyên
    // nhân (không phân biệt "không có quyền" với "không tồn tại").
    $general
        ? expect(fn () => tkAssign($actor, null, $this->accountant))->toThrow(AuthorizationException::class, __('tasks.errors.unavailable'))
        : expect(tkValidationErrors(fn () => tkAssign($actor, $this->matter, $this->otherAssistant)))
            ->toBe(['matter_id' => [__('tasks.lifecycle.matter_unavailable')]]);

    expect(Task::query()->count())->toBe(0);
})->with([
    'trợ lý trên vụ' => ['assistant', false],
    'luật sư ngoài đội ngũ' => ['outsider', false],
    // Làn fa1 (phán quyết 3): luật sư và trợ lý nay giao được việc chung — chỉ người không có `matter.view` thì không.
    'kế toán giao việc chung' => ['accountant', true],
    'quản lý đã bị vô hiệu' => ['inactive manager', true],
]);

it('refuses to assign work on a closed matter', function () {
    $this->matter->forceFill(['closed_at' => today()])->save();

    // Sửa sau kiểm toán 2026-10-21 (mục 1): lời từ chối gắn vào ô "Vụ việc".
    expect(tkValidationErrors(fn () => tkAssign($this->lead, $this->matter, $this->assistant)))
        ->toBe(['matter_id' => [__('tasks.lifecycle.matter_unavailable')]]);
});

it('validates the title, the description and the due date', function (array $input, string $field) {
    expect(tkValidationErrors(fn () => tkAssign($this->lead, $this->matter, $this->assistant, $input)))->toHaveKey($field);
})->with([
    'tiêu đề trống' => [['title' => '   '], 'title'],
    'tiêu đề quá dài' => [['title' => str_repeat('a', Task::TITLE_MAX_LENGTH + 1)], 'title'],
    'mô tả quá dài' => [['description' => str_repeat('a', Task::DESCRIPTION_MAX_LENGTH + 1)], 'description'],
    'hạn đã qua' => [['dueDate' => today()->subDay()->toDateString()], 'due_date'],
    'hạn không phải ngày' => [['dueDate' => 'ngày mai'], 'due_date'],
]);

it('accepts the longest title and description the columns hold, and a due date of today', function () {
    $task = tkAssign($this->lead, $this->matter, $this->assistant, [
        'title' => str_repeat('ệ', Task::TITLE_MAX_LENGTH),
        'description' => str_repeat('ệ', Task::DESCRIPTION_MAX_LENGTH),
        'dueDate' => today()->toDateString(),
    ]);

    expect(mb_strlen($task->fresh()->title))->toBe(Task::TITLE_MAX_LENGTH)
        ->and(mb_strlen($task->fresh()->description))->toBe(Task::DESCRIPTION_MAX_LENGTH);
});

it('creates one task, not two, when the same dialog is submitted twice in a row', function () {
    // Làn fa1 (phán quyết 4): chống trùng theo mã một lần của hộp thoại, không theo tiêu đề.
    $key = '7f3c2a10-0000-4000-8000-000000000001';
    $first = tkAssign($this->lead, $this->matter, $this->assistant, ['submissionKey' => $key]);
    $second = tkAssign($this->lead, $this->matter, $this->assistant, ['submissionKey' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(Task::query()->count())->toBe(1)
        ->and(tkEvents($first))->toBe(['task_assigned']);
    Mail::assertSent(TaskNotice::class, 1);

    // Một hộp thoại KHÁC (mã khác, hay không mã) là một lần giao mới — kể cả cùng tiêu đề.
    tkAssign($this->lead, $this->matter, $this->assistant, ['submissionKey' => '7f3c2a10-0000-4000-8000-000000000002']);
    tkAssign($this->lead, $this->matter, $this->assistant);
    expect(Task::query()->count())->toBe(3);
});

it('sends nothing when the assignment is rolled back by the caller, and only after the commit otherwise', function () {
    try {
        DB::transaction(function () {
            tkAssign($this->lead, $this->matter, $this->assistant);
            Mail::assertNothingSent();

            throw new RuntimeException('huỷ');
        });
    } catch (RuntimeException) {
    }

    Mail::assertNothingSent();
    expect(Task::query()->count())->toBe(0)
        ->and($this->assistant->notifications()->count())->toBe(0);
});

// =============================================================================================
// NHẬN, TRẢ LẠI, HOÀN THÀNH
// =============================================================================================

it('accepts a pending task once, tells the assigner, and treats a second press as done', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    Mail::fake();

    $accepted = app(AcceptTask::class)->handle($task, $this->assistant);
    $again = app(AcceptTask::class)->handle($task, $this->assistant);

    expect($accepted->status)->toBe(TaskStatus::Accepted)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($again->status)->toBe(TaskStatus::Accepted)
        ->and(tkEvents($task))->toBe(['task_assigned', 'task_accepted']);

    $row = Activity::query()->where('event', 'task_accepted')->sole();
    expect($row->properties['from'])->toBe('pending')
        ->and($row->properties['to'])->toBe('accepted');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email)
        && $mail->kind === TaskNotice::KIND_ACCEPTED);
    Mail::assertSent(TaskNotice::class, 1);
});

it('refuses to let anyone but the assignee accept, with the generic refusal', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    foreach ([$this->otherAssistant, $this->associate, $this->admin] as $actor) {
        expect(fn () => app(AcceptTask::class)->handle($task, $actor))->toThrow(AuthorizationException::class);
    }

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});

it('re-reads the task under lock: a stale screen cannot accept a task that was cancelled meanwhile', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    $stale = $task->fresh();

    app(CancelTask::class)->handle($task, $this->associate, 'Khách rút yêu cầu');

    expect(fn () => app(AcceptTask::class)->handle($stale, $this->assistant))
        ->toThrow(DomainException::class, __('tasks.errors.not_in_state', ['status' => TaskStatus::Cancelled->label()]));

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('gives someone who cannot see the task the generic refusal, never the task\'s current state', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(CancelTask::class)->handle($task, $this->associate, 'Khách rút yêu cầu');

    expect(fn () => app(AcceptTask::class)->handle($task, $this->outsider))
        ->toThrow(AuthorizationException::class, __('tasks.errors.unavailable'));
});

it('refuses an assignee whose account was deactivated meanwhile', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    $this->assistant->forceFill(['is_active' => false])->save();

    expect(fn () => app(AcceptTask::class)->handle($task, $this->assistant))->toThrow(AuthorizationException::class);
});

it('declines with a required reason, tells the assigner the reason, and keeps one row for a double press', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    expect(tkValidationErrors(fn () => app(DeclineTask::class)->handle($task, $this->assistant, '  ')))->toHaveKey('reason')
        ->and(tkValidationErrors(fn () => app(DeclineTask::class)->handle($task, $this->assistant, str_repeat('a', Task::REASON_MAX_LENGTH + 1))))->toHaveKey('reason');

    Mail::fake();
    $declined = app(DeclineTask::class)->handle($task, $this->assistant, 'Đang quá tải, nhờ chị Lan');
    app(DeclineTask::class)->handle($task, $this->assistant, 'Đang quá tải, nhờ chị Lan');

    expect($declined->status)->toBe(TaskStatus::Declined)
        ->and($declined->decline_reason)->toBe('Đang quá tải, nhờ chị Lan')
        ->and($declined->declined_at)->not->toBeNull()
        ->and(tkEvents($task))->toBe(['task_assigned', 'task_declined']);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email)
        && $mail->kind === TaskNotice::KIND_DECLINED
        && str_contains($mail->render(), 'Đang quá tải, nhờ chị Lan'));
    Mail::assertSent(TaskNotice::class, 1);
});

it('lets the assignee decline a task already accepted, but not one already completed', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(AcceptTask::class)->handle($task, $this->assistant);

    expect(app(DeclineTask::class)->handle($task, $this->assistant, 'Xung đột lợi ích')->status)->toBe(TaskStatus::Declined);

    $other = tkAssign($this->associate, $this->matter, $this->otherAssistant, ['title' => 'Việc khác']);
    app(CompleteTask::class)->handle($other, $this->otherAssistant, null);

    expect(fn () => app(DeclineTask::class)->handle($other, $this->otherAssistant, 'Muộn rồi'))->toThrow(DomainException::class);
});

it('completes a task straight from pending, stamping the acceptance too, with an optional note', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    Mail::fake();

    expect(tkValidationErrors(fn () => app(CompleteTask::class)->handle($task, $this->assistant, str_repeat('a', Task::NOTE_MAX_LENGTH + 1))))->toHaveKey('note');

    $done = app(CompleteTask::class)->handle($task, $this->assistant, '  Đã nộp ở toà, biên nhận trong tab tài liệu  ');
    app(CompleteTask::class)->handle($task, $this->assistant, null);

    expect($done->status)->toBe(TaskStatus::Completed)
        ->and($done->completed_at)->not->toBeNull()
        ->and($done->accepted_at)->not->toBeNull()
        ->and($done->completion_note)->toBe('Đã nộp ở toà, biên nhận trong tab tài liệu')
        ->and(tkEvents($task))->toBe(['task_assigned', 'task_completed']);

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email)
        && $mail->kind === TaskNotice::KIND_COMPLETED);
    Mail::assertSent(TaskNotice::class, 1);
});

// =============================================================================================
// SỬA, GIAO LẠI, HUỶ
// =============================================================================================

it('lets the assigner edit an open task and records which fields changed', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    $updated = app(UpdateTask::class)->handle(
        task: $task,
        actor: $this->associate,
        title: 'Soạn đơn khởi kiện (bản 2)',
        description: 'Thêm phần yêu cầu bồi thường',
        dueDate: today()->addWeek()->toDateString(),
        priority: TaskPriority::High,
        expectedUpdatedAt: $task->updated_at->toDateTimeString(),
    );

    expect($updated->title)->toBe('Soạn đơn khởi kiện (bản 2)')
        ->and($updated->priority)->toBe(TaskPriority::High)
        ->and($updated->due_date->toDateString())->toBe(today()->addWeek()->toDateString());

    $row = Activity::query()->where('event', 'task_updated')->sole();
    expect($row->properties['changed'])->toEqualCanonicalizing(['title', 'description', 'due_date', 'priority']);
});

it('refuses an edit made on a screen opened before someone else changed the task', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    $openedAt = $task->updated_at->toDateTimeString();

    $this->travel(5)->seconds();
    app(AcceptTask::class)->handle($task, $this->assistant);

    expect(tkValidationErrors(fn () => app(UpdateTask::class)->handle(
        task: $task,
        actor: $this->associate,
        title: 'Đổi tiêu đề',
        description: null,
        dueDate: null,
        priority: TaskPriority::Normal,
        expectedUpdatedAt: $openedAt,
    )))->toHaveKey('title');
});

it('keeps an already-past due date on edit, but refuses moving it into the past', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant, ['dueDate' => today()->toDateString()]);
    $this->travel(3)->days();

    $edit = fn (?string $due) => app(UpdateTask::class)->handle(
        task: $task,
        actor: $this->associate,
        title: 'Tiêu đề mới',
        description: null,
        dueDate: $due,
        priority: TaskPriority::Normal,
        expectedUpdatedAt: null,
    );

    expect($edit($task->due_date->toDateString())->title)->toBe('Tiêu đề mới')
        ->and(tkValidationErrors(fn () => $edit(today()->subDay()->toDateString())))->toHaveKey('due_date');
});

it('does not let the assignee edit, reassign or cancel their own task', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    expect(fn () => app(UpdateTask::class)->handle($task, $this->assistant, 'x', null, null, TaskPriority::Normal, null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReassignTask::class)->handle($task, $this->assistant, $this->otherAssistant))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelTask::class)->handle($task, $this->assistant, 'Không làm'))->toThrow(AuthorizationException::class);
});

it('reassigns a declined task to someone else as a fresh pending task, and tells the new assignee', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(DeclineTask::class)->handle($task, $this->assistant, 'Quá tải');
    Mail::fake();

    $moved = app(ReassignTask::class)->handle($task, $this->associate, $this->otherAssistant);

    expect($moved->status)->toBe(TaskStatus::Pending)
        ->and($moved->assignee_id)->toBe($this->otherAssistant->id)
        ->and($moved->assigned_by)->toBe($this->associate->id)
        ->and($moved->decline_reason)->toBeNull()
        ->and($moved->declined_at)->toBeNull()
        ->and($moved->accepted_at)->toBeNull();

    $row = Activity::query()->where('event', 'task_reassigned')->sole();
    expect($row->properties['previous_assignee_id'])->toBe($this->assistant->id)
        ->and($row->properties['assignee_id'])->toBe($this->otherAssistant->id)
        ->and($row->properties['from'])->toBe('declined');

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->otherAssistant->email)
        && $mail->kind === TaskNotice::KIND_ASSIGNED);
    Mail::assertSent(TaskNotice::class, 1);
});

it('lets a declined task go back to the same person, but not an open one, and never to someone ineligible', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);

    expect(tkValidationErrors(fn () => app(ReassignTask::class)->handle($task, $this->associate, $this->assistant)))->toHaveKey('assignee_id')
        ->and(tkValidationErrors(fn () => app(ReassignTask::class)->handle($task, $this->associate, $this->outsider)))->toHaveKey('assignee_id');

    app(DeclineTask::class)->handle($task, $this->assistant, 'Cần thêm thông tin');

    expect(app(ReassignTask::class)->handle($task, $this->associate, $this->assistant)->status)->toBe(TaskStatus::Pending);
});

it('does not let the person reassigning hand the task to themselves', function () {
    $task = tkAssign($this->lead, $this->matter, $this->assistant);
    app(DeclineTask::class)->handle($task, $this->assistant, 'Không kịp');

    expect(tkValidationErrors(fn () => app(ReassignTask::class)->handle($task, $this->lead, $this->lead)))->toHaveKey('assignee_id')
        ->and($task->fresh()->assignee_id)->toBe($this->assistant->id);
});

it('lets a manager move the open task of a deactivated assignee to someone else', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(AcceptTask::class)->handle($task, $this->assistant);
    $this->assistant->forceFill(['is_active' => false])->save();

    $moved = app(ReassignTask::class)->handle($task, $this->manager, $this->otherAssistant);

    expect($moved->assignee_id)->toBe($this->otherAssistant->id)
        ->and($moved->status)->toBe(TaskStatus::Pending);
});

it('cancels with a required reason, tells the assignee by bell only, and treats a second press as done', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    Mail::fake();

    expect(tkValidationErrors(fn () => app(CancelTask::class)->handle($task, $this->associate, '')))->toHaveKey('reason');

    $cancelled = app(CancelTask::class)->handle($task, $this->associate, 'Khách rút yêu cầu');
    app(CancelTask::class)->handle($task, $this->associate, 'Khách rút yêu cầu');

    expect($cancelled->status)->toBe(TaskStatus::Cancelled)
        ->and($cancelled->cancel_reason)->toBe('Khách rút yêu cầu')
        ->and(tkEvents($task))->toBe(['task_assigned', 'task_cancelled']);

    Mail::assertNothingSent();
    expect($this->assistant->notifications()->where('type', TaskAlert::class)->count())->toBe(2);
});

it('does not ring the former assignee when a task they already declined is cancelled', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(DeclineTask::class)->handle($task, $this->assistant, 'Không kịp');

    app(CancelTask::class)->handle($task, $this->associate, 'Không cần nữa');

    expect($this->assistant->notifications()->where('type', TaskAlert::class)->count())->toBe(1);
});

it('does not send the assigned notice to an assignee who already accepted before it went out', function () {
    DB::transaction(function () {
        $task = tkAssign($this->associate, $this->matter, $this->assistant);
        app(AcceptTask::class)->handle($task, $this->assistant);
    });

    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_ASSIGNED);
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_ACCEPTED);
});

it('cannot cancel, edit or reassign a completed task', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    app(CompleteTask::class)->handle($task, $this->assistant, null);

    expect(fn () => app(CancelTask::class)->handle($task, $this->associate, 'x'))->toThrow(DomainException::class)
        ->and(fn () => app(ReassignTask::class)->handle($task, $this->associate, $this->otherAssistant))->toThrow(DomainException::class)
        ->and(fn () => app(UpdateTask::class)->handle($task, $this->associate, 'x', null, null, TaskPriority::Normal, null))->toThrow(DomainException::class);
});

// =============================================================================================
// NGƯỜI NHẬN THƯ — không bao giờ ai không còn được xem việc
// =============================================================================================

it('sends no mail and no bell to an assigner who has left or can no longer see the task — the lead gets it instead', function () {
    $task = tkAssign($this->associate, $this->matter, $this->assistant);
    Mail::fake();

    $this->associate->forceFill(['is_active' => false])->save();
    app(AcceptTask::class)->handle($task, $this->assistant);

    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email));
    expect($this->associate->notifications()->count())->toBe(0);

    // Người giao rời đội ngũ: không còn xem được vụ, nên không còn xem được việc.
    $this->associate->forceFill(['is_active' => true])->save();
    $this->matter->team()->detach($this->associate->id);
    app(CompleteTask::class)->handle($task, $this->assistant, null);

    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->associate->email));
    expect($this->associate->notifications()->count())->toBe(0);

    // Làn fa1 (phán quyết 7): "đã nhận" và "đã hoàn thành" đi qua `forTaskAssigner()` — luật sư phụ trách nhận thay.
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->lead->email) && $mail->kind === TaskNotice::KIND_ACCEPTED);
    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->hasTo($this->lead->email) && $mail->kind === TaskNotice::KIND_COMPLETED);
});

it('does not tell an assignee about a task that was reassigned away before the notice went out', function () {
    $task = DB::transaction(function () {
        $task = tkAssign($this->associate, $this->matter, $this->assistant);
        app(DeclineTask::class)->handle($task, $this->assistant, 'Không kịp');
        app(ReassignTask::class)->handle($task, $this->associate, $this->otherAssistant);

        return $task;
    });

    Mail::assertSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_ASSIGNED && $mail->hasTo($this->otherAssistant->email));
    Mail::assertNotSent(TaskNotice::class, fn (TaskNotice $mail): bool => $mail->kind === TaskNotice::KIND_ASSIGNED && $mail->hasTo($this->assistant->email));
    expect($task->fresh()->assignee_id)->toBe($this->otherAssistant->id);
});
