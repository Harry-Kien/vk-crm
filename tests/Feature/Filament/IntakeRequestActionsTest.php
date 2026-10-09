<?php

use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Intake\PrivacyNotice;
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
        ->assertSee(PrivacyNotice::text())
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
    // Cùng người = cùng số, cùng vai (fix vòng 1: trợ lý chỉ gộp một bản đang khoá cuộc gọi lại vào
    // một bản bắt được đúng các cuộc gọi lại đó). Bản Xanh ghi TRƯỚC, nên nó không bị khoá theo bản Đỏ.
    $green = iraRecord($assistant, ['contact_phone' => '0832 270 898']);
    app(RecordPrivacyNotice::class)->handle($assistant, $green, true);
    $red = iraRedIntake($assistant);

    expect($green->fresh()->hasUnresolvedRed())->toBeFalse();

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

// ---------------------------------------------------------------- fix vòng 1 (rà soát Task 3, C1)
//
// Khoá người gọi lại (fix vòng 1 của Task 2, C1) đọc hai điều của lần gọi TRƯỚC: nó còn mở, và nó còn
// SĐT/CCCD + vai của người gọi. Gộp (bản nguồn rời `openForConflictCheck()`) và sửa danh tính (đổi
// SĐT/CCCD/vai) là hai đường bỏ một trong hai điều đó. Các test dưới đây kết thúc bằng cùng một câu
// hỏi, qua màn hình: trợ lý B ghi cuộc gọi lại của người đó — ô câu chuyện có còn khoá như Đỏ không?

/** Lần gọi đầu: Đỏ (bên đối lập là khách hiện hữu), quản lý đã từ chối VÌ XUNG ĐỘT. */
function iraDeclinedForConflict(User $creator): IntakeRequest
{
    $intake = iraRedIntake($creator);
    app(DeclineIntake::class)->handle(iraStaff(Role::Manager), $intake, 'Bên kia là khách hiện hữu', true);

    return $intake->fresh();
}

/**
 * Cuộc gọi lại của cùng người, ghi QUA TRANG TẠO: cùng số (dạng gõ khác), cùng vai, không nhắc lại
 * bên đối lập — đúng kịch bản C1 của Task 2.
 */
function iraCallbackThroughScreen(User $actor): IntakeRequest
{
    test()->actingAs($actor, 'web');
    test()->livewire(CreateIntakeRequest::class)
        ->fillForm([
            'contact_name' => 'Người Gọi Lần Sau',
            'contact_phone' => '+84 832 270 898',
            'contact_role' => PartyRole::Plaintiff->value,
            'source' => IntakeSource::Phone->value,
            'privacy_notice' => true,
            'parties' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    return IntakeRequest::query()->where('contact_name', 'Người Gọi Lần Sau')->sole();
}

/** Ô câu chuyện của cuộc gọi lại khoá như Đỏ, chờ quản lý — nhìn từ trang của người ghi nó. */
function iraAssertCallbackLocked(User $actor, IntakeRequest $callback): void
{
    test()->actingAs($actor, 'web');
    iraEdit($callback)
        ->assertSee(__('enums.intake_summary_blocker.conflict_red'))
        ->assertFormFieldIsDisabled('summary');

    expect($callback->fresh()->hasUnresolvedRed())->toBeTrue();
}

it('refuses an assistant the merge of a record declined for a conflict into a record with another number, so the callback stays locked', function () {
    $assistantA = iraStaff();
    $first = iraDeclinedForConflict($assistantA);
    $other = iraRecord($assistantA, ['contact_phone' => '0901777888']);

    $this->actingAs($assistantA, 'web');
    iraEdit($first)
        ->callAction('merge', data: ['merge_target' => $other->id])
        ->assertHasActionErrors(['merge_target']);

    expect($first->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($first->fresh()->merged_into_id)->toBeNull()
        ->and($other->parties()->count())->toBe(0);

    $assistantB = iraStaff();
    iraAssertCallbackLocked($assistantB, iraCallbackThroughScreen($assistantB));
});

it('refuses an assistant the merge of a pending red into a record with another number', function () {
    $assistant = iraStaff();
    $red = iraRedIntake($assistant);
    $other = iraRecord($assistant, ['contact_phone' => '0901777888']);

    $this->actingAs($assistant, 'web');
    iraEdit($red)
        ->callAction('merge', data: ['merge_target' => $other->id])
        ->assertHasActionErrors(['merge_target' => __('intake.errors.merge_drops_caller')]);

    expect($red->fresh()->status)->toBe(IntakeStatus::New)
        ->and($other->fresh()->conflict_red_pending_since)->toBeNull();
});

it('answers the merge of a declined record the same way whatever the reason, so the refusal does not tell an assistant it was a conflict', function () {
    $assistant = iraStaff();
    $ordinary = iraRecord($assistant, ['contact_phone' => '0901000001']);
    app(DeclineIntake::class)->handle($assistant, $ordinary, 'Ngoài lĩnh vực', false);
    $conflict = iraRecord($assistant, ['contact_phone' => '0901000002']);
    app(DeclineIntake::class)->handle(iraStaff(Role::Manager), $conflict, 'Bên kia là khách hiện hữu', true);
    $target = iraRecord($assistant, ['contact_phone' => '0901000009']);

    $this->actingAs($assistant, 'web');
    $errorOf = fn (IntakeRequest $source): array => iraEdit($source->fresh())
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasActionErrors(['merge_target'])
        ->errors()->get('mountedActions.0.data.merge_target');

    expect($errorOf($ordinary))->toBe($errorOf($conflict))
        ->and($errorOf($ordinary))->toBe([__('intake.errors.merge_drops_caller')])
        ->and($ordinary->fresh()->status)->toBe(IntakeStatus::Declined)
        ->and($conflict->fresh()->status)->toBe(IntakeStatus::Declined);
});

it('lets an assistant merge a record declined for a conflict into a record of the same caller, and the callback stays locked through it', function () {
    $assistantA = iraStaff();
    // Bản đích ghi TRƯỚC (cùng số, cùng vai, Xanh), rồi lần gọi Đỏ bị từ chối vì xung đột.
    $sameCaller = iraRecord($assistantA, ['contact_phone' => '0832 270 898']);
    $first = iraDeclinedForConflict($assistantA);

    $this->actingAs($assistantA, 'web');
    iraEdit($first)
        ->callAction('merge', data: ['merge_target' => $sameCaller->id])
        ->assertHasNoErrors();

    expect($first->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($sameCaller->fresh()->hasUnresolvedRed())->toBeTrue();

    $assistantB = iraStaff();
    iraAssertCallbackLocked($assistantB, iraCallbackThroughScreen($assistantB));
});

it('lets a manager merge a record declined for a conflict into a record with another number', function () {
    $assistant = iraStaff();
    $first = iraDeclinedForConflict($assistant);
    $other = iraRecord($assistant, ['contact_phone' => '0901777888']);

    $this->actingAs(iraStaff(Role::Manager), 'web');
    iraEdit($first)
        ->callAction('merge', data: ['merge_target' => $other->id])
        ->assertHasNoErrors();

    expect($first->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($other->fresh()->hasUnresolvedRed())->toBeTrue();
});

it('shows the identity of a declined record read-only, saves nothing from a forged save, and the callback stays locked', function () {
    $assistantA = iraStaff();
    $first = iraDeclinedForConflict($assistantA);

    $this->actingAs($assistantA, 'web');
    // Cả phần danh tính khoá: không câu "còn Đỏ chờ xử lý — chỉ trưởng phòng đổi được" dưới ô nào (fix
    // vòng 2): câu đó sai ở đây (trưởng phòng cũng không đổi được) và nói lý do từ chối (R8).
    iraEdit($first)
        ->assertFormFieldIsDisabled('contact_phone')
        ->assertFormFieldIsDisabled('contact_name')
        ->assertFormFieldIsDisabled('contact_role')
        ->assertFormFieldIsDisabled('parties')
        ->assertDontSee(__('intake.fields.caller_keys_locked_help'));

    expect(iraFormActionNames($first))->toBe([]);

    iraEdit($first)
        ->fillForm(['contact_phone' => '0901777888'])
        ->call('save')
        ->assertNotified(__('actions.failed_title'));

    expect($first->fresh()->contact_phone_normalized)->toBe('84832270898');

    $assistantB = iraStaff();
    iraAssertCallbackLocked($assistantB, iraCallbackThroughScreen($assistantB));
});

it('shows the identity of a record declined for an ordinary reason read-only too, the same as one declined for a conflict', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant, ['contact_phone' => '0901000001']);
    app(DeclineIntake::class)->handle($assistant, $intake, 'Ngoài lĩnh vực', false);

    $this->actingAs($assistant, 'web');
    iraEdit($intake->fresh())
        ->assertFormFieldIsDisabled('contact_phone')
        ->assertFormFieldIsDisabled('contact_name')
        ->assertFormFieldIsDisabled('parties')
        ->assertDontSee(__('intake.fields.caller_keys_locked_help'))
        ->fillForm(['contact_name' => 'Đổi Sau Khi Từ Chối'])
        ->call('save')
        ->assertNotified(__('actions.failed_title'));

    expect(iraFormActionNames($intake->fresh()))->toBe([])
        ->and($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');
});

it('shows an assistant the same identity help on a green record declined for a conflict as on one declined for an ordinary reason, so the page does not tell it was a conflict', function () {
    $assistant = iraStaff();
    $ordinary = iraRecord($assistant, ['contact_name' => 'Lê Thị Thường', 'contact_phone' => '0901000001']);
    app(DeclineIntake::class)->handle($assistant, $ordinary, 'Ngoài lĩnh vực', false);
    // Xanh, không Đỏ nào chờ xử lý: điều duy nhất khác bản trên là lý do từ chối — xung đột (R8).
    $conflict = iraRecord($assistant, ['contact_name' => 'Trần Văn Xung', 'contact_phone' => '0901000002']);
    app(DeclineIntake::class)->handle(iraStaff(Role::Manager), $conflict, 'Bên kia là khách hiện hữu', true);

    expect($conflict->fresh()->conflict_level)->toBe(ConflictLevel::Green)
        ->and($conflict->fresh()->hasUnresolvedRed())->toBeFalse()
        ->and($conflict->fresh()->decline_reason_is_conflict)->toBeTrue();

    $this->actingAs($assistant, 'web');
    $helpTexts = [
        __('intake.fields.caller_keys_locked_help'),
        __('intake.fields.contact_phone_help'),
        __('intake.fields.contact_id_number_help_edit'),
        __('intake.fields.contact_role_help'),
    ];
    $helpOn = function (IntakeRequest $intake) use ($helpTexts): array {
        $html = iraEdit($intake->fresh())
            ->assertFormFieldIsDisabled('contact_phone')
            ->assertFormFieldIsDisabled('contact_role')
            ->html();

        return array_map(fn (string $text): int => substr_count($html, $text), $helpTexts);
    };

    expect($helpOn($conflict))->toBe($helpOn($ordinary))
        ->and($helpOn($ordinary))->toBe([0, 1, 1, 1]);
});

it('keeps the phone, ID number and role of a record with a pending red out of an assistant\'s reach, not the name, and the callback stays locked', function () {
    $assistantA = iraStaff();
    $red = iraRedIntake($assistantA, ['contact_id_number' => '079123456789']);

    $this->actingAs($assistantA, 'web');
    iraEdit($red)
        ->assertFormFieldIsDisabled('contact_phone')
        ->assertFormFieldIsDisabled('contact_id_number')
        ->assertFormFieldIsDisabled('contact_role')
        ->assertFormFieldIsEnabled('contact_name')
        ->assertSee(__('intake.fields.caller_keys_locked_help'));

    iraEdit($red)
        ->fillForm(['contact_phone' => '0901777888'])
        ->call('save')
        ->assertHasFormErrors(['contact_phone']);

    iraEdit($red)
        ->fillForm(['contact_role' => PartyRole::Related->value])
        ->call('save')
        ->assertHasFormErrors(['contact_role']);

    expect($red->fresh()->contact_phone_normalized)->toBe('84832270898')
        ->and($red->fresh()->contact_role)->toBe(PartyRole::Plaintiff);

    iraEdit($red)
        ->fillForm(['contact_name' => 'Người Gọi Đã Sửa Tên'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($red->fresh()->contact_name)->toBe('Người Gọi Đã Sửa Tên');

    $assistantB = iraStaff();
    iraAssertCallbackLocked($assistantB, iraCallbackThroughScreen($assistantB));
});

it('leaves an empty phone or ID number of a record with a pending red open to the assistant, who can fill it in', function (array $recorded, string $open, string $locked, string $typed, string $column, string $stored) {
    $assistant = iraStaff();
    $red = iraRedIntake($assistant, $recorded);

    $this->actingAs($assistant, 'web');
    $page = iraEdit($red)
        ->assertFormFieldIsEnabled($open)
        ->assertFormFieldIsDisabled($locked)
        ->assertFormFieldIsDisabled('contact_role');

    // Câu "chỉ trưởng phòng… đổi được ô này" dưới đúng hai ô khoá (ô còn lại và vai), không dưới ô mở.
    expect(substr_count($page->html(), __('intake.fields.caller_keys_locked_help')))->toBe(2);

    $page->fillForm([$open => $typed])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = $column === 'contact_id_number_hash' ? Normalizer::idNumberHash($stored) : $stored;

    expect($red->fresh()->{$column})->toBe($stored)
        ->and($red->fresh()->hasUnresolvedRed())->toBeTrue();
})->with([
    'no ID number yet' => [[], 'contact_id_number', 'contact_phone', '079123456789', 'contact_id_number_hash', '079123456789'],
    'no phone yet' => [['contact_phone' => null, 'contact_email' => 'goi@example.com', 'contact_id_number' => '079123456789'], 'contact_phone', 'contact_id_number', '0832270898', 'contact_phone_normalized', '84832270898'],
]);

it('lets a manager change the phone of a record with a pending red', function () {
    $red = iraRedIntake(iraStaff());

    $this->actingAs(iraStaff(Role::Manager), 'web');
    iraEdit($red)
        ->assertFormFieldIsEnabled('contact_phone')
        ->assertDontSee(__('intake.fields.caller_keys_locked_help'))
        ->fillForm(['contact_phone' => '0901777888'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($red->fresh()->contact_phone_normalized)->toBe('84901777888');
});

it('leaves the phone and role of a record with no pending red to the assistant who recorded it', function () {
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertFormFieldIsEnabled('contact_phone')
        ->assertFormFieldIsEnabled('contact_role')
        ->assertDontSee(__('intake.fields.caller_keys_locked_help'))
        ->fillForm(['contact_phone' => '0901777888'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->contact_phone_normalized)->toBe('84901777888');
});

/*
 * Lượt quét trước bản 1.0 (việc của luật sư/chủ văn phòng ở Ghi chú M10, phần là MÃ): câu thông báo
 * đọc cho người gọi viết cứng "24 tháng" trong khi hạn lưu thật đọc `PROSPECT_RETENTION_MONTHS`
 * (`IntakeRequest::retentionMonths()`). Đổi biến thì câu nói sai. Nay câu đọc chính con số đó, và
 * phiên bản ghi kèm mỗi lần ghi nhận mang con số khi nó khác số mà bản chữ gốc được viết với — để vẫn
 * biết mỗi người đã nghe câu nào (luật "đổi chữ thì đổi version").
 */
it('reads the retention period of the privacy notice from the configured number of months', function () {
    config(['vkcrm.prospect_retention_months' => 36]);
    $assistant = iraStaff();

    $this->actingAs($assistant, 'web');
    test()->livewire(CreateIntakeRequest::class)
        ->assertSee(PrivacyNotice::text())
        ->assertSee('36 tháng')
        ->assertDontSee('24 tháng');

    $intake = iraRecord($assistant);
    iraEdit($intake)
        ->assertSee('36 tháng')
        ->callAction(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'), data: ['privacy_notice' => true])
        ->assertHasNoErrors();

    expect($intake->fresh()->privacy_notice_version)->toBe(__('intake.privacy_notice.version').'-36t');
});

it('keeps the original notice version when the retention period is the one the text was written for', function () {
    config(['vkcrm.prospect_retention_months' => PrivacyNotice::BASE_MONTHS]);
    $assistant = iraStaff();
    $intake = iraRecord($assistant);

    $this->actingAs($assistant, 'web');
    iraEdit($intake)
        ->assertSee(PrivacyNotice::BASE_MONTHS.' tháng')
        ->callAction(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'), data: ['privacy_notice' => true])
        ->assertHasNoErrors();

    expect($intake->fresh()->privacy_notice_version)->toBe(PrivacyNotice::version())
        ->and(mb_strlen(PrivacyNotice::version()))->toBeLessThanOrEqual(20);

    // Một bản ghi đã nghe câu 24 tháng rồi văn phòng đổi sang 12: nút ghi nhận hiện lại.
    config(['vkcrm.prospect_retention_months' => 12]);
    iraEdit($intake->fresh())->assertActionVisible(TestAction::make('recordPrivacyNotice')->schemaComponent('privacyActions'));
});
