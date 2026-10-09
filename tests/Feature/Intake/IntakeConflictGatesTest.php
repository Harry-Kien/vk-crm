<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\IntakeSummaryGate;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Intake\PrivacyNotice;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function gateStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Một khách hàng hiện hữu, đã có vụ, với SĐT và CCCD biết trước. */
function gateExistingClient(string $phone = '0912000111', string $idNumber = '079012345678', string $name = 'Nguyễn Văn Hùng'): Client
{
    $client = Client::factory()->create(['phone' => $phone, 'id_number' => $idNumber, 'name' => $name]);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    return $client;
}

/** Ghi nhận một lần liên hệ ĐỦ định danh (Xanh nếu không trùng ai). */
function gateRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Trần Thị Lan',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** Bên đối lập mang SĐT của khách hiện hữu ở vai đối lập: Đỏ. */
function gateRedParties(string $phone = '0912000111'): array
{
    return [['name' => 'Bị đơn trùng khách', 'role' => PartyRole::Defendant, 'phone' => $phone]];
}

it('opens the story for a green, fully identified contact only after the privacy notice is recorded', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);

    expect(IntakeSummaryGate::blockers($intake))->toBe([IntakeSummaryBlocker::PrivacyNotice]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Câu chuyện'))
        ->toThrow(ValidationException::class);
    expect($intake->fresh()->summary)->toBeNull();

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    app(UpdateIntakeSummary::class)->handle($actor, $intake, '  Câu chuyện của tôi  ');

    expect($intake->fresh()->summary)->toBe('Câu chuyện của tôi');
});

it('records the privacy notice with its version, when and by whom, and refuses an unticked box', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);

    expect(fn () => app(RecordPrivacyNotice::class)->handle($actor, $intake, false))
        ->toThrow(ValidationException::class);
    expect($intake->fresh()->privacy_notice_acknowledged_at)->toBeNull();

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    $fresh = $intake->fresh();
    $row = Activity::query()->where('event', 'intake_privacy_notice_recorded')->sole();

    expect($fresh->privacy_notice_version)->toBe(PrivacyNotice::version())
        ->and($fresh->privacy_notice_acknowledged_at)->not->toBeNull()
        ->and($fresh->privacy_notice_recorded_by)->toBe($actor->id)
        ->and($row->causer->is($actor))->toBeTrue()
        ->and($row->properties->all())->toBe(['version' => PrivacyNotice::version()]);
});

it('does not record the privacy notice twice for the same version, but records a new version again', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    $firstAt = $intake->fresh()->privacy_notice_acknowledged_at;

    $this->travel(5)->minutes();
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(Activity::query()->where('event', 'intake_privacy_notice_recorded')->count())->toBe(1)
        ->and($intake->fresh()->privacy_notice_acknowledged_at->equalTo($firstAt))->toBeTrue();

    $intake->forceFill(['privacy_notice_version' => 'ban-cu'])->save();
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(Activity::query()->where('event', 'intake_privacy_notice_recorded')->count())->toBe(2)
        ->and($intake->fresh()->privacy_notice_version)->toBe(PrivacyNotice::version());
});

it('keeps the story locked at red and never saves it, even when the caller insists', function () {
    gateExistingClient();
    $actor = gateStaff();
    $intake = gateRecord($actor, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect($intake->conflict_level)->toBe(ConflictLevel::Red)
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Cố tình gửi thẳng'))
        ->toThrow(ValidationException::class);
    // Cả người có quyền xem mọi bản ghi cũng không ghi được câu chuyện vào một bản Đỏ chưa xử lý.
    expect(fn () => app(UpdateIntakeSummary::class)->handle(gateStaff(Role::Manager), $intake, 'Quản lý cố tình'))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->summary)->toBeNull();
});

it('does not tell the person typing why the result is red, beyond the blocker sentence', function () {
    gateExistingClient(name: 'Khách Hàng Bí Mật Của Văn Phòng');
    $actor = gateStaff();
    $intake = gateRecord($actor, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    try {
        app(UpdateIntakeSummary::class)->handle($actor, $intake, 'x');
        $this->fail('phải bị chặn');
    } catch (ValidationException $exception) {
        $message = implode(' ', $exception->errors()['summary']);

        expect($message)->toContain(IntakeSummaryBlocker::ConflictRed->label())
            ->and($message)->not->toContain('Khách Hàng Bí Mật')
            ->and($message)->not->toContain('0912000111');
    }
});

it('lets a manager override red with a reason, which opens the story', function () {
    gateExistingClient();
    $assistant = gateStaff();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($assistant, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, '  Đã hỏi ý kiến chủ nhiệm, bên kia đã rút khỏi vụ cũ  ');

    $fresh = $intake->fresh();

    expect($fresh->conflict_overridden_by)->toBe($manager->id)
        ->and($fresh->conflict_override_reason)->toBe('Đã hỏi ý kiến chủ nhiệm, bên kia đã rút khỏi vụ cũ')
        ->and(IntakeSummaryGate::isOpen($fresh))->toBeTrue();

    app(UpdateIntakeSummary::class)->handle($assistant, $intake, 'Câu chuyện sau khi được ghi đè');

    expect($intake->fresh()->summary)->toBe('Câu chuyện sau khi được ghi đè');
});

it('refuses the override to an assistant, a lawyer and an accountant, whatever the reason', function () {
    gateExistingClient();
    $assistant = gateStaff();
    $intake = gateRecord($assistant, [], gateRedParties());

    foreach ([$assistant, gateStaff(Role::Lawyer), gateStaff(Role::Accountant)] as $person) {
        expect(fn () => app(ResolveIntakeRedConflict::class)->handle($person, $intake, 'Lý do rất hợp lý và đủ dài'))
            ->toThrow(AuthorizationException::class);
    }

    expect($intake->fresh()->conflict_overridden_by)->toBeNull();
});

it('refuses an override without a reason, and one that is too long', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties());

    foreach (['', '   ', str_repeat('a', 2001)] as $reason) {
        expect(fn () => app(ResolveIntakeRedConflict::class)->handle($manager, $intake, $reason))
            ->toThrow(ValidationException::class);
    }

    expect($intake->fresh()->conflict_overridden_by)->toBeNull()
        ->and($intake->fresh()->conflict_override_reason)->toBeNull();
});

it('refuses to override a result that is not red', function () {
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager);

    expect(fn () => app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Không có gì để ghi đè'))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->conflict_overridden_by)->toBeNull();
});

it('writes the override reason into the override row, against the manager, as OpenMatter does (SPEC §6.10)', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties());

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, '  Lý do nhạy cảm về khách hàng khác  ');

    $row = Activity::query()->where('event', 'intake_conflict_overridden')->sole();

    expect($row->causer->is($manager))->toBeTrue()
        ->and($row->properties->get('override_reason'))->toBe('Lý do nhạy cảm về khách hàng khác')
        ->and($row->properties->get('level'))->toBe('red')
        ->and($row->properties->get('confirmed_pairs'))->not->toBeEmpty();
});

it('keeps the reason of every override in the log, even after a later re-run clears the column', function () {
    gateExistingClient(phone: '0912000111', idNumber: '079012345678');
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties('0912000111'));
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Ghi đè lần đầu');

    // Một khách hiện hữu THỨ HAI cũng mang số đó: khớp Đỏ mới, ghi đè cũ hết hiệu lực và cột về null.
    gateExistingClient(phone: '0912000111', idNumber: '079099999999', name: 'Khách thứ hai');
    app(RerunIntakeConflictCheck::class)->handle($manager, $intake->fresh());

    expect($intake->fresh()->conflict_override_reason)->toBeNull();

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake->fresh(), 'Ghi đè lần hai');

    // Lịch sử chỉ thêm, không sửa: lý do của MỌI lần ghi đè còn trong nhật ký.
    expect(Activity::query()->where('event', 'intake_conflict_overridden')->orderBy('id')->get()
        ->map(fn (Activity $row) => $row->properties->get('override_reason'))->all())
        ->toBe(['Ghi đè lần đầu', 'Ghi đè lần hai']);
});

it('refuses to acknowledge red: only an override resolves it', function () {
    gateExistingClient();
    $actor = gateStaff();
    $intake = gateRecord($actor, [], gateRedParties());

    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Red))
        ->toThrow(ConflictBlocked::class);

    expect($intake->fresh()->conflict_acknowledged_by)->toBeNull();
});

it('keeps the story locked at yellow by name until acknowledged, and opens it after', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'lê thị hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    // Khớp chỉ theo tên không bao giờ Đỏ — chỉ Vàng.
    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictAcknowledgement]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Chưa xác nhận'))
        ->toThrow(ValidationException::class);

    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow);

    $fresh = $intake->fresh();

    expect($fresh->conflict_acknowledged_by)->toBe($actor->id)
        ->and($fresh->conflict_acknowledged_at)->not->toBeNull()
        ->and(IntakeSummaryGate::isOpen($fresh))->toBeTrue();

    app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Đã xác nhận nên ghi được');
    expect($intake->fresh()->summary)->toBe('Đã xác nhận nên ghi được');
});

it('does not block again a match that was already acknowledged (R13c) — but a new match does', function () {
    $matterA = Matter::factory()->create();
    MatterParty::factory()->for($matterA)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow);

    // Chạy lại: khớp đã xác nhận vẫn hiện, nhưng không chặn lại.
    $again = app(RerunIntakeConflictCheck::class)->handle($actor, $intake);

    expect($again->level)->toBe(ConflictLevel::Green)
        ->and($again->matches)->toBeEmpty()
        ->and($again->confirmedMatches)->toHaveCount(1)
        ->and(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    // Một khớp MỚI (cùng tên, ở một vụ khác) phải được nhìn lại: xác nhận cũ không che nó.
    $matterB = Matter::factory()->create();
    MatterParty::factory()->for($matterB)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $third = app(RerunIntakeConflictCheck::class)->handle($actor, $intake);

    expect($third->level)->toBe(ConflictLevel::Yellow)
        ->and($third->matches)->toHaveCount(1)
        ->and($third->confirmedMatches)->toHaveCount(1)
        ->and($intake->fresh()->conflict_acknowledged_by)->toBeNull()
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictAcknowledgement]);
});

it('does not let a yellow acknowledgement cover a red match that appears later for the same pair', function () {
    // Bất biến C1: xác nhận Vàng (khớp tên) không che một Đỏ MỚI của cùng bên phía mình.
    $client = Client::factory()->create(['phone' => '0912000111', 'name' => 'Nguyễn Văn Hùng']);
    $matter = Matter::factory()->create();

    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], [['name' => 'Nguyễn Văn Hùng', 'role' => PartyRole::Defendant]]);

    // Chưa có ai trùng: thiếu định danh cho bên đối lập nhưng người liên hệ đủ — Xanh.
    expect($intake->conflict_level)->toBe(ConflictLevel::Green);

    MatterParty::factory()->for($matter)->ourClient($client)->create();

    // Bây giờ khớp tên với khách hiện hữu ở vai đối lập, KHÔNG có SĐT: chỉ Vàng.
    $yellow = app(RerunIntakeConflictCheck::class)->handle($manager, $intake);
    expect($yellow->level)->toBe(ConflictLevel::Yellow);
    app(AcknowledgeIntakeConflict::class)->handle($manager, $intake, ConflictLevel::Yellow);

    // Người nhập bổ sung SĐT của bên đối lập — cùng bên, tầng khớp mạnh hơn, và nay là Đỏ.
    $party = $intake->parties()->first();
    $party->identify(null, '0912000111')->save();

    $red = app(RerunIntakeConflictCheck::class)->handle($manager, $intake);

    expect($red->level)->toBe(ConflictLevel::Red)
        ->and($intake->fresh()->conflict_acknowledged_by)->toBeNull();
});

it('sends a green result with a missing identity through the acknowledgement gate, like yellow', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor, ['contact_phone' => null, 'contact_role' => null]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect($intake->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->conflict_result['incomplete_parties'])->toBe(['Trần Thị Lan'])
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictAcknowledgement]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Chưa xác nhận'))
        ->toThrow(ValidationException::class);

    // Xác nhận phải khớp ĐÚNG mức đang hiện (Xanh, không phải Vàng).
    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow))
        ->toThrow(ConflictAcknowledgementRequired::class);

    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Green);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();
});

it('refuses an acknowledgement when there is nothing to acknowledge', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);

    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Green))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->conflict_acknowledged_by)->toBeNull();
});

it('refuses an acknowledgement given for a level that has changed since', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);

    // Người nhập đã thấy Vàng nhưng bấm với mức Xanh (hoặc mức cũ): không hợp lệ.
    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Green))
        ->toThrow(ConflictAcknowledgementRequired::class);

    expect($intake->fresh()->conflict_acknowledged_by)->toBeNull();
});

it('closes the gate when the identity was edited after the check, until the check is run again', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    // Sửa danh tính thẳng vào dòng (như một form sửa không đi qua kiểm tra): kết quả Xanh cũ hết giá trị.
    $intake->identify(null, '0999888777')->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), 'Danh tính đã đổi'))
        ->toThrow(ValidationException::class);

    app(RerunIntakeConflictCheck::class)->handle($actor, $intake->fresh());

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();
});

it('closes the gate when an opposing party was changed after the check', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => '0955000111']]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    $intake->parties()->first()->identify(null, '0955000222')->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);
});

it('clears an acknowledgement when the identity changed, because it covered another identity', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);
    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow);
    expect($intake->fresh()->conflict_acknowledged_by)->toBe($actor->id);

    $intake->parties()->first()->identify(null, '0977000999')->save();
    app(RerunIntakeConflictCheck::class)->handle($actor, $intake->fresh());

    expect($intake->fresh()->conflict_acknowledged_by)->toBeNull();
});

it('keeps the acknowledgement across a re-run that finds nothing new and changes nothing', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor, ['contact_phone' => null, 'contact_role' => null]);
    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Green);

    app(RerunIntakeConflictCheck::class)->handle($actor, $intake->fresh());
    app(RerunIntakeConflictCheck::class)->handle($actor, $intake->fresh());

    expect($intake->fresh()->conflict_acknowledged_by)->toBe($actor->id);
});

it('lets an override still cover the acknowledgement gate after a harmless re-run', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    // Một bên chỉ có tên: lần chạy lại vẫn 'thiếu định danh', tức cổng xác nhận sẽ đóng nếu ghi đè
    // không che nó.
    $intake = gateRecord($manager, [], [...gateRedParties(), ['name' => 'Bên chỉ có tên', 'role' => PartyRole::Related]]);
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Ghi đè có lý do đầy đủ');

    app(RerunIntakeConflictCheck::class)->handle($manager, $intake->fresh());

    $fresh = $intake->fresh();

    // Chạy lại: khớp Đỏ đã ghi đè thành "đã xác nhận", nên mức mới là Xanh — nhưng cổng vẫn mở.
    expect($fresh->conflict_level)->toBe(ConflictLevel::Green)
        ->and($fresh->conflict_result['incomplete_parties'])->toBe(['Bên chỉ có tên'])
        ->and($fresh->conflict_acknowledged_by)->toBeNull()
        ->and($fresh->conflict_overridden_by)->toBe($manager->id)
        ->and(IntakeSummaryGate::isOpen($fresh))->toBeTrue();
});

it('gives an acknowledgement row that carries only levels and pair signatures', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);
    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow);

    $row = Activity::query()->where('event', 'intake_conflict_acknowledged')->sole();

    expect($row->causer->is($actor))->toBeTrue()
        ->and(array_keys($row->properties->all()))->toEqualCanonicalizing(['level', 'incomplete_parties', 'confirmed_pairs'])
        ->and($row->properties->get('confirmed_pairs')[0]['level'])->toBe('yellow')
        ->and($row->properties->get('confirmed_pairs')[0]['pair_key'])->toStartWith('i:')
        ->and(json_encode($row->properties, JSON_UNESCAPED_UNICODE))->not->toContain('Lê Thị Hoa');
});

it('lets only someone who can see the record write, acknowledge or re-run', function () {
    $owner = gateStaff();
    $other = gateStaff();
    $accountant = gateStaff(Role::Accountant);
    $intake = gateRecord($owner);

    foreach ([$other, $accountant] as $stranger) {
        expect(fn () => app(RecordPrivacyNotice::class)->handle($stranger, $intake, true))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateIntakeSummary::class)->handle($stranger, $intake, 'x'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RerunIntakeConflictCheck::class)->handle($stranger, $intake))->toThrow(AuthorizationException::class)
            ->and(fn () => app(AcknowledgeIntakeConflict::class)->handle($stranger, $intake, ConflictLevel::Green))->toThrow(AuthorizationException::class);
    }

    // Được giao thì thấy.
    $intake->forceFill(['assigned_to' => $other->id])->save();
    app(RecordPrivacyNotice::class)->handle($other, $intake->fresh(), true);
    expect($intake->fresh()->privacy_notice_recorded_by)->toBe($other->id);
});

it('refuses every write on a record that was anonymised or merged', function () {
    $actor = gateStaff();

    // Cả hai bản đều Xanh đủ định danh VÀ đã ghi nhận thông báo TRƯỚC khi đóng: cổng ô câu chuyện
    // đang mở, nên lời từ chối dưới đây chỉ có thể đến từ việc bản ghi đã đóng — không phải từ cổng.
    $anonymised = gateRecord($actor, ['contact_name' => 'Đã ẩn danh']);
    app(RecordPrivacyNotice::class)->handle($actor, $anonymised, true);
    $anonymised->fresh()->forceFill(['anonymised_at' => now()])->save();

    $merged = gateRecord($actor, ['contact_name' => 'Đã gộp']);
    app(RecordPrivacyNotice::class)->handle($actor, $merged, true);
    $merged->fresh()->forceFill(['status' => IntakeStatus::Merged])->save();

    $closedMessage = fn (callable $call): ?string => rescue(function () use ($call) {
        $call();

        return null;
    }, fn (Throwable $e) => $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e::class, false);

    foreach ([$anonymised->fresh(), $merged->fresh()] as $closed) {
        expect($closedMessage(fn () => app(RecordPrivacyNotice::class)->handle($actor, $closed, true)))->toBe(__('intake.errors.record_closed'))
            ->and($closedMessage(fn () => app(UpdateIntakeSummary::class)->handle($actor, $closed, 'x')))->toBe(__('intake.errors.record_closed'))
            ->and($closedMessage(fn () => app(RerunIntakeConflictCheck::class)->handle($actor, $closed)))->toBe(__('intake.errors.record_closed'))
            ->and($closedMessage(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $closed, ConflictLevel::Green)))->toBe(__('intake.errors.record_closed'))
            ->and($closed->fresh()->summary)->toBeNull();
    }
});

it('keeps the story out of the activity log and takes the column length into account', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Nội dung câu chuyện rất riêng tư');

    $row = Activity::query()->where('event', 'intake_summary_updated')->sole();

    expect($row->properties->all())->toBe(['length' => mb_strlen('Nội dung câu chuyện rất riêng tư')])
        ->and($row->causer->is($actor))->toBeTrue();

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, str_repeat('a', 60001)))
        ->toThrow(ValidationException::class);

    // Rỗng nghĩa là xoá câu chuyện.
    app(UpdateIntakeSummary::class)->handle($actor, $intake, "  \n ");
    expect($intake->fresh()->summary)->toBeNull();
});

it('reads the gate from the locked row, so a stale in-memory copy cannot open the story', function () {
    gateExistingClient();
    $actor = gateStaff();
    $intake = gateRecord($actor, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    // Bản trong bộ nhớ giả vờ như đã được ghi đè; dòng thật thì chưa.
    $stale = $intake->fresh();
    $stale->conflict_overridden_by = gateStaff(Role::Manager)->id;
    $stale->conflict_override_reason = 'Chỉ có trong bộ nhớ';

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $stale, 'Cố mở bằng bản cũ'))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->summary)->toBeNull();
});

it('closes the gate for a record that was never checked', function () {
    $actor = gateStaff();
    $intake = IntakeRequest::factory()->create([
        'created_by' => $actor->id, 'privacy_notice_acknowledged_at' => now(), 'privacy_notice_version' => 'x',
    ]);

    expect(IntakeSummaryGate::blockers($intake))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);

    expect(fn () => app(UpdateIntakeSummary::class)->handle($actor, $intake, 'Chưa kiểm tra'))
        ->toThrow(ValidationException::class);
});

it('closes the gate when the stored level is missing even though a check time is recorded', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    $intake->forceFill(['conflict_level' => null])->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);
});

it('keeps red locked when an override has a person but no reason', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);

    $intake->forceFill(['conflict_overridden_by' => $manager->id, 'conflict_override_reason' => '  '])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    $intake->forceFill(['conflict_overridden_by' => null, 'conflict_override_reason' => 'Có lý do nhưng không có người'])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);
});

it('closes the gate when any single piece of identity changes after the check', function (string $target, string $column, mixed $value) {
    $actor = gateStaff();
    $intake = gateRecord($actor, ['contact_id_number' => '079012345678'], [
        ['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => '0955000111', 'id_number' => '001099887766'],
    ]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    $model = $target === 'party' ? $intake->parties()->first() : $intake;
    $model->setAttribute($column, $value);
    $model->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);
})->with([
    'contact role' => ['contact', 'contact_role', PartyRole::Defendant],
    'contact name' => ['contact', 'contact_name', 'Người Khác Hẳn'],
    'contact phone' => ['contact', 'contact_phone_normalized', '84999000111'],
    'contact id hash' => ['contact', 'contact_id_number_hash', str_repeat('a', 64)],
    'party role' => ['party', 'role', PartyRole::Related],
    'party name' => ['party', 'name', 'Bên Khác Hẳn'],
    'party phone' => ['party', 'phone_normalized', '84999000222'],
    'party id hash' => ['party', 'id_number_hash', str_repeat('b', 64)],
]);

it('accepts a summary exactly at the byte limit and an override reason exactly at its limit', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, str_repeat('a', 2000));
    app(UpdateIntakeSummary::class)->handle($manager, $intake, str_repeat('b', 60000));

    expect(strlen($intake->fresh()->summary))->toBe(60000)
        ->and(mb_strlen($intake->fresh()->conflict_override_reason))->toBe(2000);
});

it('refuses an acknowledgement given for a yellow that turned red meanwhile, and keeps the evidence of the re-check', function () {
    $matterA = Matter::factory()->create();
    MatterParty::factory()->for($matterA)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $intake = gateRecord($actor, [], [['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);

    // Trong lúc người nhập đang nhìn màn hình Vàng, dữ liệu văn phòng đổi: nay Đỏ.
    $client = Client::factory()->create(['phone' => '0977000111']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Yellow))
        ->toThrow(ConflictBlocked::class);

    // Bằng chứng của lần chạy lại vẫn còn dù xác nhận bị từ chối (transaction riêng, đã commit).
    expect($intake->fresh()->conflict_level)->toBe(ConflictLevel::Red)
        ->and($intake->fresh()->conflict_acknowledged_by)->toBeNull()
        ->and(Activity::query()->where('event', 'conflict_check_run')->count())->toBe(2);
});

it('clears an override when a NEW red match appears, and keeps every field cleared', function () {
    gateExistingClient(phone: '0912000111', idNumber: '079012345678');
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties('0912000111'));
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Ghi đè lần đầu');
    $intake->refresh()->forceFill(['conflict_acknowledged_by' => $manager->id, 'conflict_acknowledged_at' => now()])->save();

    // Một khách hiện hữu THỨ HAI cũng mang số đó, ở một vụ khác: khớp Đỏ mới, chưa ai nhìn.
    gateExistingClient(phone: '0912000111', idNumber: '079099999999', name: 'Khách thứ hai');

    $result = app(RerunIntakeConflictCheck::class)->handle($manager, $intake->fresh());
    $fresh = $intake->fresh();

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($fresh->conflict_overridden_by)->toBeNull()
        ->and($fresh->conflict_override_reason)->toBeNull()
        ->and($fresh->conflict_acknowledged_by)->toBeNull()
        ->and($fresh->conflict_acknowledged_at)->toBeNull()
        ->and(IntakeSummaryGate::blockers($fresh))->toBe([IntakeSummaryBlocker::ConflictRed]);
});

it('says only that the check is stale when the identity changed on a red record, not that it is red', function () {
    gateExistingClient();
    $actor = gateStaff();
    $intake = gateRecord($actor, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    $intake->parties()->first()->identify(null, '0955000222')->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);
});

it('does not ask for an acknowledgement after an override even when a party has no identity', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], [
        ...gateRedParties(),
        ['name' => 'Bên chỉ có tên', 'role' => PartyRole::Related],
    ]);
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Ghi đè có lý do');

    expect($intake->fresh()->conflict_result['incomplete_parties'])->toBe(['Bên chỉ có tên'])
        ->and(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();
});

it('clears an acknowledgement of a missing identity once the identity changes, even when the re-run finds no match', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor, ['contact_phone' => null, 'contact_role' => null]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    app(AcknowledgeIntakeConflict::class)->handle($actor, $intake, ConflictLevel::Green);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    // Đổi tên: vẫn thiếu định danh, vẫn không khớp ai — nhưng xác nhận cũ nói về một danh tính khác.
    $intake->fresh()->forceFill(['contact_name' => 'Tên Đã Sửa'])->save();
    $result = app(RerunIntakeConflictCheck::class)->handle($actor, $intake->fresh());
    $fresh = $intake->fresh();

    expect($result->matches)->toBeEmpty()
        ->and($fresh->conflict_acknowledged_by)->toBeNull()
        ->and($fresh->conflict_acknowledged_at)->toBeNull()
        ->and(IntakeSummaryGate::blockers($fresh))->toBe([IntakeSummaryBlocker::ConflictAcknowledgement]);
});

it('closes the gate when the check time is missing even though a level is stored', function () {
    $actor = gateStaff();
    $intake = gateRecord($actor);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();

    $intake->fresh()->forceFill(['conflict_checked_at' => null])->save();

    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictUnchecked]);
});

/**
 * R1 + M6.5 R13(g): ba cửa chạy lại kiểm tra (xác nhận Vàng, ghi đè Đỏ, chạy lại) cũng chạy dưới khoá
 * `conflict-check` — cùng khoá `OpenMatter` — chứ không chỉ lần ghi nhận đầu (test ở RecordIntakeTest).
 * Khoá đang bị giữ thì trả `ConflictCheckBusy` tiếng Việt, không lỗi 500, và không ghi gì: không dòng
 * `conflict_check_run`, không xác nhận, không ghi đè. Mỗi lời gọi chờ đủ 10 giây của `block(10)`.
 */
it('answers ConflictCheckBusy from the acknowledgement, the override and the re-run while the conflict lock is held, and writes nothing', function () {
    gateExistingClient();
    $assistant = gateStaff();
    $manager = gateStaff(Role::Manager);
    $red = gateRecord($assistant, [], gateRedParties());

    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);
    $yellow = gateRecord($assistant, ['contact_name' => 'Phạm Thu Trang', 'contact_phone' => '0833000111'], [
        ['name' => 'lê thị hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111'],
    ]);

    expect($red->conflict_level)->toBe(ConflictLevel::Red)
        ->and($yellow->conflict_level)->toBe(ConflictLevel::Yellow);

    $runsBefore = Activity::query()->where('event', 'conflict_check_run')->count();

    // Giữ khoá 120 giây, không phải 30 như các Action: ba lần chờ 10 giây nối nhau, và một khoá 30
    // giây sẽ tự hết hạn giữa lần chờ thứ ba (đo được: lần chạy lại khi đó lấy được khoá).
    $lock = Cache::store('database')->lock('conflict-check', 120);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(AcknowledgeIntakeConflict::class)->handle($assistant, $yellow, ConflictLevel::Yellow))
            ->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));
        expect(fn () => app(ResolveIntakeRedConflict::class)->handle($manager, $red, 'Đã hỏi ý kiến chủ nhiệm về vụ này'))
            ->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));
        expect(fn () => app(RerunIntakeConflictCheck::class)->handle($assistant, $yellow))
            ->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));
    } finally {
        $lock->release();
    }

    expect(Activity::query()->where('event', 'conflict_check_run')->count())->toBe($runsBefore)
        ->and($yellow->fresh()->conflict_acknowledged_by)->toBeNull()
        ->and($red->fresh()->conflict_overridden_by)->toBeNull();
});

/**
 * Fix vòng 1 (I2): Đỏ DÍNH. R1: "Đỏ … chỉ quản lý hoặc admin mở được, bằng một trong hai: từ chối (R8),
 * hoặc ghi đè kèm lý do bắt buộc". Một lần chạy lại ra Xanh sau khi sửa danh tính — gỡ bên đối lập,
 * sửa số — không phải một trong hai cách đó, kể cả khi quản lý tự chạy lại: chỉ ghi đè có lý do.
 */
it('keeps red locked after the identity is edited and the re-run comes out green, until a manager overrides it with a reason', function (string $edit) {
    gateExistingClient();
    $assistant = gateStaff();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($assistant, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($assistant, $intake, true);

    $party = $intake->parties()->first();
    $edit === 'removed' ? $party->delete() : $party->identify(null, '0955000999')->save();

    $rerun = app(RerunIntakeConflictCheck::class)->handle($assistant, $intake->fresh());

    expect($rerun->level)->toBe(ConflictLevel::Green)
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed])
        ->and(fn () => app(UpdateIntakeSummary::class)->handle($assistant, $intake->fresh(), 'Câu chuyện'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ResolveIntakeRedConflict::class)->handle($assistant, $intake->fresh(), 'Tôi đã sửa số'))
        ->toThrow(AuthorizationException::class);

    // Quản lý chạy lại cũng không mở: chỉ ghi đè có lý do (hoặc từ chối, Task 3).
    app(RerunIntakeConflictCheck::class)->handle($manager, $intake->fresh());
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake->fresh(), 'Trợ lý nhập nhầm số bên đối lập, đã gọi lại xác minh');

    $fresh = $intake->fresh();
    $row = Activity::query()->where('event', 'intake_conflict_overridden')->sole();

    expect($fresh->conflict_red_pending_since)->toBeNull()
        ->and(IntakeSummaryGate::isOpen($fresh))->toBeTrue()
        ->and($row->properties->get('level'))->toBe('green')
        ->and($row->properties->get('override_reason'))->toBe('Trợ lý nhập nhầm số bên đối lập, đã gọi lại xác minh');

    app(UpdateIntakeSummary::class)->handle($assistant, $intake, 'Câu chuyện sau khi quản lý xử lý');
    expect($intake->fresh()->summary)->toBe('Câu chuyện sau khi quản lý xử lý');
})->with(['opposing party removed' => 'removed', 'opposing phone corrected' => 'corrected']);

it('records since when a red is pending, and keeps that first time across later red re-runs', function () {
    gateExistingClient();
    $assistant = gateStaff();
    $intake = gateRecord($assistant, [], gateRedParties());
    $since = $intake->conflict_red_pending_since;

    expect($since)->not->toBeNull();

    $this->travel(2)->hours();
    $again = app(RerunIntakeConflictCheck::class)->handle($assistant, $intake->fresh());

    expect($again->level)->toBe(ConflictLevel::Red)
        ->and($intake->fresh()->conflict_red_pending_since->equalTo($since))->toBeTrue();
});

it('keeps a red result locked when the pending marker is missing, unless a manager override with a reason stands', function () {
    gateExistingClient();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($manager, [], gateRedParties());
    app(RecordPrivacyNotice::class)->handle($manager, $intake, true);

    // Một dòng Đỏ không mang dấu "đang chờ" (ghi trước khi có cột, hoặc sửa tay): mức Đỏ đã lưu vẫn khoá
    // — trừ khi có một ghi đè còn hiệu lực, tức có CẢ người ghi đè LẪN lý do.
    $intake->fresh()->forceFill(['conflict_red_pending_since' => null])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    $intake->fresh()->forceFill(['conflict_overridden_by' => $manager->id, 'conflict_override_reason' => '  '])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    $intake->fresh()->forceFill(['conflict_overridden_by' => null, 'conflict_override_reason' => 'Lý do không người'])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictRed]);

    $intake->fresh()->forceFill(['conflict_overridden_by' => $manager->id, 'conflict_override_reason' => 'Đủ người và lý do'])->save();
    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();
});

it('does not let a mass assignment clear the pending red marker', function () {
    gateExistingClient();
    $assistant = gateStaff();
    $intake = gateRecord($assistant, [], gateRedParties());

    $intake->fresh()->fill(['conflict_red_pending_since' => null])->save();

    expect($intake->fresh()->conflict_red_pending_since)->not->toBeNull();
});

it('lets only an override that stands, with a person and a reason, cover the acknowledgement gate', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $actor = gateStaff();
    $manager = gateStaff(Role::Manager);
    $intake = gateRecord($actor, [], [['name' => 'lê thị hoa', 'role' => PartyRole::Defendant, 'phone' => '0977000111']]);
    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow);

    // Một "ghi đè" thiếu lý do (sửa tay) không phải ghi đè (`IntakeRequest::hasConflictOverride()`): nó
    // không che được bước xác nhận Vàng.
    $intake->fresh()->forceFill(['conflict_overridden_by' => $manager->id, 'conflict_override_reason' => '  '])->save();
    expect(IntakeSummaryGate::blockers($intake->fresh()))->toBe([IntakeSummaryBlocker::ConflictAcknowledgement]);

    $intake->fresh()->forceFill(['conflict_override_reason' => 'Đã xem cả hai bên'])->save();
    expect(IntakeSummaryGate::isOpen($intake->fresh()))->toBeTrue();
});
