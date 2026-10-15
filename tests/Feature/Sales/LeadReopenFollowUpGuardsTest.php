<?php

use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Enums\FollowUpChannel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeRedPending;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fi, B1–B3 — ba chỗ hở nhỏ của việc chăm sóc lead (làn kd) và mở lại lead (làn fa):
 *  B1. Mở lại một lead đã đóng xoá hẹn liên hệ lại đã qua (không hiện "quá hạn chăm sóc" ngay); hẹn còn ở
 *      tương lai thì giữ.
 *  B2. "Ghi lần chăm sóc" từ chối thời gian liên hệ trước lúc văn phòng nhận lead, lỗi trên đúng ô.
 *  B3. Mở lại một lead bị từ chối vì xung đột đưa nó về "Đỏ chờ" VÀ báo trưởng phòng/quản trị như đường
 *      thường (`IntakeRedPending`, sau commit, chỉ mã bản ghi).
 * Qua trang sửa lead. Hàm toàn cục tiền tố `lrg…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-10-15 09:00:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->otherManager = User::factory()->withRole(Role::Manager)->create();
    $this->sales = User::factory()->withRole(Role::Sales)->create();
});

afterEach(fn () => Carbon::setTestNow());

function lrgRecord(User $actor): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [
        'contact_name' => 'Người Gọi Mẫu', 'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ], [['name' => 'Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977000111']])->intake;
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

function lrgEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

function lrgLog(): TestAction
{
    return TestAction::make('logFollowUp')->schemaComponent('followUpActions');
}

function lrgReopen(IntakeRequest $intake): void
{
    test()->actingAs(test()->manager, 'web');

    lrgEdit($intake->fresh())
        ->callAction('reopen', data: ['reopen_reason' => 'Khách gọi lại, muốn tiếp tục'])
        ->assertHasNoActionErrors();
}

it('clears a follow-up appointment that already passed when a lost lead is reopened', function () {
    $intake = lrgRecord($this->manager);
    $intake->forceFill(['status' => IntakeStatus::Lost, 'next_follow_up_at' => '2026-10-10 09:00:00'])->save();

    lrgReopen($intake);

    expect($intake->fresh()->status)->toBe(IntakeStatus::Contacted)
        ->and($intake->fresh()->next_follow_up_at)->toBeNull();
});

it('keeps a follow-up appointment still ahead when a lead is reopened', function () {
    $intake = lrgRecord($this->manager);
    $intake->forceFill(['status' => IntakeStatus::Lost, 'next_follow_up_at' => '2026-10-20 09:00:00'])->save();

    lrgReopen($intake);

    expect($intake->fresh()->next_follow_up_at->toDateTimeString())->toBe('2026-10-20 09:00:00');
});

it('refuses a follow-up dated before the office received the lead, on the time field', function () {
    $intake = lrgRecord($this->manager);
    $intake->forceFill(['assigned_to' => $this->sales->id, 'status' => IntakeStatus::Contacted, 'received_at' => '2026-10-12 14:30:40'])->save();

    $this->actingAs($this->sales, 'web');

    $component = lrgEdit($intake)
        ->callAction(lrgLog(), [
            'channel' => FollowUpChannel::Call->value,
            'occurred_at' => '2026-10-12 14:00:00',
            'note' => 'Gọi lại khách',
        ])
        ->assertHasActionErrors(['occurred_at']);

    expect(collect($component->errors()->toArray())->flatten()->all())
        ->toContain(__('leads.follow_ups.errors.occurred_before_received', ['at' => '12/10/2026 14:30']))
        ->and(IntakeFollowUp::query()->where('intake_request_id', $intake->id)->count())->toBe(0);

    // Cùng phút nhận lead (ô thời gian không có giây) thì nhận.
    lrgEdit($intake)
        ->callAction(lrgLog(), [
            'channel' => FollowUpChannel::Call->value,
            'occurred_at' => '2026-10-12 14:30:00',
            'note' => 'Gọi lại khách ngay khi nhận',
        ])
        ->assertHasNoActionErrors();

    expect(IntakeFollowUp::query()->where('intake_request_id', $intake->id)->count())->toBe(1);
});

it('still refuses a follow-up in the future, on the time field', function () {
    $intake = lrgRecord($this->manager);
    $intake->forceFill(['assigned_to' => $this->sales->id, 'status' => IntakeStatus::Contacted])->save();

    $this->actingAs($this->sales, 'web');

    lrgEdit($intake)
        ->callAction(lrgLog(), [
            'channel' => FollowUpChannel::Call->value,
            'occurred_at' => '2026-10-15 10:00:00',
            'note' => 'Gọi lại khách',
        ])
        ->assertHasActionErrors(['occurred_at']);
});

it('tells the managers when reopening a conflict decline puts the lead back to a pending red', function () {
    $intake = lrgRecord($this->manager);
    app(DeclineIntake::class)->handle($this->manager, $intake, 'Bên kia là khách hiện hữu', true);

    expect($intake->fresh()->conflict_red_pending_since)->toBeNull();

    lrgReopen($intake);

    $notification = $this->otherManager->fresh()->notifications()->sole();

    expect($intake->fresh()->hasUnresolvedRed())->toBeTrue()
        ->and($notification->type)->toBe(IntakeRedPending::class)
        ->and($notification->data['body'])->toContain($intake->code)
        ->and($notification->data['body'])->not->toContain('Người Gọi Mẫu');
});

it('does not tell the managers when reopening an ordinary decline or a lost lead', function () {
    $declined = lrgRecord($this->manager);
    app(DeclineIntake::class)->handle($this->manager, $declined, 'Ngoài lĩnh vực văn phòng nhận', false);

    lrgReopen($declined);

    expect($this->otherManager->fresh()->notifications()->count())->toBe(0);
});

it('does not tell the managers again when the conflict decline already carried a pending red', function () {
    $intake = lrgRecord($this->manager);
    app(DeclineIntake::class)->handle($this->manager, $intake, 'Bên kia là khách hiện hữu', true);
    $intake->forceFill(['conflict_red_pending_since' => '2026-10-14 08:00:00'])->save();

    lrgReopen($intake);

    expect($intake->fresh()->conflict_red_pending_since->toDateTimeString())->toBe('2026-10-14 08:00:00')
        ->and($this->otherManager->fresh()->notifications()->count())->toBe(0);
});

/*
 * Làn fi, vòng sửa 1 (I2): lead đã đóng không nằm trong `OpenLeads`, nên luật sư tư vấn của nó có thể đã nghỉ
 * hay đổi vai trong lúc lead đóng. Mở lại thì bỏ luật sư tư vấn không còn nhận được lead
 * (`HandIntakeToLawyer::canReceive()`): lead về tay người phụ trách, không kẹt ở người không chuyển đổi được.
 */
it('drops a consulting lawyer who can no longer receive leads when the lead is reopened', function (string $who) {
    $gone = match ($who) {
        'deactivated' => User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]),
        'moved to sales' => User::factory()->withRole(Role::Sales)->create(),
    };
    $intake = lrgRecord($this->manager);
    $intake->forceFill([
        'status' => IntakeStatus::Lost,
        'assigned_to' => $this->sales->id,
        'consulting_lawyer_id' => $gone->id,
        'handed_to_lawyer_at' => '2026-10-11 16:00:00',
    ])->save();

    lrgReopen($intake);

    $fresh = $intake->fresh();
    $audit = Activity::query()->where('event', 'intake_reopened')->where('subject_id', $intake->id)->sole();

    expect($fresh->status)->toBe(IntakeStatus::Contacted)
        ->and($fresh->consulting_lawyer_id)->toBeNull()
        ->and($fresh->handed_to_lawyer_at)->toBeNull()
        ->and($audit->properties['consulting_lawyer_dropped'])->toBe($gone->id)
        ->and($fresh->isVisibleTo($gone->fresh()))->toBeFalse();
})->with(['deactivated', 'moved to sales']);

it('keeps a consulting lawyer who can still receive leads when the lead is reopened', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake = lrgRecord($this->manager);
    $intake->forceFill(['status' => IntakeStatus::Lost, 'consulting_lawyer_id' => $lawyer->id, 'handed_to_lawyer_at' => '2026-10-11 16:00:00'])->save();

    lrgReopen($intake);

    $audit = Activity::query()->where('event', 'intake_reopened')->where('subject_id', $intake->id)->sole();

    expect($intake->fresh()->consulting_lawyer_id)->toBe($lawyer->id)
        ->and($audit->properties->has('consulting_lawyer_dropped'))->toBeFalse();
});
