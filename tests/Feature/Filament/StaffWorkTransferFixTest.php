<?php

use App\Actions\Matter\TransferStaffWork;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Mail\Staff\DeadlineAssigned;
use App\Mail\Staff\StaffWorkTransferred;
use App\Mail\Staff\TaskNotice;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\DeadlineAssignedAlert;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\StaffWorkTransferAlert;
use App\Notifications\Staff\TaskAlert;
use App\Notifications\Staff\TeamDepartureAlert;
use App\Support\OpenTaskWork;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn fa2, Task 1, vòng sửa 1 — phần "Việc nội bộ và ghế" của "Bàn giao hàng loạt" ({@see TransferStaffWork}) sau
 * rà soát: không lộ hồ sơ hạn chế người bấm không quản lý (I1), một lời báo tổng hợp cho cả lô (I2), việc "Cần giao
 * lại" sau một lần gỡ chuyển được (I3), người bấm làm người nhận được (minor 3), chỉ ghế giữ việc mới chuyển (minor
 * 4), hộp xác nhận + lý do bắt buộc (minor 5), nhật ký chỉ khi có gì chuyển (minor 9). Mọi đường qua màn hình.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Viên']);
    $this->head = User::factory()->position(UserPosition::Manager)->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Sắp Nghỉ']);
    $this->successor = User::factory()->position(UserPosition::Assistant)->withRole(Role::Assistant)->create(['name' => 'Trợ lý Nhận']);

    $this->first = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0701', 'title' => 'Tranh chấp hợp đồng thuê kho']);
    $this->second = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0702', 'title' => 'Ly hôn có tranh chấp tài sản']);

    foreach ([$this->first, $this->second] as $matter) {
        $matter->addTeamMember($this->assistant, MatterRole::Assistant);
    }
});

function fa2r1Task(User $holder, ?Matter $matter, TaskStatus $status = TaskStatus::Accepted): Task
{
    return Task::factory()->when($matter !== null, fn ($factory) => $factory->forMatter($matter))->status($status)->create([
        'title' => 'Sao y giấy tờ',
        'assignee_id' => $holder->id,
        'assigned_by' => test()->lead->id,
    ]);
}

function fa2r1Transfer(User $actor, User $from, User $to, string $reason = 'Trợ lý nghỉ việc cuối tháng, bàn giao toàn bộ việc.')
{
    test()->actingAs($actor, 'web');

    return test()->livewire(BulkReassign::class, ['from' => $from->id])
        ->set('staff.recipient_id', $to->id)
        ->callAction('transferStaffWork', data: ['reason' => $reason]);
}

function fa2r1Bells(User $user, string $type): array
{
    return $user->notifications()->where('type', $type)->get()->pluck('data')->all();
}

it('never shows the department head a restricted matter they cannot manage — one count line, no id, code or title', function () {
    $restricted = Matter::factory()->create([
        'lead_lawyer_id' => $this->lead->id,
        'code' => 'VK-2026-HC-0999',
        'title' => 'Vụ kín của giám đốc',
        'confidentiality' => Confidentiality::Restricted,
    ]);
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant);
    $hidden = fa2r1Task($this->assistant, $restricted);

    $page = fa2r1Transfer($this->head, $this->assistant, $this->successor)->assertHasNoActionErrors();

    $results = collect($page->get('staffResults'));

    expect($results->pluck('matterId')->filter()->all())->not->toContain($restricted->id)
        ->and($results->pluck('matterId')->filter()->values()->all())->toEqualCanonicalizing([$this->first->id, $this->second->id])
        ->and(json_encode($results->all(), JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-HC-0999')->not->toContain('Vụ kín của giám đốc')
        ->and($results->where('matterId', null)->pluck('message')->all())
        ->toContain(__('internal_flow.staff_work.results.unmanaged', ['count' => 1]));

    $page->assertDontSee('VK-2026-HC-0999')->assertDontSee('Vụ kín của giám đốc')->assertDontSeeHtml('#'.$restricted->id.'</span>');

    // Hồ sơ hạn chế không bị đụng tới: người cũ còn ghế, còn giữ việc.
    expect($restricted->team()->whereKey($this->assistant->id)->exists())->toBeTrue()
        ->and($hidden->fresh()->assignee_id)->toBe($this->assistant->id);

    $log = Activity::query()->where('event', 'staff_work_transferred')->sole();

    expect($log->getExtraProperty('matters_unmanaged'))->toBe(1)
        ->and(json_encode($log->properties, JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-HC-0999');

    foreach ([$this->head, $this->successor, $this->assistant] as $user) {
        expect(json_encode($user->notifications()->get()->pluck('data')->all(), JSON_UNESCAPED_UNICODE))
            ->not->toContain('VK-2026-HC-0999')->not->toContain('Vụ kín của giám đốc');
    }
});

it('sends one digest bell and one digest mail to the recipient, and one bell to the leaver, for a whole batch of seats and deadlines', function () {
    foreach ([$this->first, $this->second] as $matter) {
        Deadline::factory()->for($matter)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false, 'due_date' => today()->addDays(5)]);
        Deadline::factory()->for($matter)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false, 'due_date' => today()->addDays(9)]);
    }

    ClientRequest::factory()->create(['matter_id' => $this->first->id, 'assigned_to' => $this->assistant->id, 'status' => ClientRequestStatus::InProgress]);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect(Deadline::query()->where('responsible_user_id', $this->successor->id)->count())->toBe(4)
        ->and(fa2r1Bells($this->successor, MatterAssignmentAlert::class))->toBe([])
        ->and(fa2r1Bells($this->successor, DeadlineAssignedAlert::class))->toBe([])
        ->and(fa2r1Bells($this->assistant, TeamDepartureAlert::class))->toBe([]);

    Mail::assertNotSent(DeadlineAssigned::class);

    $received = fa2r1Bells($this->successor, StaffWorkTransferAlert::class);

    expect($received)->toHaveCount(1)
        ->and($received[0]['body'])->toContain('VK-2026-DS-0701')->toContain('VK-2026-DS-0702')->toContain('Trợ lý Sắp Nghỉ')
        ->toContain(__('internal_flow.staff_work.digest.deadlines', ['count' => 4]))
        ->toContain(__('internal_flow.staff_work.digest.requests', ['count' => 1]));

    Mail::assertSent(StaffWorkTransferred::class, 1);
    Mail::assertSent(StaffWorkTransferred::class, fn (StaffWorkTransferred $mail): bool => $mail->hasTo($this->successor->email));

    $handed = fa2r1Bells($this->assistant, StaffWorkTransferAlert::class);

    expect($handed)->toHaveCount(1)
        ->and($handed[0]['body'])->toContain('Trợ lý Nhận')->toContain('VK-2026-DS-0701');
});

it('counts the moved tasks in the digest — while ReassignTask still sends its own per-task notices (integ: update once fa1 is merged)', function () {
    fa2r1Task($this->assistant, $this->first);
    fa2r1Task($this->assistant, $this->second, TaskStatus::Pending);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect(fa2r1Bells($this->successor, StaffWorkTransferAlert::class)[0]['body'])
        ->toContain(__('internal_flow.staff_work.digest.tasks', ['count' => 2]));

    // integ: khi TransferStaffWork::reassignTask() truyền notifyFormerHolder: false, notifyNewHolder: false (fa1),
    // đổi ba kỳ vọng dưới đây thành 0 — lời báo tổng hợp ở trên là lời báo duy nhất.
    expect(collect(fa2r1Bells($this->successor, TaskAlert::class))->count())->toBe(2)
        ->and(collect(fa2r1Bells($this->assistant, TaskAlert::class))->count())->toBe(2);

    Mail::assertSent(TaskNotice::class, 2);
});

it('names a restricted matter in the leaver\'s digest only when they can still open it, otherwise only counts it', function () {
    $restrictedLead = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Kín']);
    $restricted = Matter::factory()->create([
        'lead_lawyer_id' => $restrictedLead->id,
        'code' => 'VK-2026-HC-0888',
        'confidentiality' => Confidentiality::Restricted,
    ]);
    $restricted->addTeamMember($this->assistant, MatterRole::Assistant);
    fa2r1Task($this->assistant, $restricted);

    $this->first->team()->detach($this->assistant->id);
    $this->second->team()->detach($this->assistant->id);

    fa2r1Transfer($this->admin, $this->assistant, $restrictedLead)->assertHasNoActionErrors();

    $handed = fa2r1Bells($this->assistant, StaffWorkTransferAlert::class);
    $received = fa2r1Bells($restrictedLead, StaffWorkTransferAlert::class);

    expect($handed)->toHaveCount(1)
        ->and(json_encode($handed[0], JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-HC-0888')
        ->and($handed[0]['body'])->toContain(__('internal_flow.staff_work.digest.matters_unnamed', ['count' => 1]))
        ->and($received[0]['body'])->toContain('VK-2026-HC-0888');
});

it('moves tasks left as "Cần giao lại" after a removal, so the leaver can then be deactivated', function () {
    $task = fa2r1Task($this->assistant, $this->first);

    // 1. Luật sư phụ trách gỡ trợ lý khỏi hồ sơ, để việc "Cần giao lại".
    $this->actingAs($this->lead, 'web');

    $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->first, 'pageClass' => ViewMatter::class])
        ->callTableAction('removeMember', $this->assistant, data: ['task_recipient_id' => 'leave'])
        ->assertHasNoTableActionErrors();

    expect(Task::query()->needsReassignment()->pluck('id')->all())->toContain($task->id);

    // 2. Nghỉ việc bị chặn: việc vẫn của trợ lý trên một hồ sơ đang mở.
    $this->actingAs($this->admin, 'web');

    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active']);

    // 3. Chuyển hàng loạt sang người chưa ở đội ngũ hồ sơ đó.
    $page = fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect($task->fresh()->assignee_id)->toBe($this->successor->id)
        ->and(collect($page->get('staffResults'))->firstWhere('matterId', $this->first->id)['success'])->toBeTrue()
        ->and(MatterUser::query()->where('matter_id', $this->first->id)->where('user_id', $this->successor->id)->sole()->role_in_matter)
        ->toBe(MatterRole::Assistant)
        ->and(OpenTaskWork::forUser($this->assistant)->isEmpty())->toBeTrue();

    // 4. Nghỉ việc qua.
    $this->livewire(EditUser::class, ['record' => $this->assistant->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->assistant->fresh()->is_active)->toBeFalse();
});

it('gives the recipient a working seat when they only watch the matter or returned their seat', function () {
    $this->first->addTeamMember($this->successor, MatterRole::Observer);
    $this->second->addTeamMember($this->successor, MatterRole::Assistant, $this->lead);
    $this->second->team()->updateExistingPivot($this->successor->id, ['declined_at' => now(), 'accepted_at' => null]);

    $onFirst = fa2r1Task($this->assistant, $this->first);
    $onSecond = fa2r1Task($this->assistant, $this->second);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect($onFirst->fresh()->assignee_id)->toBe($this->successor->id)
        ->and($onSecond->fresh()->assignee_id)->toBe($this->successor->id);

    foreach ([$this->first, $this->second] as $matter) {
        $seat = MatterUser::query()->where('matter_id', $matter->id)->where('user_id', $this->successor->id)->sole();

        expect($seat->role_in_matter)->toBe(MatterRole::Assistant)
            ->and($seat->assignmentStatus())->toBe(MatterAssignmentStatus::Pending);
    }
});

it('only hands over working seats — an observer or returned seat of the leaver is just removed', function () {
    $third = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->first->team()->updateExistingPivot($this->assistant->id, ['role_in_matter' => MatterRole::Observer->value]);
    $third->addTeamMember($this->assistant, MatterRole::Assistant, $this->lead);
    $third->team()->updateExistingPivot($this->assistant->id, ['declined_at' => now(), 'accepted_at' => null]);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    foreach ([$this->first, $third] as $matter) {
        expect($matter->team()->whereKey($this->successor->id)->exists())->toBeFalse()
            ->and($matter->team()->whereKey($this->assistant->id)->exists())->toBeFalse();
    }

    expect($this->second->team()->whereKey($this->successor->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'staff_work_transferred')->sole()->getExtraProperty('seats_moved'))->toBe(1);
});

it('lets the department head take over the leaver\'s seats, deadlines and requests themselves, and says why the tasks could not follow', function () {
    $task = fa2r1Task($this->assistant, $this->first);
    $deadline = Deadline::factory()->for($this->second)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false]);
    $request = ClientRequest::factory()->create(['matter_id' => $this->second->id, 'assigned_to' => $this->assistant->id, 'status' => ClientRequestStatus::InProgress]);

    $this->actingAs($this->head, 'web');

    $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->assertFormFieldExists('recipient_id', 'staffWorkForm', fn ($field): bool => array_key_exists($this->head->id, $field->getOptions()));

    $page = fa2r1Transfer($this->head, $this->assistant, $this->head)->assertHasNoActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($this->head->id)
        ->and($request->fresh()->assigned_to)->toBe($this->head->id)
        ->and($this->second->team()->whereKey($this->head->id)->exists())->toBeTrue()
        ->and($task->fresh()->assignee_id)->toBe($this->assistant->id);

    $first = collect($page->get('staffResults'))->firstWhere('matterId', $this->first->id);
    $second = collect($page->get('staffResults'))->firstWhere('matterId', $this->second->id);

    expect($first['success'])->toBeFalse()
        ->and($first['message'])->toContain(__('internal_flow.staff_work.results.tasks_failed', ['count' => 1, 'reason' => __('tasks.validation.self_assign')]))
        ->and($second['success'])->toBeTrue()
        // Việc không chuyển được ở lại "Cần giao lại" — không biến mất khỏi danh sách nào.
        ->and(Task::query()->needsReassignment()->pluck('id')->all())->toContain($task->id)
        // Người bấm tự nhận thì không tự báo mình.
        ->and(fa2r1Bells($this->head, StaffWorkTransferAlert::class))->toBe([]);
});

it('asks for confirmation with the counts and a mandatory reason, and logs the reason', function () {
    fa2r1Task($this->assistant, $this->first);

    $this->actingAs($this->admin, 'web');

    $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->set('staff.recipient_id', $this->successor->id)
        ->mountAction('transferStaffWork')
        ->assertMountedActionModalSee(__('internal_flow.staff_work.summary', ['held' => 1, 'assigned' => 0, 'seats' => 0, 'matters' => 2]))
        ->assertMountedActionModalSee('Trợ lý Nhận');

    $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->set('staff.recipient_id', $this->successor->id)
        ->callAction('transferStaffWork', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    $this->livewire(BulkReassign::class, ['from' => $this->assistant->id])
        ->set('staff.recipient_id', $this->successor->id)
        ->callAction('transferStaffWork', data: ['reason' => str_repeat('a', TransferStaffWork::REASON_MAX + 1)])
        ->assertHasActionErrors(['reason' => 'max']);

    expect($this->first->team()->whereKey($this->assistant->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'staff_work_transferred')->count())->toBe(0);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor, 'Nghỉ thai sản sáu tháng.')->assertHasNoActionErrors();

    expect(Activity::query()->where('event', 'staff_work_transferred')->sole()->getExtraProperty('reason'))->toBe('Nghỉ thai sản sáu tháng.');
});

it('refuses a blank reason in the action itself', function () {
    expect(fn () => app(TransferStaffWork::class)->handle($this->assistant, $this->successor, $this->admin, '   '))
        ->toThrow(ValidationException::class);

    expect($this->first->team()->whereKey($this->assistant->id)->exists())->toBeTrue();
});

it('writes no transfer log line when nothing moved', function () {
    $lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create();
    $led = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    fa2r1Task($lawyer, $led);

    fa2r1Transfer($this->admin, $lawyer, $this->head)->assertHasNoActionErrors();

    expect(Activity::query()->where('event', 'staff_work_transferred')->count())->toBe(0)
        ->and(fa2r1Bells($this->head, StaffWorkTransferAlert::class))->toBe([]);

    Mail::assertNotSent(StaffWorkTransferred::class);
});

it('reads the open matters for the "who" list once per request, however many times the form asks', function () {
    foreach (range(1, 6) as $n) {
        Matter::factory()->create(['lead_lawyer_id' => $this->lead->id])->addTeamMember($this->assistant, MatterRole::Assistant);
    }

    $this->actingAs($this->head, 'web');

    $page = $this->livewire(BulkReassign::class, ['from' => $this->assistant->id]);

    DB::enableQueryLog();
    $page->set('staff.recipient_id', $this->successor->id);
    $teamLoads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'pivot_matter_id'))->count();
    DB::disableQueryLog();

    // Một lần nạp đội ngũ cho danh sách người (eager load), không phải một lần mỗi lần ô chọn vẽ lại.
    expect($teamLoads)->toBeLessThanOrEqual(1);
});

it('does not even name a restricted matter the leaver leads to a department head who cannot manage it', function () {
    $lawyer = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->create(['name' => 'Luật sư Sắp Nghỉ']);
    $this->first->addTeamMember($lawyer, MatterRole::Associate);
    $led = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'code' => 'VK-2026-HC-0777',
        'title' => 'Vụ kín do luật sư này phụ trách',
        'confidentiality' => Confidentiality::Restricted,
    ]);

    $page = fa2r1Transfer($this->head, $lawyer, $this->successor)->assertHasNoActionErrors();

    $results = collect($page->get('staffResults'));

    expect($results->pluck('matterId')->all())->not->toContain($led->id)
        ->and(json_encode($results->all(), JSON_UNESCAPED_UNICODE))->not->toContain('VK-2026-HC-0777')->not->toContain('Vụ kín do luật sư')
        ->and($results->where('matterId', null)->pluck('message')->all())
        ->toContain(__('internal_flow.staff_work.results.unmanaged', ['count' => 1]));
});

it('seats the recipient when the leaver only watched the matter but still holds a deadline or a client request there', function () {
    $this->first->team()->updateExistingPivot($this->assistant->id, ['role_in_matter' => MatterRole::Observer->value]);
    $deadline = Deadline::factory()->for($this->first)->create(['responsible_user_id' => $this->assistant->id, 'is_completed' => false]);

    fa2r1Transfer($this->admin, $this->assistant, $this->successor)->assertHasNoActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($this->successor->id)
        ->and(MatterUser::query()->where('matter_id', $this->first->id)->where('user_id', $this->successor->id)->sole()->role_in_matter)
        ->toBe(MatterRole::Assistant)
        ->and($this->first->team()->whereKey($this->assistant->id)->exists())->toBeFalse();
});
