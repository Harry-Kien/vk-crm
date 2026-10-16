<?php

use App\Actions\Matter\AcceptMatterAssignment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Confidentiality;
use App\Enums\MatterAssignmentStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Widgets\Tasks\MatterAssignmentsAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\MyActiveTasksWidget;
use App\Filament\Admin\Widgets\Tasks\TasksAwaitingMeWidget;
use App\Filament\Admin\Widgets\Tasks\TasksIAssignedWidget;
use App\Jobs\SendMatterAssignmentNotice;
use App\Mail\Staff\MatterAssignmentNotice;
use App\Models\Matter;
use App\Models\MatterUser;
use App\Models\User;
use App\Notifications\Staff\MatterAssignmentAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn tk, Task 2 — "Nhận hồ sơ": người được THÊM vào đội ngũ một vụ (trừ người theo dõi) hay nhận bàn giao
 * làm luật sư phụ trách xác nhận "Đã nhận hồ sơ" hoặc "Trả lại" kèm lý do. Mọi thao tác qua Livewire: nút
 * "Thêm thành viên" của tab Đội ngũ, khối "Hồ sơ chờ tôi nhận" của Việc của tôi, nút trên dòng của tab.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);
    $this->otherAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Tùng']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'VK-2026-DS-0042']);
    $this->matter->addTeamMember($this->otherAssistant, MatterRole::Assistant);
});

function seatOf(Matter $matter, User $user): ?MatterUser
{
    return MatterUser::query()->where('matter_id', $matter->id)->where('user_id', $user->id)->first();
}

function teamTabFor(Matter $matter)
{
    return test()->livewire(TeamRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function addThroughTeamTab(Matter $matter, User $actor, User $member, MatterRole $role): void
{
    test()->actingAs($actor, 'web');

    teamTabFor($matter)
        ->callTableAction('addMember', data: ['role_in_matter' => $role->value, 'user_id' => $member->id])
        ->assertHasNoTableActionErrors();
}

function seatAlertsFor(User $user): array
{
    return $user->notifications()->where('type', MatterAssignmentAlert::class)->get()
        ->map(fn ($notification): array => $notification->data)
        ->all();
}

it('makes a seat added through the team tab wait for the member, and tells them with a bell', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);

    $seat = seatOf($this->matter, $this->assistant);

    expect($seat->assignmentStatus())->toBe(MatterAssignmentStatus::Pending)
        ->and($seat->assigned_by)->toBe($this->lead->id)
        ->and($seat->assigned_at)->not->toBeNull()
        ->and($seat->accepted_at)->toBeNull();

    $alerts = seatAlertsFor($this->assistant);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['title'])->toContain('VK-2026-DS-0042')
        ->and($alerts[0]['body'])->toContain('Luật sư Khang')
        ->and($alerts[0]['body'])->toContain(MatterRole::Assistant->label());

    Mail::assertNothingSent();
});

it('does not ask an observer, or someone who adds themselves, to accept', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Observer);
    addThroughTeamTab($this->matter, $this->manager, $this->manager, MatterRole::Associate);

    expect(seatOf($this->matter, $this->assistant)->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted)
        ->and(seatOf($this->matter, $this->manager)->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted)
        ->and(seatAlertsFor($this->assistant))->toBe([])
        ->and(seatAlertsFor($this->manager))->toBe([]);
});

it('treats the lead seat of a new matter and seats written without an assigner as accepted', function () {
    expect(seatOf($this->matter, $this->lead)->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted)
        ->and(seatOf($this->matter, $this->otherAssistant)->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted);
});

it('backfills every seat that existed before the feature as accepted when it was created', function () {
    $migration = require database_path('migrations/2026_10_14_100002_add_assignment_columns_to_matter_user_table.php');
    $migration->down();

    expect(Schema::hasColumn('matter_user', 'accepted_at'))->toBeFalse();

    DB::table('matter_user')->where('user_id', $this->otherAssistant->id)->update(['created_at' => '2026-03-01 09:30:00']);

    $migration->up();

    $old = DB::table('matter_user')->where('user_id', $this->otherAssistant->id)->first();

    expect($old->accepted_at)->toBe('2026-03-01 09:30:00')
        ->and($old->assigned_at)->toBe('2026-03-01 09:30:00')
        ->and($old->assigned_by)->toBeNull()
        ->and(DB::table('matter_user')->whereNull('accepted_at')->count())->toBe(0);
})->skip(
    // Trên MariaDB, câu DDL tự commit transaction của test (RefreshDatabase) và để rác lại cho test sau.
    // Vòng `down()`/`up()` thật trên MariaDB chạy bằng `tk-dev db:artisan migrate:rollback` (báo cáo Task 2).
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'Đổi lược đồ giữa test chỉ chạy trên SQLite.',
);

it('lists my pending seat in "Hồ sơ chờ tôi nhận" on my own page, right after the tasks waiting for me', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);

    $this->actingAs($this->assistant, 'web');

    expect($this->livewire(TeamMember::class, ['user' => $this->assistant->id])->instance()->getVisibleHeaderWidgets())->toBe([
        TasksAwaitingMeWidget::class,
        MatterAssignmentsAwaitingMeWidget::class,
        MyActiveTasksWidget::class,
        TasksIAssignedWidget::class,
    ]);

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->assertCanSeeTableRecords([seatOf($this->matter, $this->assistant)])
        ->assertSee('VK-2026-DS-0042')
        ->assertSee('Luật sư Khang');

    // Người không có hồ sơ nào chờ không thấy thêm một khối trống.
    $this->actingAs($this->otherAssistant, 'web');

    expect(MatterAssignmentsAwaitingMeWidget::canView())->toBeFalse()
        ->and($this->livewire(TeamMember::class, ['user' => $this->otherAssistant->id])->instance()->getVisibleHeaderWidgets())
        ->not->toContain(MatterAssignmentsAwaitingMeWidget::class);
});

it('accepts with one click from the block, tells the assigner with a bell, and does nothing on a second click', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);
    $seat = seatOf($this->matter, $this->assistant);

    $this->actingAs($this->assistant, 'web');

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->callTableAction('acceptSeat', $seat)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('tasks.seat.actions.accept_success'));

    expect($seat->fresh()->assignmentStatus())->toBe(MatterAssignmentStatus::Accepted);

    $alerts = seatAlertsFor($this->lead);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['body'])->toContain('Trợ lý Lan')
        ->and($alerts[0]['title'])->toContain('VK-2026-DS-0042');

    // Lần bấm thứ hai (tab cũ còn hiện nút): nút đã ẩn trên màn hình mới; Action không ghi thêm gì, không
    // báo lần hai, không ném lỗi.
    teamTabFor($this->matter)->assertTableActionHidden('acceptSeat', $this->assistant);
    app(AcceptMatterAssignment::class)->handle($this->matter, $this->assistant);

    expect(Activity::query()->where('event', 'matter_assignment_accepted')->count())->toBe(1)
        ->and(seatAlertsFor($this->lead))->toHaveCount(1);
});

it('declines with a required reason, keeps the member on the matter, and tells assigner, lead and manager by bell and mail', function () {
    $otherManager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Phúc']);
    addThroughTeamTab($this->matter, $this->manager, $this->associate, MatterRole::Associate);
    $seat = seatOf($this->matter, $this->associate);

    $this->actingAs($this->associate, 'web');

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->callTableAction('declineSeat', $seat, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($seat->fresh()->assignmentStatus())->toBe(MatterAssignmentStatus::Pending);

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->callTableAction('declineSeat', $seat, data: ['reason' => 'Xung đột lợi ích với bên bị đơn.'])
        ->assertHasNoTableActionErrors();

    $fresh = $seat->fresh();

    expect($fresh->assignmentStatus())->toBe(MatterAssignmentStatus::Declined)
        ->and($fresh->decline_reason)->toBe('Xung đột lợi ích với bên bị đơn.')
        // Không tự gỡ: vẫn ngồi ghế cũ.
        ->and($this->matter->team()->whereKey($this->associate->id)->exists())->toBeTrue();

    foreach ([$this->manager, $this->lead, $otherManager] as $told) {
        $alerts = seatAlertsFor($told);

        expect($alerts)->toHaveCount(1)
            ->and($alerts[0]['body'])->toContain('Xung đột lợi ích với bên bị đơn.');

        Mail::assertSent(MatterAssignmentNotice::class, fn (MatterAssignmentNotice $mail): bool => $mail->hasTo($told->email)
            && $mail->kind === MatterAssignmentNotice::KIND_DECLINED);
    }

    Mail::assertSent(MatterAssignmentNotice::class, 3);

    // Người trả lại không tự báo mình: chỉ còn chuông "được giao" của lúc đầu.
    expect(collect(seatAlertsFor($this->associate))->pluck('title')->all())
        ->toBe([__('tasks.seat.alert.assigned.title', ['code' => 'VK-2026-DS-0042'])]);
});

it('caps the decline reason at the column length', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);

    $this->actingAs($this->assistant, 'web');

    $this->livewire(MatterAssignmentsAwaitingMeWidget::class)
        ->callTableAction('declineSeat', seatOf($this->matter, $this->assistant), data: ['reason' => str_repeat('a', MatterUser::DECLINE_REASON_MAX_LENGTH + 1)])
        ->assertHasTableActionErrors(['reason' => 'max']);
});

it('refuses to accept a seat I already handed back, saying why', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);

    $this->actingAs($this->assistant, 'web');
    $tab = teamTabFor($this->matter);

    $tab->callTableAction('declineSeat', $this->assistant, data: ['reason' => 'Đang quá tải.'])->assertHasNoTableActionErrors();

    // Nút đã ẩn; một payload dàn dựng vẫn bị Action từ chối với câu nói tình trạng hiện tại.
    $tab->assertTableActionHidden('acceptSeat', $this->assistant);

    expect(fn () => app(AcceptMatterAssignment::class)->handle($this->matter, $this->assistant))
        ->toThrow(DomainException::class, MatterAssignmentStatus::Declined->label());
});

it('shows on the team tab who has not accepted and who handed back, with the reason only to those who manage the team', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);
    addThroughTeamTab($this->matter, $this->lead, $this->associate, MatterRole::Associate);

    $this->actingAs($this->associate, 'web');
    teamTabFor($this->matter)->callTableAction('declineSeat', $this->associate, data: ['reason' => 'Lý do riêng tư XYZ'])->assertHasNoTableActionErrors();

    $this->actingAs($this->lead, 'web');
    teamTabFor($this->matter)
        ->assertSee(MatterAssignmentStatus::Pending->label())
        ->assertSee(MatterAssignmentStatus::Declined->label())
        ->assertSee(MatterAssignmentStatus::Accepted->label())
        ->assertSee('Lý do riêng tư XYZ')
        // Luật sư phụ trách không thấy nút nhận/trả lại trên dòng của người khác.
        ->assertTableActionHidden('acceptSeat', $this->assistant)
        ->assertTableActionHidden('declineSeat', $this->assistant);

    $this->actingAs($this->otherAssistant, 'web');
    teamTabFor($this->matter)
        ->assertSee(MatterAssignmentStatus::Declined->label())
        ->assertSee(__('tasks.seat.declined_note_short'))
        ->assertDontSee('Lý do riêng tư XYZ');

    // Chính người đang chờ thấy hai nút trên dòng của mình.
    $this->actingAs($this->assistant, 'web');
    teamTabFor($this->matter)
        ->assertTableActionVisible('acceptSeat', $this->assistant)
        ->assertTableActionVisible('declineSeat', $this->assistant)
        ->assertTableActionHidden('acceptSeat', $this->otherAssistant);
});

it('refuses, with the one generic sentence, a member removed meanwhile, someone outside the team, and a deactivated account', function () {
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);
    addThroughTeamTab($this->matter, $this->lead, $this->associate, MatterRole::Associate);

    // Gỡ giữa lúc khối còn mở (lượt đua với "Gỡ thành viên" — Action hỏi lại ghế dưới khoá).
    $this->matter->team()->detach($this->assistant->id);
    $this->associate->forceFill(['is_active' => false])->save();

    foreach ([$this->assistant, $this->manager, $this->associate] as $actor) {
        expect(fn () => app(AcceptMatterAssignment::class)->handle($this->matter, $actor))
            ->toThrow(AuthorizationException::class, __('tasks.seat.errors.unavailable'));
    }

    expect(Activity::query()->where('event', 'matter_assignment_accepted')->exists())->toBeFalse();
});

it('drops the bell when the member can no longer open the matter by the time the job runs', function () {
    $this->matter->forceFill(['confidentiality' => Confidentiality::Normal])->save();
    addThroughTeamTab($this->matter, $this->lead, $this->assistant, MatterRole::Assistant);

    $this->actingAs($this->assistant, 'web');
    teamTabFor($this->matter)->callTableAction('declineSeat', $this->assistant, data: ['reason' => 'Đang quá tải.']);

    // Vụ siết hạn chế giữa lúc xếp và lúc gửi: chạy lại job của lời báo "trả lại" cho một quản lý.
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    $event = Activity::query()->where('event', 'matter_assignment_declined')->latest('id')->first();
    $this->manager->notifications()->delete();

    (new SendMatterAssignmentNotice($this->matter->id, $this->assistant->id, MatterAssignmentAlert::DECLINED, $this->manager->id, $event->id))
        ->handle(app(ResolveStaffRecipients::class));

    expect(seatAlertsFor($this->manager))->toBe([]);
});
