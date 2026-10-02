<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 3 — các hành động của màn hình tiếp nhận, qua Livewire: cổng ô câu chuyện (R1, R7a) với
 * lý do khoá bằng lời, gửi thẳng `summary` khi Đỏ, xác nhận Vàng, xử lý Đỏ (quản lý/admin), kiểm tra
 * lại, sửa phần danh tính, đổi trạng thái, từ chối (R8), gộp (R4) — kể cả gộp không phải đường rửa
 * Đỏ thứ hai (phán quyết controller, fix vòng 1 của Task 2).
 *
 * Hàm toàn cục mang tiền tố `ira…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function iraStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Một khách hàng hiện hữu của văn phòng, mang số 0912000111, là nguyên đơn trong một vụ. */
function iraExistingClient(): Client
{
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    return $client;
}

function iraRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** Đỏ: bên đối lập mang SĐT của khách hiện hữu, ở vai đối lập với người liên hệ nguyên đơn. */
function iraRedIntake(User $actor, array $overrides = []): IntakeRequest
{
    iraExistingClient();

    $intake = iraRecord($actor, $overrides, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

/** Vàng theo tên: bên đối lập chỉ có tên, trùng tên một bên của một vụ đã có. */
function iraYellowIntake(User $actor): IntakeRequest
{
    MatterParty::factory()->for(Matter::factory()->create())->create(['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Related]);

    $intake = iraRecord($actor, [], [['name' => 'Phạm Văn Vàng', 'role' => PartyRole::Defendant]]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

function iraEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

function iraSaveSummary(): TestAction
{
    return TestAction::make('saveSummary')->schemaComponent('storyActions');
}

it('keeps the story of a red call locked, says why in words, and saves nothing even when the request is forged', function () {
    $assistant = iraStaff();
    $intake = iraRedIntake($assistant);

    expect($intake->conflict_level)->toBe(ConflictLevel::Red);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('intake.gate.locked'))
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertSee(__('intake.gate.hint_conflict_red'))
        ->assertFormFieldIsDisabled('summary')
        ->set('data.summary', 'Bí mật nghe lén qua request sửa tay')
        ->callAction(iraSaveSummary())
        ->assertHasErrors(['data.summary']);

    iraEdit($intake)
        ->set('data.summary', 'Bí mật nghe lén qua nút lưu danh tính')
        ->call('save');

    expect($intake->fresh()->summary)->toBeNull();
});

it('hides the red override from an assistant and a lawyer, and lets a manager open the story with a reason', function () {
    $assistant = iraStaff();
    $intake = iraRedIntake($assistant);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)->assertActionDoesNotExist(TestAction::make('resolveRed')->schemaComponent('checkActions'));

    $lawyer = iraStaff(Role::Lawyer);
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();
    $this->actingAs($lawyer, 'web');
    iraEdit($intake)->assertActionDoesNotExist(TestAction::make('resolveRed')->schemaComponent('checkActions'));

    $manager = iraStaff(Role::Manager);
    $this->actingAs($manager, 'web');
    iraEdit($intake)
        ->assertActionVisible(TestAction::make('resolveRed')->schemaComponent('checkActions'))
        ->mountAction(TestAction::make('resolveRed')->schemaComponent('checkActions'))
        ->assertMountedActionModalDontSee(__('intake.gate.red_was_shown'));

    iraEdit($intake)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => ''])
        ->assertHasActionErrors(['override_reason']);

    expect($intake->fresh()->hasUnresolvedRed())->toBeTrue();

    iraEdit($intake)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã gọi khách hiện hữu, không cùng việc'])
        ->assertHasNoErrors()
        ->assertSee(__('intake.gate.open'))
        ->assertFormFieldIsEnabled('summary');

    expect($intake->fresh()->hasUnresolvedRed())->toBeFalse()
        ->and($intake->fresh()->conflict_overridden_by)->toBe($manager->id);
});

it('stops calling the red result "locked" in the check panel once a manager has overridden it', function () {
    $assistant = iraStaff();
    $intake = iraRedIntake($assistant);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('intake.check.heading_red'))
        ->assertDontSee(__('intake.check.heading_red_overridden'));

    $this->actingAs(iraStaff(Role::Manager), 'web');
    iraEdit($intake)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã gọi khách hiện hữu, không cùng việc'])
        ->assertHasNoErrors();

    expect($intake->fresh()->conflict_level)->toBe(ConflictLevel::Red);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('intake.gate.open'))
        ->assertSee(__('intake.check.heading_red_overridden'))
        ->assertDontSee(__('intake.check.heading_red'))
        ->assertDontSee('Đã gọi khách hiện hữu, không cùng việc');
});

it('lets a manager resolve a sticky red after the re-run came out green, and says the record was red before', function () {
    $assistant = iraStaff();
    $intake = iraRedIntake($assistant);

    $intake->parties()->delete();
    app(RerunIntakeConflictCheck::class)->handle($assistant, $intake->fresh());

    expect($intake->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->fresh()->hasUnresolvedRed())->toBeTrue();

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertFormFieldIsDisabled('summary');

    $this->actingAs(iraStaff(Role::Manager), 'web');
    iraEdit($intake)
        ->mountAction(TestAction::make('resolveRed')->schemaComponent('checkActions'))
        ->assertMountedActionModalSee(__('intake.gate.red_was_shown'));
});

it('lets the assistant acknowledge a yellow match by name, which opens the story', function () {
    $assistant = iraStaff();
    $intake = iraYellowIntake($assistant);

    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('enums.intake_summary_blocker.conflict_acknowledgement'))
        ->assertSee('Phạm Văn Vàng')
        ->assertFormFieldIsDisabled('summary')
        ->callAction(TestAction::make('acknowledge')->schemaComponent('checkActions'))
        ->assertHasNoErrors()
        ->assertSee(__('intake.gate.open'))
        ->assertFormFieldIsEnabled('summary');

    expect($intake->fresh()->conflict_acknowledged_by)->toBe($assistant->id);
});

it('does not offer the yellow acknowledgement while a red is pending, nor when nothing needs one', function () {
    $assistant = iraStaff();

    $red = iraRedIntake($assistant);
    $this->actingAs($assistant, 'web');
    iraEdit($red)->assertActionDoesNotExist(TestAction::make('acknowledge')->schemaComponent('checkActions'));

    $green = iraRecord($assistant, ['contact_name' => 'Người Khác Hẳn', 'contact_phone' => '0901000009'], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977000999']]);
    iraEdit($green)->assertActionDoesNotExist(TestAction::make('acknowledge')->schemaComponent('checkActions'));
});

it('records the privacy notice only when the box in the dialog is ticked', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('intake.privacy_notice.text'))
        ->callAction(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'), data: ['privacy_notice' => false])
        ->assertHasActionErrors(['privacy_notice']);

    expect($intake->fresh()->privacy_notice_acknowledged_at)->toBeNull();

    iraEdit($intake)
        ->callAction(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'), data: ['privacy_notice' => true])
        ->assertHasNoErrors();

    expect($intake->fresh()->privacy_notice_acknowledged_at)->not->toBeNull()
        ->and($intake->fresh()->privacy_notice_recorded_by)->toBe($assistant->id);

    iraEdit($intake->fresh())->assertActionDoesNotExist(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'));
});

it('re-runs the check from the screen and writes one more conflict_check_run row for the record', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);
    $runs = fn (): int => Activity::query()->where('event', 'conflict_check_run')
        ->where('subject_type', 'intake_request')->where('subject_id', $intake->id)->count();

    expect($runs())->toBe(1);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->callAction(TestAction::make('rerun')->schemaComponent('checkActions'))
        ->assertHasNoErrors();

    expect($runs())->toBe(2);
});

it('saves an identity edit through the action: re-checks, normalises, keeps stored identifiers left blank', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant, ['contact_id_number' => '079123456789'], [
        ['name' => 'Bị Đơn Một', 'role' => PartyRole::Defendant, 'phone' => '0977000111', 'id_number' => '001099000111'],
        ['name' => 'Bị Đơn Gõ Nhầm', 'role' => PartyRole::Defendant],
    ]);
    $keep = $intake->parties()->where('name', 'Bị Đơn Một')->sole();
    $runsBefore = Activity::query()->where('event', 'conflict_check_run')->count();

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertFormSet(['contact_id_number' => null])
        ->fillForm([
            'contact_phone' => '+84 901 222 333',
            'parties' => [
                ['id' => $keep->id, 'role' => PartyRole::Defendant->value, 'name' => 'Bị Đơn Một', 'phone' => null, 'id_number' => null],
                ['id' => null, 'role' => PartyRole::Defendant->value, 'name' => 'Bị Đơn Mới', 'phone' => '0966111222', 'id_number' => null],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $intake->fresh();

    expect($fresh->contact_phone)->toBe('+84 901 222 333')
        ->and($fresh->contact_phone_normalized)->toBe('84901222333')
        ->and($fresh->contact_id_number_hash)->toBe(Normalizer::idNumberHash('079123456789'))
        ->and($fresh->parties()->orderBy('id')->pluck('name')->all())->toBe(['Bị Đơn Một', 'Bị Đơn Mới'])
        ->and($keep->fresh()->phone_normalized)->toBe('84977000111')
        ->and($keep->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('001099000111'))
        ->and(Activity::query()->where('event', 'conflict_check_run')->count())->toBe($runsBefore + 1)
        ->and($fresh->conflict_result['fingerprint'])->toBe($fresh->identityFingerprint());
});

it('refuses an opposing-party row id that belongs to another record, and changes nothing', function () {
    $assistant = iraStaff();
    $mine = iraRecord($assistant, [], [['name' => 'Bên Của Tôi', 'role' => PartyRole::Defendant]]);
    $other = iraRecord(iraStaff(), ['contact_phone' => '0901555666'], [['name' => 'Bên Của Người Khác', 'role' => PartyRole::Defendant]]);
    $foreign = $other->parties()->sole();

    $this->actingAs($assistant, 'web');
    iraEdit($mine)
        ->fillForm(['parties' => [
            ['id' => $foreign->id, 'role' => PartyRole::Defendant->value, 'name' => 'Đổi Tên Hộ', 'phone' => null, 'id_number' => null],
        ]])
        ->call('save')
        ->assertHasFormErrors(['parties']);

    expect($foreign->fresh()->name)->toBe('Bên Của Người Khác')
        ->and($foreign->fresh()->intake_request_id)->toBe($other->id)
        ->and($mine->parties()->pluck('name')->all())->toBe(['Bên Của Tôi']);
});

it('changes the status only to the steps a person may set, never to won, merged or declined', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    $component = iraEdit($intake)->mountAction('changeStatus');
    $options = $component->instance()->getSchema('mountedActionSchema0')->getComponent('status')->getOptions();

    expect(array_keys($options))->toBe([
        IntakeStatus::Contacted->value, IntakeStatus::Consulting->value, IntakeStatus::Quoted->value, IntakeStatus::Lost->value,
    ]);

    iraEdit($intake)
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Won->value])
        ->assertHasActionErrors(['status']);

    expect($intake->fresh()->status)->toBe(IntakeStatus::New);

    iraEdit($intake)->callAction('changeStatus', data: ['status' => IntakeStatus::Quoted->value])->assertHasNoErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Quoted);

    $options = iraEdit($intake->fresh())->mountAction('changeStatus')->instance()
        ->getSchema('mountedActionSchema0')->getComponent('status')->getOptions();

    expect(array_keys($options))->toBe([IntakeStatus::Contacted->value, IntakeStatus::Consulting->value, IntakeStatus::Lost->value]);
});

it('shows the conflict-decline switch only to a manager or admin', function () {
    $lawyer = iraStaff(Role::Lawyer);
    $intake = iraRecord($lawyer);
    $switchHidden = fn (): bool => (bool) iraEdit($intake)->mountAction('decline')->instance()
        ->getSchema('mountedActionSchema0')->getComponent('decline_for_conflict', withHidden: true)?->isHidden();

    $this->actingAs($lawyer, 'web');
    expect($switchHidden())->toBeTrue();

    $this->actingAs(iraStaff(Role::Manager), 'web');
    expect($switchHidden())->toBeFalse();
});

it('lets a lawyer decline for an ordinary reason, and a forged conflict flag is not taken from the lawyer', function () {
    $lawyer = iraStaff(Role::Lawyer);
    $intake = iraRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    iraEdit($intake)
        ->callAction('decline', data: ['decline_reason' => 'Ngoài lĩnh vực', 'decline_for_conflict' => true])
        ->assertHasNoErrors();

    expect($intake->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($intake->fresh()->decline_reason)->toBe('Ngoài lĩnh vực')
        ->and($intake->fresh()->decline_reason_is_conflict)->toBeFalse();
});

it('lets a manager decline a red call for a conflict, keeps the red pending, and locks the story of the record', function () {
    $assistant = iraStaff();
    $intake = iraRedIntake($assistant);

    $this->actingAs(iraStaff(Role::Manager), 'web');
    iraEdit($intake)
        ->callAction('decline', data: ['decline_reason' => 'Bên kia là khách hiện hữu', 'decline_for_conflict' => true])
        ->assertHasNoErrors();

    $fresh = $intake->fresh();

    expect($fresh->status)->toBe(IntakeStatus::Declined)
        ->and($fresh->decline_reason_is_conflict)->toBeTrue()
        ->and($fresh->conflict_red_pending_since)->not->toBeNull();

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(__('enums.intake_summary_blocker.declined'))
        ->assertDontSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertFormFieldIsDisabled('summary')
        ->assertActionHidden('decline')
        ->assertActionHidden('changeStatus');
});

it('asks for a reason before declining', function () {
    $lawyer = iraStaff(Role::Lawyer);
    $intake = iraRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    iraEdit($intake)
        ->callAction('decline', data: ['decline_reason' => '   '])
        ->assertHasActionErrors(['decline_reason']);

    expect($intake->fresh()->status)->toBe(IntakeStatus::New);
});

it('does not let an assistant launder a red by merging it into a green record of the same person', function () {
    $assistant = iraStaff();
    $red = iraRedIntake($assistant);
    $green = iraRecord($assistant, ['contact_phone' => '0901777888']);
    app(RecordPrivacyNotice::class)->handle($assistant, $green, true);

    $this->actingAs($assistant, 'web');
    iraEdit($red)
        ->callAction('merge', data: ['merge_target' => $green->id])
        ->assertHasNoErrors();

    $red = $red->fresh();
    $green = $green->fresh();

    expect($red->status)->toBe(IntakeStatus::Merged)
        ->and($red->merged_into_id)->toBe($green->id)
        ->and($green->parties()->pluck('name')->all())->toBe(['Bị Đơn Là Khách'])
        ->and($green->conflict_red_pending_since)->not->toBeNull()
        ->and($green->hasUnresolvedRed())->toBeTrue();

    iraEdit($green)
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertFormFieldIsDisabled('summary')
        ->set('data.summary', 'Thử ghi sau khi gộp')
        ->callAction(iraSaveSummary())
        ->assertHasErrors(['data.summary']);

    expect($green->fresh()->summary)->toBeNull();
});

it('offers as merge targets only open records the user can see, other than this one', function () {
    $assistant = iraStaff();
    $source = iraRecord($assistant, ['contact_phone' => '0901000001']);
    $visible = iraRecord($assistant, ['contact_phone' => '0901000002']);
    $hidden = iraRecord(iraStaff(), ['contact_phone' => '0901000003']);
    $merged = iraRecord($assistant, ['contact_phone' => '0901000004']);
    $merged->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => $visible->id])->save();

    $this->actingAs($assistant, 'web');
    $component = iraEdit($source)->mountAction('merge');
    $options = $component->instance()->getSchema('mountedActionSchema0')->getComponent('merge_target')->getOptions();

    expect(array_keys($options))->toBe([$visible->id]);

    iraEdit($source)
        ->callAction('merge', data: ['merge_target' => $hidden->id])
        ->assertHasActionErrors(['merge_target']);

    expect($source->fresh()->status)->toBe(IntakeStatus::New)
        ->and($hidden->fresh()->parties()->count())->toBe(0);
});

it('shows a merged record read-only, with a link to where it went and no action left', function () {
    $assistant = iraStaff();
    $source = iraRecord($assistant, ['contact_phone' => '0901000001']);
    $target = iraRecord($assistant, ['contact_phone' => '0901000002']);

    $this->actingAs($assistant, 'web');
    iraEdit($source)->callAction('merge', data: ['merge_target' => $target->id])->assertHasNoErrors();

    iraEdit($source->fresh())
        ->assertSee(__('intake.decision.merged_into', ['code' => $target->code]))
        ->assertFormFieldIsDisabled('contact_name')
        ->assertFormFieldIsDisabled('summary')
        ->assertActionHidden('changeStatus')
        ->assertActionHidden('decline')
        ->assertActionHidden('merge')
        ->assertActionDoesNotExist(TestAction::make('rerun')->schemaComponent('checkActions'))
        ->assertActionDoesNotExist(iraSaveSummary());
});

it('moves the opposing parties of the merged record without duplicating one both records named', function () {
    $assistant = iraStaff();
    $source = iraRecord($assistant, ['contact_phone' => '0901000001'], [
        ['name' => 'Chung Một Người', 'role' => PartyRole::Defendant, 'phone' => '0966000111'],
        ['name' => 'Chỉ Ở Bản Nguồn', 'role' => PartyRole::Defendant],
    ]);
    $target = iraRecord($assistant, ['contact_phone' => '0901000002'], [
        ['name' => 'Chung Một Người', 'role' => PartyRole::Defendant, 'phone' => '0966 000 111'],
    ]);

    $this->actingAs($assistant, 'web');
    iraEdit($source)->callAction('merge', data: ['merge_target' => $target->id])->assertHasNoErrors();

    expect($target->parties()->pluck('name')->all())->toEqualCanonicalizing(['Chỉ Ở Bản Nguồn', 'Chung Một Người'])
        ->and(IntakeParty::query()->where('intake_request_id', $source->id)->count())->toBe(0)
        ->and($target->fresh()->conflict_result['fingerprint'])->toBe($target->fresh()->identityFingerprint());
});

it('answers a merge that would pass the cap of opposing parties with an error on the target field, and moves nothing', function () {
    $assistant = iraStaff();
    $named = fn (string $prefix, int $count): array => array_map(fn (int $i): array => ['name' => "{$prefix} {$i}", 'role' => PartyRole::Defendant], range(1, $count));
    $source = iraRecord($assistant, ['contact_phone' => '0901000001'], $named('Bên Nguồn', 6));
    $target = iraRecord($assistant, ['contact_phone' => '0901000002'], $named('Bên Đích', 5));

    $this->actingAs($assistant, 'web');
    $page = iraEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasActionErrors(['merge_target']);

    expect($source->fresh()->status)->toBe(IntakeStatus::New)
        ->and($target->parties()->count())->toBe(5)
        ->and($page->instance()->getSchema('form')->getFlatFields(withHidden: true)['parties']->getMaxItems())
        ->toBe(IntakeRequest::MAX_OPPOSING_PARTIES);
});

it('says in words when a match was found through an opposing party the same caller named on an earlier call', function () {
    $assistantA = iraStaff();
    $assistantB = iraStaff();
    $first = iraRedIntake($assistantA);
    $callback = iraRecord($assistantB, ['contact_name' => 'Người Gọi Mẫu Lần Hai']);

    expect(collect($callback->conflict_result['matches'])->pluck('our_party_name')->all())->toContain('Bị Đơn Là Khách');

    $this->actingAs($assistantB, 'web');
    iraEdit($callback)->assertSee(__('intake.check.carried_note'));

    $this->actingAs($assistantA, 'web');
    iraEdit($first)->assertDontSee(__('intake.check.carried_note'));
});

it('offers the privacy notice again when the recorded version is not the current one', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);
    $intake->forceFill(['privacy_notice_version' => '2025-cu', 'privacy_notice_acknowledged_at' => now()])->save();

    $this->actingAs($assistant, 'web');
    iraEdit($intake)->assertActionVisible(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'));
});

it('does not offer the red override on a record that is not red, nor on one declined for a conflict', function () {
    $manager = iraStaff(Role::Manager);
    $green = iraRecord($manager, ['contact_name' => 'Người Khác Hẳn', 'contact_phone' => '0901000009']);

    $this->actingAs($manager, 'web');
    iraEdit($green)->assertActionDoesNotExist(TestAction::make('resolveRed')->schemaComponent('checkActions'));

    $red = iraRedIntake($manager);
    iraEdit($red)->callAction('decline', data: ['decline_reason' => 'Xung đột', 'decline_for_conflict' => true])->assertHasNoErrors();

    expect($red->fresh()->hasUnresolvedRed())->toBeTrue();

    iraEdit($red)->assertActionDoesNotExist(TestAction::make('resolveRed')->schemaComponent('checkActions'));
});

it('keeps a story typed but not yet saved when the identity part is saved', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant, ['contact_name' => 'Người Khác Hẳn'], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977000999']]);
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->set('data.summary', 'Đang gõ dở, chưa lưu')
        ->fillForm(['referred_by' => 'Chị Tư'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.summary', 'Đang gõ dở, chưa lưu');

    expect($intake->fresh()->referred_by)->toBe('Chị Tư')
        ->and($intake->fresh()->summary)->toBeNull();
});

it('refuses with a notice, and changes nothing, a save on a record that was merged meanwhile', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    $page = iraEdit($intake);
    $intake->forceFill(['status' => IntakeStatus::Merged])->save();

    $page->fillForm(['contact_name' => 'Đổi Sau Khi Gộp'])->call('save')->assertNotified(__('actions.failed_title'));

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');
});

it('answers a busy conflict lock on save with a notice, keeping what was typed', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);
    $lock = Cache::store('database')->lock('conflict-check', 120);
    $lock->get();

    $this->actingAs($assistant, 'web');

    try {
        iraEdit($intake)
            ->fillForm(['contact_name' => 'Đổi Lúc Bận'])
            ->call('save')
            ->assertNotified(__('actions.failed_title'))
            ->assertSet('data.contact_name', 'Đổi Lúc Bận');
    } finally {
        $lock->release();
    }

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');
});

it('shows an anonymised record read-only even while its status is still open', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);
    $intake->forceFill(['anonymised_at' => now()])->save();

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertFormFieldIsDisabled('contact_name')
        ->assertActionHidden('changeStatus')
        ->assertActionHidden('decline')
        ->assertActionHidden('merge');

    expect(iraFormActionNames($intake))->toBe([]);
});

it('offers the save button on an open record', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    expect(iraFormActionNames($intake))->toContain('save');
});

/** Tên các nút dưới form chính (Lưu, Huỷ) — `getFormActions()` là protected, gọi qua một closure gắn vào trang. */
function iraFormActionNames(IntakeRequest $intake): array
{
    $page = iraEdit($intake)->instance();

    return array_map(fn ($action): string => $action->getName(), (fn (): array => $this->getFormActions())->call($page));
}
