<?php

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Matter\TransferStaffWork;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Jobs\SendStaffWorkTransferNotices;
use App\Mail\Staff\StaffWorkTransferred;
use App\Mail\Staff\TaskNotice;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\StaffWorkTransferAlert;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TeamDepartureAlert;
use App\Support\OpenTaskWork;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Gộp integ-fa (làn fa1 + fa2) — cờ lời báo của `ReassignTask` nối vào hai đường hàng loạt của làn fa2, nhận thay
 * (take-over) cho người bấm, và các phát hiện của rà soát cuối làn fa2: mốc hạn liệt kê từng mốc trong lời báo tổng
 * hợp (C1), người giao của việc đã chuyển được báo (I-A), số "việc đã giao" đúng sự thật (M-a), không việc nào bị bỏ
 * qua im lặng khi đổi người giao (M-b), và các minor. Mọi đường qua màn hình (Bàn giao hàng loạt, tab Đội ngũ).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Ho_Chi_Minh'));

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Viên']);
    $this->head = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Sắp Nghỉ']);
    $this->successor = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Nhận']);

    $this->first = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-IF-0001', 'title' => 'Tranh chấp kho bãi']);
    $this->second = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-IF-0002', 'title' => 'Thừa kế nhà phố']);

    foreach ([$this->first, $this->second] as $matter) {
        $matter->addTeamMember($this->associate, MatterRole::Associate);
        $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    }
});

function ifTask(User $holder, ?Matter $matter, ?User $assigner = null, TaskStatus $status = TaskStatus::Accepted): Task
{
    return Task::factory()->when($matter !== null, fn ($factory) => $factory->forMatter($matter))->status($status)->create([
        'title' => 'Sao y giấy tờ',
        'assignee_id' => $holder->id,
        'assigned_by' => ($assigner ?? test()->associate)->id,
    ]);
}

function ifTransfer(User $actor, User $from, User $to, string $reason = 'Trợ lý nghỉ việc, bàn giao toàn bộ việc.')
{
    test()->actingAs($actor, 'web');

    return test()->livewire(BulkReassign::class, ['from' => $from->id])
        ->set('staff.recipient_id', $to->id)
        ->callAction('transferStaffWork', data: ['reason' => $reason]);
}

function ifBells(User $user, string $type): array
{
    return $user->notifications()->where('type', $type)->get()->pluck('data')->all();
}

function ifDigestMail(User $to): StaffWorkTransferred
{
    $sent = Mail::sent(StaffWorkTransferred::class, fn (StaffWorkTransferred $mail): bool => $mail->hasTo($to->email));

    expect($sent)->toHaveCount(1);

    return $sent->first();
}

/** @return list<string> các giá trị "Mốc hạn" của thư tổng hợp */
function ifMailDeadlineLines(StaffWorkTransferred $mail): array
{
    return collect($mail->content()->with['details'])
        ->where('label', __('internal_flow.staff_work.digest.email.fields.deadline_item'))
        ->pluck('value')
        ->values()
        ->all();
}

// ---------------------------------------------------------------- A: cờ lời báo trên hai đường hàng loạt

it('moves the tasks in bulk with the digest as the only notice — no per-task bell or mail to the recipient, the leaver or the assigner', function () {
    ifTask($this->assistant, $this->first);
    ifTask($this->assistant, $this->second, status: TaskStatus::Pending);

    ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect(ifBells($this->successor, StaffWorkTransferAlert::class)[0]['body'])
        ->toContain(__('internal_flow.staff_work.digest.tasks', ['count' => 2]))
        ->and(ifBells($this->successor, TaskAlert::class))->toBe([])
        ->and(ifBells($this->assistant, TaskAlert::class))->toBe([])
        ->and(ifBells($this->associate, TaskAlert::class))->toBe([]);

    Mail::assertNotSent(TaskNotice::class);
});

it('rings the removed member once and the assigner once on a team removal — no per-task bells besides the new holder\'s', function () {
    $this->first->addTeamMember($this->successor, MatterRole::Assistant);
    ifTask($this->assistant, $this->first);
    ifTask($this->assistant, $this->first, status: TaskStatus::Pending);

    $this->actingAs($this->head, 'web');

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->first, 'pageClass' => ViewMatter::class])
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => $this->successor->id])
        ->assertHasNoTableActionErrors();

    expect(ifBells($this->assistant, TeamDepartureAlert::class))->toHaveCount(1)
        ->and(ifBells($this->assistant, TaskAlert::class))->toBe([])
        ->and(ifBells($this->associate, TeamDepartureAlert::class))->toHaveCount(1)
        ->and(ifBells($this->associate, TaskAlert::class))->toBe([])
        // Người nhận thay vẫn nhận chuông + thư "được giao" của từng việc.
        ->and(ifBells($this->successor, TaskAlert::class))->toHaveCount(2);

    Mail::assertSent(TaskNotice::class, 2);
});

// ---------------------------------------------------------------- A4: "Tôi nhận" trong hộp gỡ thành viên

it('offers the actor as "Tôi nhận (sang Đang làm)" in the removal dialog, keeps the lead lawyer as default even when they are the actor, and takes the tasks over', function () {
    $task = ifTask($this->assistant, $this->first, status: TaskStatus::Pending);

    $this->actingAs($this->lead, 'web');

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->first, 'pageClass' => ViewMatter::class])
        ->mountTableAction('removeMember', $this->assistant)
        ->assertFormFieldExists('task_recipient_id', fn (Select $field): bool => ($field->getOptions()[$this->lead->id] ?? null) === __('internal_flow.team_removal.take_over', ['name' => 'Luật sư Khang']))
        ->assertTableActionDataSet(['task_recipient_id' => $this->lead->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $fresh = $task->fresh();

    expect($fresh->assignee_id)->toBe($this->lead->id)
        ->and($fresh->status)->toBe(TaskStatus::Accepted)
        ->and($this->first->team()->whereKey($this->assistant->id)->exists())->toBeFalse()
        ->and(ifBells($this->lead, TaskAlert::class))->toBe([])
        ->and(ifBells($this->assistant, TaskAlert::class))->toBe([])
        ->and(ifBells($this->assistant, TeamDepartureAlert::class))->toHaveCount(1)
        ->and(Activity::query()->where('event', 'task_reassigned')->sole()->getExtraProperty('taken_over'))->toBeTrue();

    // Người giao (Luật sư Minh) nhận một chuông nói đúng: việc đã ở "Đang làm" của người gỡ, không "Chờ nhận".
    $assigner = ifBells($this->associate, TeamDepartureAlert::class);

    expect($assigner)->toHaveCount(1)
        ->and($assigner[0]['body'])->toBe(__('internal_flow.team_removal.tasks_handled.body_taken', [
            'member' => 'Trợ lý Sắp Nghỉ', 'matter' => __('internal_flow.team_removal.matter_named', ['code' => 'VK-2026-IF-0001']),
            'who' => 'Luật sư Khang', 'to' => 'Luật sư Khang', 'count' => 1,
        ]));
});

it('lists the held tasks of the removal dialog as separate lines', function () {
    ifTask($this->assistant, $this->first, status: TaskStatus::Pending)->update(['title' => 'Soạn đơn khởi kiện']);
    ifTask($this->assistant, $this->first)->update(['title' => 'Sao y CCCD']);

    $this->actingAs($this->head, 'web');

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->first, 'pageClass' => ViewMatter::class])
        ->mountTableAction('removeMember', $this->assistant)
        ->assertMountedActionModalSeeHtml(e(__('internal_flow.team_removal.task_line', ['title' => 'Soạn đơn khởi kiện', 'status' => TaskStatus::Pending->label(), 'due' => ''])).'<br>'
            .e(__('internal_flow.team_removal.task_line', ['title' => 'Sao y CCCD', 'status' => TaskStatus::Accepted->label(), 'due' => ''])));
});

// ---------------------------------------------------------------- C1 (I-B): người bấm nhận thay trong lô

it('lets the department head take the leaver\'s tasks over in bulk, after which the deactivation goes through', function () {
    $task = ifTask($this->assistant, $this->first);
    $general = ifTask($this->assistant, null, $this->associate, TaskStatus::Pending);

    $page = ifTransfer($this->head, $this->assistant, $this->head)->assertHasNoActionErrors();

    foreach ([$task, $general] as $moved) {
        expect($moved->fresh()->assignee_id)->toBe($this->head->id)
            ->and($moved->fresh()->status)->toBe(TaskStatus::Accepted);
    }

    expect(collect($page->get('staffResults'))->every(fn (array $row): bool => $row['success']))->toBeTrue()
        ->and(ifBells($this->head, TaskAlert::class))->toBe([])
        ->and(OpenTaskWork::forUser($this->assistant)->isEmpty())->toBeTrue();

    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->assistant->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------- B (C1 của rà soát): từng mốc trong lời báo tổng hợp

it('lists every moved deadline in the digest bell and mail — name, matter, due date, days left, severity — and flags one inside the last reminder tier', function () {
    $soon = Deadline::factory()->for($this->first)->create([
        'name' => 'Hạn kháng cáo', 'responsible_user_id' => $this->assistant->id, 'is_completed' => false,
        'due_date' => today()->addDay(), 'severity' => DeadlineSeverity::Critical,
    ]);
    $later = Deadline::factory()->for($this->second)->create([
        'name' => 'Hạn nộp chứng cứ', 'responsible_user_id' => $this->assistant->id, 'is_completed' => false,
        'due_date' => today()->addDays(9), 'severity' => DeadlineSeverity::Normal,
    ]);

    ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    $soonLine = __('internal_flow.staff_work.digest.deadline_line', [
        'name' => 'Hạn kháng cáo', 'matter' => __('internal_flow.team_removal.matter_named', ['code' => 'VK-2026-IF-0001']),
        'due' => __('internal_flow.deadline_assigned.due_in', ['date' => $soon->due_date->format('d/m/Y'), 'days' => 1]),
        'severity' => DeadlineSeverity::Critical->label(),
    ]);
    $laterLine = __('internal_flow.staff_work.digest.deadline_line', [
        'name' => 'Hạn nộp chứng cứ', 'matter' => __('internal_flow.team_removal.matter_named', ['code' => 'VK-2026-IF-0002']),
        'due' => __('internal_flow.deadline_assigned.due_in', ['date' => $later->due_date->format('d/m/Y'), 'days' => 9]),
        'severity' => DeadlineSeverity::Normal->label(),
    ]);
    $urgent = __('internal_flow.staff_work.digest.deadline_urgent', ['days' => min(CheckDeadlines::TIERS)]);

    $lines = ifMailDeadlineLines(ifDigestMail($this->successor));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe($urgent.' '.$soonLine)
        ->and($lines[1])->toBe($laterLine);

    $body = ifBells($this->successor, StaffWorkTransferAlert::class)[0]['body'];

    expect($body)->toContain(e($urgent.' '.$soonLine))->toContain(e($laterLine))
        ->and(substr_count($body, $urgent))->toBe(1);
});

it('never names a deadline nor its restricted matter in the digest when the recipient can no longer open the matter', function () {
    Deadline::factory()->for($this->first)->create([
        'name' => 'Hạn kín của vụ hạn chế', 'responsible_user_id' => $this->assistant->id, 'is_completed' => false,
        'due_date' => today()->addDays(4),
    ]);

    Queue::fake();

    ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    // Trước khi lời báo chạy, hồ sơ chuyển sang hạn chế — người nhận (không phụ trách, không quản trị) không còn mở được.
    $this->first->forceFill(['confidentiality' => Confidentiality::Restricted])->save();

    Queue::pushed(SendStaffWorkTransferNotices::class)->sole()->handle(app(ResolveStaffRecipients::class));

    $bell = json_encode(ifBells($this->successor, StaffWorkTransferAlert::class), JSON_UNESCAPED_UNICODE);
    $lines = ifMailDeadlineLines(ifDigestMail($this->successor));

    expect($bell)->not->toContain('VK-2026-IF-0001')->not->toContain('Hạn kín của vụ hạn chế')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toContain(__('internal_flow.team_removal.matter_hidden'))
        ->toContain(__('internal_flow.staff_work.digest.deadline_unnamed'))
        ->not->toContain('VK-2026-IF-0001')->not->toContain('Hạn kín');
});

// ---------------------------------------------------------------- C2 (I-A): người giao của việc đã chuyển

it('tells each assigner of the moved tasks once per batch, never the actor, the leaver or the recipient', function () {
    ifTask($this->assistant, $this->first);
    ifTask($this->assistant, $this->second);
    ifTask($this->assistant, null, $this->associate, TaskStatus::Pending);
    ifTask($this->assistant, $this->first, $this->admin);
    ifTask($this->assistant, $this->first, $this->successor);

    ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    $bells = ifBells($this->associate, StaffWorkTransferAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain('Trợ lý Sắp Nghỉ')->toContain('Trợ lý Nhận')
        ->toContain(__('internal_flow.staff_work.digest.tasks', ['count' => 3]))
        ->toContain('VK-2026-IF-0001')->toContain('VK-2026-IF-0002')
        ->and(ifBells($this->admin, StaffWorkTransferAlert::class))->toBe([])
        // Người nhận có đúng một chuông tổng hợp (loại "nhận việc"), không thêm chuông "người giao".
        ->and(ifBells($this->successor, StaffWorkTransferAlert::class))->toHaveCount(1)
        ->and(ifBells($this->assistant, StaffWorkTransferAlert::class))->toHaveCount(1);
});

it('does not tell an assigner about a moved task on a restricted matter they cannot open', function () {
    // Vụ hạn chế: chỉ luật sư phụ trách và quản trị viên mở được — người nhận là luật sư phụ trách.
    $this->first->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    $hidden = ifTask($this->assistant, $this->first);
    $open = ifTask($this->assistant, $this->second);

    ifTransfer($this->admin, $this->assistant, $this->lead)->assertHasNoActionErrors();

    expect($hidden->fresh()->assignee_id)->toBe($this->lead->id)
        ->and($open->fresh()->assignee_id)->toBe($this->lead->id);

    $bells = ifBells($this->associate, StaffWorkTransferAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain(__('internal_flow.staff_work.digest.tasks', ['count' => 1]))->toContain('VK-2026-IF-0002')
        ->and(json_encode($bells, JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-IF-0001');

    $others = json_encode(User::query()->whereKeyNot([$this->lead->id, $this->assistant->id])->get()
        ->flatMap(fn (User $user) => ifBells($user, StaffWorkTransferAlert::class))->all(), JSON_UNESCAPED_UNICODE);

    expect($others)->not->toContain('VK-2026-IF-0001');
});

it('counts for an assigner only the moved tasks the recipient still holds when the notice goes out', function () {
    ifTask($this->assistant, $this->first);
    $movedOn = ifTask($this->assistant, $this->second);

    Queue::fake();

    ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    // Trước khi lời báo chạy, một việc đã được giao tiếp cho người khác.
    $movedOn->fresh()->forceFill(['assignee_id' => $this->lead->id])->save();

    Queue::pushed(SendStaffWorkTransferNotices::class)->sole()->handle(app(ResolveStaffRecipients::class));

    $bells = ifBells($this->associate, StaffWorkTransferAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain(__('internal_flow.staff_work.digest.tasks', ['count' => 1]))
        ->not->toContain('VK-2026-IF-0002');
});

it('leaves a task in place and says so when the actor moving the work is the very person who assigned it', function () {
    // Trưởng phòng chuyển việc của chính mình: việc chung họ giao cho chính người nhận — không ai khác để theo dõi.
    $task = ifTask($this->successor, null, $this->head);

    $page = ifTransfer($this->head, $this->head, $this->successor)->assertHasNoActionErrors();

    $general = collect($page->get('staffResults'))->firstWhere('matterId', null);

    expect($task->fresh()->assigned_by)->toBe($this->head->id)
        ->and($general['success'])->toBeFalse()
        ->and($general['message'])->toContain(__('internal_flow.staff_work.results.followed_stuck', ['count' => 1]))
        ->and(OpenTaskWork::forUser($this->head)->assigned->count())->toBe(1);
});

// ---------------------------------------------------------------- M-a, M-b: đổi người giao

it('counts as "followed" only the tasks whose assigner became the recipient, and never skips one silently', function () {
    $outsider = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Ngoài']);
    $restricted = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-IF-0009', 'confidentiality' => Confidentiality::Restricted]);
    $restricted->addTeamMember($outsider, MatterRole::Assistant);

    // Người cũ giao: một việc cho người khác trên vụ thường (→ người nhận), một việc chính người nhận giữ (→ người
    // bấm), một việc trên vụ hạn chế người nhận không mở được (→ người bấm).
    $followed = ifTask($this->associate, $this->first, $this->assistant);
    $heldByHeir = ifTask($this->successor, null, $this->assistant);
    $hidden = ifTask($outsider, $restricted, $this->assistant);

    $page = ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect($followed->fresh()->assigned_by)->toBe($this->successor->id)
        ->and($heldByHeir->fresh()->assigned_by)->toBe($this->admin->id)
        ->and($hidden->fresh()->assigned_by)->toBe($this->admin->id)
        ->and(OpenTaskWork::forUser($this->assistant)->assigned->count())->toBe(0);

    $log = Activity::query()->where('event', 'staff_work_transferred')->sole();

    expect($log->getExtraProperty('tasks_followed'))->toBe(1)
        ->and($log->getExtraProperty('tasks_followed_by_actor'))->toBe(2);

    $mail = ifDigestMail($this->successor);
    $followedRow = collect($mail->content()->with['details'])->firstWhere('label', __('internal_flow.staff_work.digest.email.fields.followed'));

    expect($followedRow['value'])->toBe('1');

    $rows = collect($page->get('staffResults'));

    // Việc trên vụ hạn chế (dòng của hồ sơ đó) và việc chung chính người nhận giữ (dòng việc chung): mỗi dòng nói riêng.
    expect($rows->firstWhere('matterId', $restricted->id)['message'])->toContain(__('internal_flow.staff_work.results.followed_by_you', ['count' => 1]))
        ->and($rows->firstWhere('matterId', null)['message'])->toContain(__('internal_flow.staff_work.results.followed_by_you', ['count' => 1]));
});

it('makes a task the leaver gave the actor-recipient the actor\'s own task instead of skipping it', function () {
    $this->first->addTeamMember($this->head, MatterRole::Associate);
    $task = ifTask($this->head, $this->first, $this->assistant);

    ifTransfer($this->head, $this->assistant, $this->head)->assertHasNoActionErrors();

    expect($task->fresh()->assigned_by)->toBe($this->head->id)
        ->and(OpenTaskWork::forUser($this->assistant)->isEmpty())->toBeTrue();
});

// ---------------------------------------------------------------- minors của rà soát cuối

it('does not call an inner refusal of an admin "cần quản trị viên chuyển"', function () {
    Deadline::factory()->for($this->first)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false]);

    app()->bind(ChangeDeadlineResponsible::class, fn () => new class extends ChangeDeadlineResponsible
    {
        public function handle(Deadline $deadline, User $actor, User $newResponsible, bool $notify = true): Deadline
        {
            throw new AuthorizationException;
        }
    });

    $page = ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();
    $rows = collect($page->get('staffResults'));
    $first = $rows->firstWhere('matterId', $this->first->id);

    expect($first['success'])->toBeFalse()
        ->and($first['message'])->toBe(__('internal_flow.staff_work.results.refused'))
        ->and($rows->pluck('message')->implode(' '))->not->toContain(__('internal_flow.staff_work.results.unmanaged', ['count' => 1]));
});

it('never prints the code or title of a matter the actor was not cleared for when something breaks before the check', function () {
    $restricted = Matter::factory()->create([
        'lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-IF-0777', 'title' => 'Vụ kín',
        'confidentiality' => Confidentiality::Restricted,
    ]);
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant);

    // Hỏng ngay ở câu hỏi quyền của chính lần chuyển (màn hình vẫn vẽ bình thường): người bấm chưa được xác nhận
    // quản lý hồ sơ này.
    Gate::before(function (User $user, string $ability, array $arguments) use ($restricted): ?bool {
        $inTransfer = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))
            ->contains(fn (array $frame): bool => ($frame['class'] ?? null) === TransferStaffWork::class);

        if ($inTransfer && $ability === 'manageTeam' && ($arguments[0] ?? null) instanceof Matter && $arguments[0]->is($restricted)) {
            throw new RuntimeException('hỏng giữa chừng');
        }

        return null;
    });

    $page = ifTransfer($this->head, $this->assistant, $this->successor)->assertHasNoActionErrors();
    $rows = collect($page->get('staffResults'));

    expect(json_encode($rows->all(), JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-IF-0777')->not->toContain('Vụ kín')
        ->and($rows->pluck('matterId')->all())->not->toContain($restricted->id)
        ->and($rows->where('matterId', null)->pluck('message')->all())->toContain(__('reassign.bulk.results.unexpected_error'));

    $page->assertDontSee('VK-2026-IF-0777');
});

it('still logs and sends the digest when changing who follows the general tasks breaks', function () {
    Deadline::factory()->for($this->first)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false]);
    ifTask($this->associate, null, $this->assistant);

    Task::updating(function (Task $task): void {
        if ($task->matter_id === null && $task->isDirty('assigned_by')) {
            throw new RuntimeException('hỏng giữa chừng');
        }
    });

    $page = ifTransfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();
    $general = collect($page->get('staffResults'))->first(fn (array $row): bool => $row['matterId'] === null && str_contains($row['message'], __('reassign.bulk.results.unexpected_error')));

    expect($general)->not->toBeNull()
        ->and($general['success'])->toBeFalse()
        ->and(Activity::query()->where('event', 'staff_work_transferred')->count())->toBe(1)
        ->and(ifBells($this->successor, StaffWorkTransferAlert::class))->toHaveCount(1);
});

it('shows the action\'s own refusals under the reason field, or in a notification for the recipient', function () {
    app()->bind(TransferStaffWork::class, fn () => new class extends TransferStaffWork
    {
        public function handle(User $from, User $to, User $actor, string $reason): array
        {
            throw ValidationException::withMessages(['reason' => ['Lý do bị từ chối.'], 'recipient_id' => ['Người nhận vừa bị khoá.']]);
        }
    });

    ifTransfer($this->admin, $this->assistant, $this->successor)
        ->assertHasActionErrors(['reason'])
        ->assertNotified(__('actions.failed_title'));
});
