<?php

use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\IntakeSummaryGate;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeIdentity;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictCheckBusy;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * M10 Task 3 — luật nghiệp vụ của bốn Action mới mà màn hình tiếp nhận gọi: `UpdateIntakeIdentity`
 * (sửa phần danh tính, chạy lại kiểm tra), `ChangeIntakeStatus`, `DeclineIntake` (R8), `MergeIntake`
 * (R4), và khoá "đã từ chối" của cổng câu chuyện. Hành vi MÀN HÌNH ở
 * `tests/Feature/Filament/IntakeRequest{Resource,Actions}Test.php`; tệp này canh từng điều kiện của
 * Action (mỗi điều kiện có cặp dương/âm, cho mutation probe).
 *
 * Hàm toàn cục mang tiền tố `iwa…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function iwaStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function iwaRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

function iwaRed(User $actor, array $overrides = []): IntakeRequest
{
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null]);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    return iwaRecord($actor, $overrides, [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
}

function iwaIdentity(IntakeRequest $intake, array $overrides = []): array
{
    return [...[
        'contact_name' => $intake->contact_name,
        'contact_phone' => $intake->contact_phone,
        'contact_email' => $intake->contact_email,
        'contact_id_number' => null,
        'contact_role' => $intake->contact_role,
        'source' => $intake->source,
        'referred_by' => $intake->referred_by,
        'matter_type_id' => $intake->matter_type_id,
        'quoted_amount' => $intake->quoted_amount,
        'assigned_to' => $intake->assigned_to,
    ], ...$overrides];
}

/** @return list<array<string, mixed>> */
function iwaParties(IntakeRequest $intake): array
{
    return $intake->parties()->orderBy('id')->get()
        ->map(fn ($party): array => ['id' => $party->id, 'name' => $party->name, 'role' => $party->role, 'phone' => null, 'id_number' => null])
        ->all();
}

function iwaRuns(IntakeRequest $intake): int
{
    return Activity::query()->where('event', 'conflict_check_run')
        ->where('subject_type', 'intake_request')->where('subject_id', $intake->id)->count();
}

// ---------------------------------------------------------------- UpdateIntakeIdentity

it('re-runs the conflict check only when the identity changed, not for a change of source or fee', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, [
        'source' => IntakeSource::Zalo, 'referred_by' => 'Anh Ba', 'quoted_amount' => '5.000.000',
    ]), iwaParties($intake));

    expect(iwaRuns($intake))->toBe(1)
        ->and($intake->fresh()->source)->toBe(IntakeSource::Zalo)
        ->and($intake->fresh()->quoted_amount)->toBe(5_000_000)
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->not->toContain(IntakeSummaryBlocker::ConflictUnchecked);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake->fresh(), iwaIdentity($intake->fresh(), ['contact_name' => 'Tên Đã Sửa']), iwaParties($intake));

    expect(iwaRuns($intake))->toBe(2)
        ->and($intake->fresh()->conflict_result['fingerprint'])->toBe($intake->fresh()->identityFingerprint());
});

it('re-runs the check when only an opposing party changed', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [['name' => 'Bên A', 'role' => PartyRole::Defendant]]);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
        ...iwaParties($intake), ['name' => 'Bên B', 'role' => PartyRole::Defendant, 'phone' => '0966000111'],
    ]);

    expect(iwaRuns($intake))->toBe(2);
});

it('keeps the stored id hash when the id field is left blank, and replaces it when a new number is typed', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, ['contact_id_number' => '079123456789']);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, ['contact_id_number' => '  ']), []);

    expect($intake->fresh()->contact_id_number_hash)->toBe(Normalizer::idNumberHash('079123456789'));

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake->fresh(), iwaIdentity($intake->fresh(), ['contact_id_number' => '001088000222']), []);

    expect($intake->fresh()->contact_id_number_hash)->toBe(Normalizer::idNumberHash('001088000222'))
        ->and(Activity::query()->where('event', 'intake_identity_updated')->sole()->properties['changed'])->toBe(['contact_id_number']);
});

it('keeps the stored identifiers of an existing opposing party left blank, replaces typed ones, and deletes the rows left out', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [
        ['name' => 'Giữ Số', 'role' => PartyRole::Defendant, 'phone' => '0977000111', 'id_number' => '001099000111'],
        ['name' => 'Đổi Số', 'role' => PartyRole::Defendant, 'phone' => '0977000222'],
        ['name' => 'Bỏ Đi', 'role' => PartyRole::Defendant],
    ]);
    [$keep, $change] = $intake->parties()->orderBy('id')->get()->all();

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
        ['id' => $keep->id, 'name' => 'Giữ Số', 'role' => PartyRole::Defendant, 'phone' => '', 'id_number' => null],
        ['id' => $change->id, 'name' => 'Đổi Số', 'role' => PartyRole::Related, 'phone' => '0977 000 333', 'id_number' => null],
    ]);

    expect($intake->parties()->orderBy('id')->pluck('name')->all())->toBe(['Giữ Số', 'Đổi Số'])
        ->and($keep->fresh()->phone_normalized)->toBe('84977000111')
        ->and($keep->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('001099000111'))
        ->and($change->fresh()->phone_normalized)->toBe('84977000333')
        ->and($change->fresh()->role)->toBe(PartyRole::Related);
});

it('refuses a party row id of another record', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);
    $other = iwaRecord($assistant, ['contact_phone' => '0901000001'], [['name' => 'Của Bản Khác', 'role' => PartyRole::Defendant]]);
    $foreign = $other->parties()->sole();

    expect(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
        ['id' => $foreign->id, 'name' => 'Cướp', 'role' => PartyRole::Defendant],
    ]))->toThrow(ValidationException::class);

    expect($foreign->fresh()->name)->toBe('Của Bản Khác')
        ->and($foreign->fresh()->intake_request_id)->toBe($other->id);
});

it('validates the identity edit with the same column lengths and rules as the first recording', function (array $override, string $field) {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);

    try {
        app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, $override), []);
        $this->fail('expected a validation error');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($field);
    }
})->with([
    'name 201' => [['contact_name' => str_repeat('a', 201)], 'contact_name'],
    'name blank' => [['contact_name' => ''], 'contact_name'],
    'phone 21' => [['contact_phone' => str_repeat('1', 21)], 'contact_phone'],
    'opposing counsel' => [['contact_role' => PartyRole::OpposingCounsel], 'contact_role'],
    'fee' => [['quoted_amount' => 'abc'], 'quoted_amount'],
]);

it('refuses to edit the identity of a merged, anonymised or converted record', function (array $state) {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);
    $intake->forceFill($state)->save();

    expect(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake->fresh(), iwaIdentity($intake, ['contact_name' => 'Đổi']), []))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');
})->with([
    'merged' => [['status' => IntakeStatus::Merged]],
    'anonymised' => [['anonymised_at' => '2026-09-30 10:00:00']],
    'won' => [['status' => IntakeStatus::Won]],
]);

it('refuses an identity edit to someone who cannot see the record', function () {
    $intake = iwaRecord(iwaStaff());

    expect(fn () => app(UpdateIntakeIdentity::class)->handle(iwaStaff(), $intake, iwaIdentity($intake), []))
        ->toThrow(AuthorizationException::class);
});

it('answers ConflictCheckBusy from an identity edit while the conflict lock is held, and writes nothing', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);
    $lock = Cache::store('database')->lock('conflict-check', 120);
    $lock->get();

    try {
        expect(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, ['contact_name' => 'Đổi Trong Lúc Bận']), []))
            ->toThrow(ConflictCheckBusy::class);
    } finally {
        $lock->release();
    }

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu');
});

it('logs an identity edit with the names of the changed fields only, never their values', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, ['contact_name' => 'Tên Rất Riêng Tư']), []);

    $row = Activity::query()->where('event', 'intake_identity_updated')->sole();

    expect($row->causer_id)->toBe($assistant->id)
        ->and($row->properties['changed'])->toBe(['contact_name'])
        ->and(json_encode($row->properties->all()))->not->toContain('Riêng Tư');
});

// ---------------------------------------------------------------- ChangeIntakeStatus

it('moves an open record between the manual steps', function (IntakeStatus $from, IntakeStatus $to) {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);
    $intake->forceFill(['status' => $from])->save();

    app(ChangeIntakeStatus::class)->handle($assistant, $intake->fresh(), $to);

    expect($intake->fresh()->status)->toBe($to)
        ->and(Activity::query()->where('event', 'intake_status_changed')->sole()->properties->all())
        ->toBe(['from' => $from->value, 'to' => $to->value]);
})->with([
    'new → contacted' => [IntakeStatus::New, IntakeStatus::Contacted],
    'contacted → consulting' => [IntakeStatus::Contacted, IntakeStatus::Consulting],
    'consulting → quoted' => [IntakeStatus::Consulting, IntakeStatus::Quoted],
    'quoted → lost' => [IntakeStatus::Quoted, IntakeStatus::Lost],
    'new → lost' => [IntakeStatus::New, IntakeStatus::Lost],
]);

it('refuses the statuses that belong to another action, the same status, and any move out of a final status', function (IntakeStatus $from, IntakeStatus $to) {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);
    $intake->forceFill(['status' => $from])->save();

    expect(fn () => app(ChangeIntakeStatus::class)->handle($assistant, $intake->fresh(), $to))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->status)->toBe($from);
})->with([
    'to won' => [IntakeStatus::New, IntakeStatus::Won],
    'to merged' => [IntakeStatus::New, IntakeStatus::Merged],
    'to declined' => [IntakeStatus::New, IntakeStatus::Declined],
    'back to new' => [IntakeStatus::Contacted, IntakeStatus::New],
    'same' => [IntakeStatus::Contacted, IntakeStatus::Contacted],
    'from declined' => [IntakeStatus::Declined, IntakeStatus::Contacted],
    'from lost' => [IntakeStatus::Lost, IntakeStatus::Contacted],
    'from won' => [IntakeStatus::Won, IntakeStatus::Contacted],
    'from merged' => [IntakeStatus::Merged, IntakeStatus::Contacted],
]);

it('refuses a status change on an anonymised record and to someone who cannot see the record', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant);

    expect(fn () => app(ChangeIntakeStatus::class)->handle(iwaStaff(), $intake, IntakeStatus::Contacted))
        ->toThrow(AuthorizationException::class);

    $intake->forceFill(['anonymised_at' => now()])->save();

    expect(fn () => app(ChangeIntakeStatus::class)->handle($assistant, $intake->fresh(), IntakeStatus::Contacted))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------- DeclineIntake

it('declines with a trimmed reason and logs neither the reason nor whether it was a conflict', function () {
    $lawyer = iwaStaff(Role::Lawyer);
    $intake = iwaRecord($lawyer);

    app(DeclineIntake::class)->handle($lawyer, $intake, '  Ngoài lĩnh vực  ', false);

    $fresh = $intake->fresh();
    $row = Activity::query()->where('event', 'intake_declined')->sole();

    expect($fresh->status)->toBe(IntakeStatus::Declined)
        ->and($fresh->decline_reason)->toBe('Ngoài lĩnh vực')
        ->and($fresh->decline_reason_is_conflict)->toBeFalse()
        ->and($row->causer_id)->toBe($lawyer->id)
        ->and($row->properties->all())->toBe(['from' => IntakeStatus::New->value])
        ->and(json_encode($row->properties->all()))->not->toContain('lĩnh vực');
});

it('lets only a manager or admin decline for a conflict', function (Role $role, bool $allowed) {
    $intake = iwaRecord(iwaStaff(Role::Lawyer));
    $actor = iwaStaff($role);
    $intake->forceFill(['assigned_to' => $actor->id])->save();

    $call = fn () => app(DeclineIntake::class)->handle($actor, $intake->fresh(), 'Xung đột với khách cũ', true);

    if ($allowed) {
        $call();
        expect($intake->fresh()->decline_reason_is_conflict)->toBeTrue();
    } else {
        expect($call)->toThrow(AuthorizationException::class);
        expect($intake->fresh()->status)->toBe(IntakeStatus::New);
    }
})->with([
    'admin' => [Role::Admin, true],
    'manager' => [Role::Manager, true],
    'lawyer' => [Role::Lawyer, false],
    'assistant' => [Role::Assistant, false],
]);

it('requires a reason of at most 2000 characters', function (string $reason) {
    $lawyer = iwaStaff(Role::Lawyer);
    $intake = iwaRecord($lawyer);

    expect(fn () => app(DeclineIntake::class)->handle($lawyer, $intake, $reason, false))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->status)->toBe(IntakeStatus::New);
})->with(['blank' => '   ', 'too long' => str_repeat('a', 2001)]);

it('accepts a reason of exactly 2000 characters', function () {
    $lawyer = iwaStaff(Role::Lawyer);
    $intake = iwaRecord($lawyer);

    app(DeclineIntake::class)->handle($lawyer, $intake, str_repeat('ạ', 2000), false);

    expect($intake->fresh()->status)->toBe(IntakeStatus::Declined);
});

it('declines from every open step but not from a final one', function (IntakeStatus $from, bool $allowed) {
    $manager = iwaStaff(Role::Manager);
    $intake = iwaRecord($manager);
    $intake->forceFill(['status' => $from])->save();

    $call = fn () => app(DeclineIntake::class)->handle($manager, $intake->fresh(), 'Lý do', false);

    if ($allowed) {
        $call();
        expect($intake->fresh()->status)->toBe(IntakeStatus::Declined);
    } else {
        expect($call)->toThrow(ValidationException::class);
        expect($intake->fresh()->status)->toBe($from);
    }
})->with([
    'new' => [IntakeStatus::New, true],
    'contacted' => [IntakeStatus::Contacted, true],
    'consulting' => [IntakeStatus::Consulting, true],
    'quoted' => [IntakeStatus::Quoted, true],
    'declined' => [IntakeStatus::Declined, false],
    'lost' => [IntakeStatus::Lost, false],
    'won' => [IntakeStatus::Won, false],
    'merged' => [IntakeStatus::Merged, false],
]);

it('keeps a pending red when a manager declines for a conflict', function () {
    $assistant = iwaStaff();
    $intake = iwaRed($assistant);
    $since = $intake->conflict_red_pending_since;

    app(DeclineIntake::class)->handle(iwaStaff(Role::Manager), $intake, 'Xung đột', true);

    expect($intake->fresh()->conflict_red_pending_since?->toDateTimeString())->toBe($since->toDateTimeString());
});

it('closes the story of a declined record for good, for any reason, with a word that does not say why', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977000999']]);
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    app(DeclineIntake::class)->handle($assistant, $intake->fresh(), 'Ngoài lĩnh vực', false);

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::Declined])
        ->and(IntakeSummaryBlocker::Declined->label())->not->toContain('xung đột');
});

it('answers ConflictCheckBusy from a decline while the conflict lock is held', function () {
    $lawyer = iwaStaff(Role::Lawyer);
    $intake = iwaRecord($lawyer);
    $lock = Cache::store('database')->lock('conflict-check', 120);
    $lock->get();

    try {
        expect(fn () => app(DeclineIntake::class)->handle($lawyer, $intake, 'Lý do', false))->toThrow(ConflictCheckBusy::class);
    } finally {
        $lock->release();
    }

    expect($intake->fresh()->status)->toBe(IntakeStatus::New);
});

// ---------------------------------------------------------------- MergeIntake

it('merges a record into another: status, link, moved parties, a fresh check of the target, two log rows', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001'], [['name' => 'Bên Nguồn', 'role' => PartyRole::Defendant, 'phone' => '0966000111']]);
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);
    $targetRuns = iwaRuns($target);

    app(MergeIntake::class)->handle($assistant, $source, $target);

    $source = $source->fresh();
    $target = $target->fresh();

    expect($source->status)->toBe(IntakeStatus::Merged)
        ->and($source->merged_into_id)->toBe($target->id)
        ->and($target->parties()->pluck('name')->all())->toBe(['Bên Nguồn'])
        ->and($target->parties()->sole()->phone_normalized)->toBe('84966000111')
        ->and(iwaRuns($target))->toBe($targetRuns + 1)
        ->and($target->conflict_result['fingerprint'])->toBe($target->identityFingerprint())
        ->and($target->conflict_red_pending_since)->toBeNull()
        ->and(Activity::query()->where('event', 'intake_merged')->sole()->properties->all())->toBe(['merged_into' => $target->code, 'moved_parties' => 1])
        ->and(Activity::query()->where('event', 'intake_merge_received')->sole()->properties->all())->toBe(['merged_from' => $source->code, 'moved_parties' => 1]);
});

it('carries a pending red of the merged record onto the target, keeping the earlier time', function () {
    $assistant = iwaStaff();
    $this->travelTo(now()->subDay());
    $red = iwaRed($assistant, ['contact_phone' => '0901000001']);
    $this->travelBack();
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);

    // Khác số: chỉ quản lý/admin gộp được một bản đang khoá cuộc gọi lại vào đây (fix vòng 1).
    app(MergeIntake::class)->handle(iwaStaff(Role::Manager), $red, $target);

    expect($target->fresh()->conflict_red_pending_since?->toDateTimeString())
        ->toBe($red->conflict_red_pending_since->toDateTimeString());
});

it('keeps the earlier pending time of the target when the target was red first', function () {
    $assistant = iwaStaff();
    $this->travelTo(now()->subDays(2));
    $target = iwaRed($assistant, ['contact_phone' => '0901000002']);
    $this->travelBack();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $source->forceFill(['decline_reason_is_conflict' => true])->save();

    app(MergeIntake::class)->handle(iwaStaff(Role::Manager), $source->fresh(), $target);

    expect($target->fresh()->conflict_red_pending_since?->toDateTimeString())
        ->toBe($target->conflict_red_pending_since->toDateTimeString());
});

it('marks the target red pending when the merged record was declined for a conflict, even with no red left', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $source->forceFill(['decline_reason_is_conflict' => true])->save();
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);

    app(MergeIntake::class)->handle(iwaStaff(Role::Manager), $source->fresh(), $target);

    expect($target->fresh()->hasUnresolvedRed())->toBeTrue();
});

it('does not mark the target when the merged record holds no red and was not declined for a conflict', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $source->forceFill(['status' => IntakeStatus::Declined, 'decline_reason' => 'Khác', 'decline_reason_is_conflict' => false])->save();
    // Cùng số, cùng vai: trợ lý gộp được một bản đã từ chối chỉ vào bản bắt được cùng các cuộc gọi lại
    // (fix vòng 1).
    $target = iwaRecord($assistant, ['contact_phone' => '0901000001']);

    app(MergeIntake::class)->handle($assistant, $source->fresh(), $target);

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($target->fresh()->conflict_red_pending_since)->toBeNull();
});

it('refuses to merge a record into itself, into a closed target, or a closed source', function (string $case) {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);

    match ($case) {
        'itself' => $target = $source,
        'target merged' => $target->forceFill(['status' => IntakeStatus::Merged])->save(),
        'target won' => $target->forceFill(['status' => IntakeStatus::Won])->save(),
        'target converted' => $target->forceFill(['matter_id' => Matter::factory()->create()->id])->save(),
        'target anonymised' => $target->forceFill(['anonymised_at' => now()])->save(),
        'source merged' => $source->forceFill(['status' => IntakeStatus::Merged])->save(),
        'source won' => $source->forceFill(['status' => IntakeStatus::Won])->save(),
        'source anonymised' => $source->forceFill(['anonymised_at' => now()])->save(),
    };

    $sourceStatus = $source->fresh()->status;

    expect(fn () => app(MergeIntake::class)->handle($assistant, $source->fresh(), $target->fresh()))
        ->toThrow(ValidationException::class);

    expect($source->fresh()->status)->toBe($sourceStatus);
})->with(['itself', 'target merged', 'target won', 'target converted', 'target anonymised', 'source merged', 'source won', 'source anonymised']);

it('refuses a merge to someone who cannot see either record', function (string $hidden) {
    $assistant = iwaStaff();
    $other = iwaStaff();
    $source = iwaRecord($hidden === 'source' ? $other : $assistant, ['contact_phone' => '0901000001']);
    $target = iwaRecord($hidden === 'target' ? $other : $assistant, ['contact_phone' => '0901000002']);

    expect(fn () => app(MergeIntake::class)->handle($assistant, $source, $target))
        ->toThrow(AuthorizationException::class);

    expect($source->fresh()->status)->toBe(IntakeStatus::New);
})->with(['source', 'target']);

it('answers ConflictCheckBusy from a merge while the conflict lock is held', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);
    $lock = Cache::store('database')->lock('conflict-check', 120);
    $lock->get();

    try {
        expect(fn () => app(MergeIntake::class)->handle($assistant, $source, $target))->toThrow(ConflictCheckBusy::class);
    } finally {
        $lock->release();
    }

    expect($source->fresh()->status)->toBe(IntakeStatus::New);
});

it('lets the merged-in parties make the target red when they match an existing client', function () {
    $assistant = iwaStaff();
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null]);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    $source = iwaRecord($assistant, ['contact_phone' => '0901000001']);
    $source->parties()->create(['name' => 'Thêm Tay', 'role' => PartyRole::Defendant])->identify(null, '0912000111')->save();
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002']);

    app(MergeIntake::class)->handle($assistant, $source, $target);

    expect($target->fresh()->conflict_level)->toBe(ConflictLevel::Red)
        ->and($target->fresh()->hasUnresolvedRed())->toBeTrue();
});

/** `$count` bên đối lập chỉ có tên, `"{$prefix} 1"` … `"{$prefix} {$count}"`. */
function iwaNamedParties(string $prefix, int $count): array
{
    return array_map(fn (int $i): array => ['name' => "{$prefix} {$i}", 'role' => PartyRole::Defendant], range(1, $count));
}

it('refuses a merge that would leave the target with more opposing parties than one record may hold, and moves nothing', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001'], iwaNamedParties('Bên Nguồn', 6));
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002'], iwaNamedParties('Bên Đích', 5));
    $targetRuns = iwaRuns($target);

    expect(fn () => app(MergeIntake::class)->handle($assistant, $source, $target))
        ->toThrow(ValidationException::class, __('intake.errors.merge_too_many_parties', ['max' => IntakeRequest::MAX_OPPOSING_PARTIES]));

    expect($source->fresh()->status)->toBe(IntakeStatus::New)
        ->and($source->parties()->count())->toBe(6)
        ->and($target->parties()->count())->toBe(5)
        ->and(iwaRuns($target))->toBe($targetRuns)
        ->and(Activity::query()->where('event', 'intake_merged')->exists())->toBeFalse();
});

it('merges up to exactly the cap, counting once a party both records named', function () {
    $assistant = iwaStaff();
    $source = iwaRecord($assistant, ['contact_phone' => '0901000001'], [...iwaNamedParties('Bên Nguồn', 5), ['name' => 'Bên Đích 1', 'role' => PartyRole::Defendant]]);
    $target = iwaRecord($assistant, ['contact_phone' => '0901000002'], iwaNamedParties('Bên Đích', 5));

    app(MergeIntake::class)->handle($assistant, $source, $target);

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($target->parties()->count())->toBe(IntakeRequest::MAX_OPPOSING_PARTIES);
});

it('uses one cap of opposing parties for the first recording, the identity edit and the screen', function () {
    expect(IntakeRequest::MAX_OPPOSING_PARTIES)->toBe(10);

    $assistant = iwaStaff();

    expect(fn () => iwaRecord($assistant, [], iwaNamedParties('Bên', 11)))->toThrow(ValidationException::class);

    $intake = iwaRecord($assistant, [], iwaNamedParties('Bên', 10));

    expect(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
        ...iwaParties($intake), ['id' => null, 'name' => 'Bên Thứ Mười Một', 'role' => PartyRole::Defendant, 'phone' => null, 'id_number' => null],
    ]))->toThrow(ValidationException::class);
});

it('writes no log row and runs no check when an identity save changes nothing', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => '0966000111']]);

    $result = app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), iwaParties($intake));

    expect($result)->toBeNull()
        ->and(iwaRuns($intake))->toBe(1)
        ->and(Activity::query()->where('event', 'intake_identity_updated')->count())->toBe(0);
});

it('counts an opposing party removed as a change of identity', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [['name' => 'Bên A', 'role' => PartyRole::Defendant]]);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), []);

    expect($intake->parties()->count())->toBe(0)
        ->and(iwaRuns($intake))->toBe(2)
        ->and(Activity::query()->where('event', 'intake_identity_updated')->sole()->properties['changed'])->toBe(['parties']);
});

it('replaces the id hash of an existing opposing party when a new number is typed, keeping its phone', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, [], [['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => '0966000111', 'id_number' => '001099000111']]);
    $party = $intake->parties()->sole();

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
        ['id' => $party->id, 'name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => null, 'id_number' => '001099000999'],
    ]);

    expect($party->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('001099000999'))
        ->and($party->fresh()->phone_normalized)->toBe('84966000111');
});

it('does not take received_at from an identity edit', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, ['received_at' => '2026-09-01 08:00:00']);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, [...iwaIdentity($intake), 'received_at' => '2026-01-01 08:00:00', 'contact_name' => 'Đổi'], []);

    expect($intake->fresh()->received_at->format('Y-m-d H:i'))->toBe('2026-09-01 08:00');
});

it('clears the stored normalised phone when the phone of the contact is erased', function () {
    $assistant = iwaStaff();
    $intake = iwaRecord($assistant, ['contact_email' => 'a@example.com']);

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, ['contact_phone' => null]), []);

    expect($intake->fresh()->contact_phone)->toBeNull()
        ->and($intake->fresh()->contact_phone_normalized)->toBeNull()
        ->and(Activity::query()->where('event', 'intake_identity_updated')->sole()->properties['changed'])->toBe(['contact_phone']);
});

it('refuses a phone whose normalised form would not fit its 20-character column, at the first recording and at an identity edit', function () {
    $assistant = iwaStaff();

    // '0' + 19 chữ số: qua luật `max:20` của dạng gõ, nhưng `Normalizer::phone()` thay số 0 đầu bằng
    // '84' → 21 ký tự, quá `contact_phone_normalized` / `intake_parties.phone_normalized` (20) — lỗi
    // 1406 trên MariaDB strict, SQLite không thấy (rà soát Task 2, m2). Action là cổng thật.
    $tooLong = '09123456780987654321';

    expect(strlen($tooLong))->toBe(20)
        ->and(strlen(Normalizer::phone($tooLong)))->toBe(21);

    $refusedOn = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return array_keys($exception->errors());
        }

        return [];
    };

    expect($refusedOn(fn () => iwaRecord($assistant, ['contact_phone' => $tooLong])))->toBe(['contact_phone'])
        ->and($refusedOn(fn () => iwaRecord($assistant, [], [
            ['name' => 'Bên Một', 'role' => PartyRole::Defendant, 'phone' => '0977000111'],
            ['name' => 'Bên Hai', 'role' => PartyRole::Defendant, 'phone' => $tooLong],
        ])))->toBe(['parties.1.phone'])
        ->and(IntakeRequest::query()->count())->toBe(0);

    $intake = iwaRecord($assistant);

    expect($refusedOn(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, ['contact_phone' => $tooLong]), [])))
        ->toBe(['contact_phone'])
        ->and($refusedOn(fn () => app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake), [
            ['id' => null, 'name' => 'Bên Mới', 'role' => PartyRole::Defendant, 'phone' => $tooLong, 'id_number' => null],
        ])))->toBe(['parties.0.phone'])
        ->and($intake->fresh()->contact_phone_normalized)->toBe('84832270898');

    // 20 ký tự mà dạng chuẩn hoá vẫn vừa cột: lưu được.
    $saved = iwaRecord($assistant, ['contact_phone' => '84912345678098765432'], [
        ['name' => 'Bên Số Dài', 'role' => PartyRole::Defendant, 'phone' => '84987654321012345678'],
    ]);

    expect($saved->contact_phone_normalized)->toBe('84912345678098765432')
        ->and($saved->parties()->sole()->phone_normalized)->toBe('84987654321012345678');
});

it('refuses to decline an anonymised record even while its status is still open', function () {
    $manager = iwaStaff(Role::Manager);
    $intake = iwaRecord($manager);
    $intake->forceFill(['anonymised_at' => now()])->save();

    expect(fn () => app(DeclineIntake::class)->handle($manager, $intake->fresh(), 'Lý do', false))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->status)->toBe(IntakeStatus::New);
});

// ---------------------------------------------------------------- fix vòng 1 (rà soát Task 3, C1)
//
// Khoá người gọi lại (`IntakeRequest::sameCallerIntakes()`) đọc ở lần gọi TRƯỚC: còn mở, cùng vai đã
// khai, cùng SĐT chuẩn hoá hoặc dấu băm CCCD. Gộp bản đó đi (nó rời `openForConflictCheck()`) hoặc đổi
// ba thông tin đó là bỏ khoá — trừ khi người làm là người xử lý Đỏ (`resolveConflict`).

/** Bản nguồn theo `$state`: Đỏ chưa xử lý; từ chối vì xung đột; từ chối vì lý do thường. */
function iwaSource(User $assistant, string $state, array $overrides = []): IntakeRequest
{
    if ($state === 'pending red') {
        return iwaRed($assistant, $overrides)->fresh();
    }

    $intake = iwaRecord($assistant, $overrides);

    $state === 'declined for a conflict'
        ? app(DeclineIntake::class)->handle(iwaStaff(Role::Manager), $intake, 'Xung đột', true)
        : app(DeclineIntake::class)->handle($assistant, $intake, 'Ngoài lĩnh vực', false);

    return $intake->fresh();
}

it('refuses an assistant the merge of a record that locks repeat calls, or of a declined one, into a record that would not catch the same callbacks', function (string $state, array $source, array $target) {
    $assistant = iwaStaff();
    // Bản đích ghi TRƯỚC: nó không bị khoá theo bản nguồn ở lần ghi của nó.
    $target = iwaRecord($assistant, $target);
    $source = iwaSource($assistant, $state, $source);
    $statusBefore = $source->status;

    expect(fn () => app(MergeIntake::class)->handle($assistant, $source, $target->fresh()))
        ->toThrow(ValidationException::class, __('intake.errors.merge_drops_caller'));

    expect($source->fresh()->status)->toBe($statusBefore)
        ->and($source->fresh()->merged_into_id)->toBeNull()
        ->and(Activity::query()->where('event', 'intake_merged')->exists())->toBeFalse();
})->with([
    'pending red → another phone' => ['pending red', [], ['contact_phone' => '0901000002']],
    'pending red → same phone, another role' => ['pending red', [], ['contact_role' => PartyRole::Related]],
    'pending red → same phone, no id number' => ['pending red', ['contact_id_number' => '079123456789'], []],
    'pending red → same phone, another id number' => ['pending red', ['contact_id_number' => '079123456789'], ['contact_id_number' => '079000000001']],
    'pending red → same id number, another phone' => ['pending red', ['contact_id_number' => '079123456789'], ['contact_phone' => '0901000002', 'contact_id_number' => '079123456789']],
    'declined for a conflict → another phone' => ['declined for a conflict', [], ['contact_phone' => '0901000002']],
    'declined, ordinary → another phone' => ['declined, ordinary', [], ['contact_phone' => '0901000002']],
]);

it('lets an assistant merge such a record into a record that catches the same callbacks', function (string $state, array $source, array $target) {
    $assistant = iwaStaff();
    $target = iwaRecord($assistant, $target);
    $source = iwaSource($assistant, $state, $source);

    app(MergeIntake::class)->handle($assistant, $source, $target->fresh());

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($source->fresh()->merged_into_id)->toBe($target->id);
})->with([
    'pending red → same phone (typed otherwise) and role' => ['pending red', [], ['contact_phone' => '+84 832 270 898']],
    'pending red → same phone, role and id number' => ['pending red', ['contact_id_number' => '079123456789'], ['contact_id_number' => '079123456789']],
    'pending red with phone only → same phone, target also has an id number' => ['pending red', [], ['contact_id_number' => '079000000001']],
    'pending red with id number only → same id number, target also has a phone' => ['pending red', ['contact_phone' => null, 'contact_email' => 'a@example.com', 'contact_id_number' => '079123456789'], ['contact_phone' => '0901000002', 'contact_id_number' => '079123456789']],
    'pending red with no declared role → another phone' => ['pending red', ['contact_role' => null], ['contact_phone' => '0901000002']],
    'pending red with neither phone nor id number → another phone' => ['pending red', ['contact_phone' => null, 'contact_email' => 'a@example.com'], ['contact_phone' => '0901000002']],
    'pending red with neither phone nor id number → another phone and role' => ['pending red', ['contact_phone' => null, 'contact_email' => 'a@example.com'], ['contact_phone' => '0901000002', 'contact_role' => PartyRole::Related]],
    'declined for a conflict → same phone and role' => ['declined for a conflict', [], []],
    'declined, ordinary → same phone and role' => ['declined, ordinary', [], []],
]);

it('lets a manager or an admin merge a record that locks repeat calls into any record, not a lawyer', function (Role $role, bool $allowed) {
    $actor = iwaStaff($role);
    $source = iwaSource($actor, 'declined for a conflict');
    $target = iwaRecord($actor, ['contact_phone' => '0901000002']);

    $call = fn () => app(MergeIntake::class)->handle($actor, $source, $target);

    if ($allowed) {
        $call();
        expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
            ->and($target->fresh()->hasUnresolvedRed())->toBeTrue();
    } else {
        expect($call)->toThrow(ValidationException::class, __('intake.errors.merge_drops_caller'));
        expect($source->fresh()->status)->toBe(IntakeStatus::Declined);
    }
})->with([
    'manager' => [Role::Manager, true],
    'admin' => [Role::Admin, true],
    'lawyer' => [Role::Lawyer, false],
]);

it('refuses any identity edit of a declined record, to anyone, whatever the reason it was declined', function (string $state, Role $role) {
    $assistant = iwaStaff();
    $intake = iwaSource($assistant, $state);
    $actor = $role === Role::Assistant ? $assistant : iwaStaff($role);

    expect(fn () => app(UpdateIntakeIdentity::class)->handle($actor, $intake, iwaIdentity($intake, ['contact_name' => 'Đổi Tên']), []))
        ->toThrow(ValidationException::class, __('intake.errors.identity_declined'));

    expect($intake->fresh()->contact_name)->toBe('Người Gọi Mẫu')
        ->and(Activity::query()->where('event', 'intake_identity_updated')->exists())->toBeFalse();
})->with([
    'ordinary, by the assistant who recorded it' => ['declined, ordinary', Role::Assistant],
    'conflict, by the assistant who recorded it' => ['declined for a conflict', Role::Assistant],
    'conflict, by a manager' => ['declined for a conflict', Role::Manager],
]);

it('refuses an assistant a change of the phone, ID number or role of a record with a pending red', function (array $edit, string $field) {
    $assistant = iwaStaff();
    $intake = iwaRed($assistant, ['contact_id_number' => '079123456789'])->fresh();

    try {
        app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, $edit), iwaParties($intake));
        $this->fail('expected a validation error');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([$field => [__('intake.errors.caller_keys_locked')]]);
    }

    $fresh = $intake->fresh();

    expect($fresh->contact_phone_normalized)->toBe('84832270898')
        ->and($fresh->contact_id_number_hash)->toBe(Normalizer::idNumberHash('079123456789'))
        ->and($fresh->contact_role)->toBe(PartyRole::Plaintiff);
})->with([
    'phone changed' => [['contact_phone' => '0901000002'], 'contact_phone'],
    'phone erased' => [['contact_phone' => null, 'contact_email' => 'a@example.com'], 'contact_phone'],
    'id number changed' => [['contact_id_number' => '079000000001'], 'contact_id_number'],
    'role changed' => [['contact_role' => PartyRole::Related], 'contact_role'],
    'role erased' => [['contact_role' => null], 'contact_role'],
]);

it('lets an assistant add an identifier, retype the same phone, or change anything else on a record with a pending red', function (array $source, array $edit, string $column, mixed $expected) {
    $assistant = iwaStaff();
    $intake = iwaRed($assistant, $source)->fresh();

    app(UpdateIntakeIdentity::class)->handle($assistant, $intake, iwaIdentity($intake, $edit), iwaParties($intake));

    $expected = $column === 'contact_id_number_hash' ? Normalizer::idNumberHash($expected) : $expected;

    expect($intake->fresh()->{$column})->toBe($expected)
        ->and($intake->fresh()->hasUnresolvedRed())->toBeTrue();
})->with([
    'same phone typed otherwise' => [[], ['contact_phone' => '+84 832 270 898'], 'contact_phone', '+84 832 270 898'],
    'id number added' => [[], ['contact_id_number' => '079123456789'], 'contact_id_number_hash', '079123456789'],
    'phone added' => [['contact_phone' => null, 'contact_email' => 'a@example.com'], ['contact_phone' => '0832270898'], 'contact_phone_normalized', '84832270898'],
    'role declared' => [['contact_role' => null], ['contact_role' => PartyRole::Plaintiff], 'contact_role', PartyRole::Plaintiff],
    'name changed' => [[], ['contact_name' => 'Tên Khác'], 'contact_name', 'Tên Khác'],
]);

it('lets a manager or an admin change the phone, ID number and role of a record with a pending red', function (Role $role) {
    $intake = iwaRed(iwaStaff(), ['contact_id_number' => '079123456789'])->fresh();

    app(UpdateIntakeIdentity::class)->handle(iwaStaff($role), $intake, iwaIdentity($intake, [
        'contact_phone' => '0901000002', 'contact_id_number' => '079000000001', 'contact_role' => PartyRole::Related,
    ]), iwaParties($intake));

    $fresh = $intake->fresh();

    expect($fresh->contact_phone_normalized)->toBe('84901000002')
        ->and($fresh->contact_id_number_hash)->toBe(Normalizer::idNumberHash('079000000001'))
        ->and($fresh->contact_role)->toBe(PartyRole::Related);
})->with(['manager' => Role::Manager, 'admin' => Role::Admin]);
