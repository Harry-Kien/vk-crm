<?php

use App\Actions\AddMatterParty;
use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\IntakeSummaryGate;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\OpenMatter;
use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\IntakeSummaryBlocker;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ssStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

function ssRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Trần Thị Lan',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

/** Chạy `RunConflictCheck` như `OpenMatter` làm: các bên chưa lưu, không chủ thể. */
function ssCheckParties(array $parties): ConflictCheckResult
{
    return app(RunConflictCheck::class)->handle(collect($parties));
}

function ssParty(PartyRole $role, string $name, ?string $phone = null, ?string $idNumber = null, bool $ours = false): MatterParty
{
    return (new MatterParty(['role' => $role, 'name' => $name, 'is_our_client' => $ours]))->identify($idNumber, $phone);
}

it('makes A visible to B: A tells the story, B calls and names A as the opposing party — yellow, labelled, no story', function () {
    $assistant = ssStaff();

    // A gọi, kể chuyện, được ghi đầy đủ (Xanh → mở ô câu chuyện).
    $a = ssRecord($assistant, ['contact_name' => 'Nguyễn Văn An', 'contact_phone' => '0901111222', 'contact_role' => PartyRole::Plaintiff]);
    app(RecordPrivacyNotice::class)->handle($assistant, $a, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $a, 'Bí mật: ông An kể về vụ lừa đảo của người thân.');

    // B gọi, và khai A (qua SĐT) là bên đối lập.
    $result = app(RecordIntake::class)->handle($assistant, [
        'contact_name' => 'Lê Văn Bình', 'contact_phone' => '0903333444', 'contact_role' => PartyRole::Defendant, 'source' => 'phone',
    ], [['name' => 'Người tên khác hẳn', 'role' => PartyRole::Plaintiff, 'phone' => '+84 901 111 222']]);

    expect($result->conflict->level)->toBe(ConflictLevel::Yellow)
        ->and($result->conflict->matches)->toHaveCount(1);

    $match = $result->conflict->matches->first();

    expect($match->matterCode)->toBe($a->code)
        ->and($match->matterTypeName)->toBe('Đã liên hệ văn phòng ngày '.now()->format('d/m/Y'))
        ->and($match->tier)->toBe(ConflictMatchTier::Phone)
        ->and($match->level)->toBe(ConflictLevel::Yellow)
        ->and($match->contactedOn)->toBe(now()->format('Y-m-d'));

    // Không lộ câu chuyện của A ở bất kỳ chỗ nào B nhìn thấy.
    $everything = json_encode([
        $match->toArray(),
        $result->intake->fresh()->conflict_result,
        Activity::query()->where('event', 'conflict_check_run')->pluck('properties')->all(),
    ], JSON_UNESCAPED_UNICODE);
    expect($everything)->not->toContain('lừa đảo')
        ->and($everything)->not->toContain('Bí mật');
});

it('finds the same contact under all four ways of writing the phone number', function () {
    $assistant = ssStaff();
    $a = ssRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0832270898']);

    foreach (['0832270898', '+84832270898', '84832270898', '(+84) 832 270 898'] as $form) {
        $result = ssCheckParties([ssParty(PartyRole::Defendant, 'Tên hoàn toàn khác', phone: $form)]);

        expect($result->matches)->toHaveCount(1, "dạng {$form}")
            ->and($result->matches->first()->matterCode)->toBe($a->code)
            ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Phone);
    }
});

it('never turns a match against an intake red, whatever the roles and the tier', function () {
    $assistant = ssStaff();
    ssRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0832270898', 'contact_id_number' => '079012345678', 'contact_role' => PartyRole::Plaintiff]);

    // Bên mới ở vai Bị đơn đối lập với khách (nguyên đơn) và trùng cả CCCD lẫn SĐT: nếu là khách hàng
    // thật thì Đỏ; là người chưa thành khách thì tối đa Vàng.
    $result = ssCheckParties([
        ssParty(PartyRole::Plaintiff, 'Khách mới', ours: true),
        ssParty(PartyRole::Defendant, 'Bên kia', phone: '0832270898', idNumber: '079012345678'),
    ]);

    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->matches->every(fn (ConflictMatch $m) => $m->level === ConflictLevel::Yellow))->toBeTrue()
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Hash);
});

it('matches an opposing party named in an earlier intake as well as its contact', function () {
    $assistant = ssStaff();
    $a = ssRecord($assistant, ['contact_name' => 'Người Gọi Đầu'], [['name' => 'Bên đối lập của người đầu', 'role' => PartyRole::Defendant, 'phone' => '0966555444']]);

    // Người mới gọi CHÍNH LÀ bên bị khai ở lần trước.
    $result = ssCheckParties([ssParty(PartyRole::Plaintiff, 'Người mới', phone: '0966555444', ours: true)]);

    expect($result->matches)->toHaveCount(1)
        ->and($result->matches->first()->matterCode)->toBe($a->code)
        ->and($result->matches->first()->partyRole)->toBe(PartyRole::Defendant)
        ->and($result->matches->first()->partyName)->toBe('Bên đối lập của người đầu');
});

it('ignores intakes that were converted, merged, anonymised, soft deleted or are the record being checked', function () {
    $assistant = ssStaff();
    $matter = Matter::factory()->create();

    $mk = fn (string $name) => ssRecord($assistant, ['contact_name' => $name, 'contact_phone' => '0955123456']);

    $converted = $mk('Đã chuyển đổi');
    $converted->forceFill(['status' => IntakeStatus::Won, 'matter_id' => $matter->id])->save();

    $wonWithoutMatter = $mk('Won không vụ');
    $wonWithoutMatter->forceFill(['status' => IntakeStatus::Won])->save();

    $withMatterOnly = $mk('Có vụ không won');
    $withMatterOnly->forceFill(['matter_id' => Matter::factory()->create()->id])->save();

    $merged = $mk('Đã gộp');
    $merged->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => $converted->id])->save();

    $mergedIntoOnly = $mk('Có đích gộp');
    $mergedIntoOnly->forceFill(['merged_into_id' => $converted->id])->save();

    $anonymised = $mk('Đã ẩn danh');
    $anonymised->forceFill(['anonymised_at' => now()])->save();

    $trashed = $mk('Đã xoá mềm');
    $trashed->delete();

    $probe = fn () => ssCheckParties([ssParty(PartyRole::Defendant, 'Tên khác hẳn', phone: '0955123456')]);

    expect($probe()->matches)->toBeEmpty();

    // Cặp dương: còn mở thì thấy (đã từ chối và khách không theo tiếp vẫn là người văn phòng đã nghe).
    $declined = $mk('Bị từ chối');
    $declined->forceFill(['status' => IntakeStatus::Declined])->save();
    $lost = $mk('Không theo tiếp');
    $lost->forceFill(['status' => IntakeStatus::Lost])->save();

    expect($probe()->matches->pluck('matterCode')->all())->toEqualCanonicalizing([$declined->code, $lost->code]);
});

it('does not match the intake against its own contact and parties when it is the subject of the check', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_phone' => '0955123456'], [['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => '0955999888']]);

    expect($intake->conflict_result['matches'])->toBeEmpty()
        ->and($intake->conflict_level)->toBe(ConflictLevel::Green);
});

it('treats a repeat caller with the same phone and the same role as the same person, not as a conflict', function () {
    $assistant = ssStaff();
    ssRecord($assistant, ['contact_name' => 'Người Gọi Lại', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Plaintiff]);

    $second = ssRecord($assistant, ['contact_name' => 'Người Gọi Lại', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Plaintiff]);

    expect($second->conflict_level)->toBe(ConflictLevel::Green)
        ->and($second->conflict_result['matches'])->toBeEmpty();
});

it('keeps a same-phone contact on the opposite side, or with an unknown role, as a yellow match', function () {
    $assistant = ssStaff();
    // Vợ gọi từ máy bàn nhà: nguyên đơn (ly hôn).
    $wife = ssRecord($assistant, ['contact_name' => 'Vợ', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Plaintiff]);

    // Chồng gọi từ CHÍNH máy bàn đó, ở phía đối lập: không phải cùng một người.
    $husband = ssRecord($assistant, ['contact_name' => 'Chồng', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Defendant]);

    expect($husband->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($husband->conflict_result['matches'][0]['matter_code'])->toBe($wife->code);

    // Vai chưa biết của MỘT trong hai bên: không đủ để coi là cùng một người.
    $unknown = ssRecord($assistant, ['contact_name' => 'Chưa rõ', 'contact_phone' => '0955123456', 'contact_role' => null]);

    expect($unknown->conflict_level)->toBe(ConflictLevel::Yellow);
});

it('never treats a name-only match as the same caller again', function () {
    $assistant = ssStaff();
    ssRecord($assistant, ['contact_name' => 'Nguyễn Văn Tú', 'contact_phone' => '0955000111', 'contact_role' => PartyRole::Plaintiff]);

    // Cùng tên (bỏ dấu), khác SĐT, cùng vai: tên người Việt trùng nhau rất phổ biến — vẫn Vàng.
    $second = ssRecord($assistant, ['contact_name' => 'nguyen van tu', 'contact_phone' => '0955000222', 'contact_role' => PartyRole::Plaintiff]);

    expect($second->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($second->conflict_result['matches'][0]['tier'])->toBe('name');
});

it('shows OpenMatter the match from an unconverted intake, and lets it save once acknowledged', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0955123456']);

    $lawyer = ssStaff(Role::Lawyer);
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();
    $attributes = [
        'client_id' => $client->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id,
        'title' => 'Vụ có bên từng liên hệ', 'lead_lawyer_id' => $lawyer->id,
    ];
    $parties = [['role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0955123456']];

    try {
        app(OpenMatter::class)->handle($lawyer, $attributes, $parties);
        $this->fail('phải đòi xác nhận');
    } catch (ConflictAcknowledgementRequired $exception) {
        expect($exception->result->matches)->toHaveCount(1)
            ->and($exception->result->matches->first()->matterCode)->toBe($intake->code);
    }

    expect(Matter::query()->where('title', 'Vụ có bên từng liên hệ')->exists())->toBeFalse();

    $opened = app(OpenMatter::class)->handle($lawyer, $attributes, $parties, acknowledged: ConflictLevel::Yellow);

    expect($opened->matter->exists)->toBeTrue()
        ->and($opened->result->level)->toBe(ConflictLevel::Yellow);

    // R13c: lần thêm bên sau trên cùng vụ không bị chặn lại bởi khớp đã xác nhận (`pairKey` mang
    // tiền tố `ir`, đọc lại được từ `confirmed_pairs`).
    $row = Activity::query()->where('event', 'matter_opened')->where('subject_id', $opened->matter->id)->sole();
    expect($row->properties->get('confirmed_pairs')[0]['pair_key'])->toMatch('/^\d+::ir\d+$/');

    $added = app(AddMatterParty::class)->handle($opened->matter, $lawyer, ['role' => PartyRole::Related, 'name' => 'Một bên hoàn toàn khác', 'phone' => '0966111222']);
    expect($added->party->exists)->toBeTrue()
        ->and($added->result->level)->toBe(ConflictLevel::Green)
        ->and($added->result->confirmedMatches)->toHaveCount(1);
});

it('shows AddMatterParty the match from an unconverted intake', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0955123456']);

    $lawyer = ssStaff(Role::Lawyer);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    expect(fn () => app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0955123456',
    ]))->toThrow(ConflictAcknowledgementRequired::class);

    $result = app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0955123456',
    ], acknowledged: ConflictLevel::Yellow);

    expect($result->result->matches->pluck('matterCode')->all())->toBe([$intake->code]);
});

it('does not see a converted intake from OpenMatter', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Đã thành khách', 'contact_phone' => '0955123456']);
    $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save();

    $lawyer = ssStaff(Role::Lawyer);
    $type = MatterType::factory()->withStages()->create();

    $opened = app(OpenMatter::class)->handle($lawyer, [
        'client_id' => Client::factory()->create()->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id,
        'title' => 'Vụ sạch', 'lead_lawyer_id' => $lawyer->id,
    ], [['role' => PartyRole::Defendant, 'name' => 'Bên tên khác', 'phone' => '0955123456']]);

    expect($opened->result->level)->toBe(ConflictLevel::Green);
});

it('keeps the shape of a match from matter_parties unchanged and adds contacted_on only for the intake source', function () {
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);
    ssRecord(ssStaff(), ['contact_name' => 'Lê Thị Hoa', 'contact_phone' => '0955123456']);

    $result = ssCheckParties([ssParty(PartyRole::Defendant, 'Lê Thị Hoa')]);
    $arrays = $result->matches->map(fn (ConflictMatch $m) => $m->toArray());

    // Chọn theo NGUỒN (mã vụ hay mã `TN-…`), không theo chính khoá đang được khẳng định: chọn theo
    // khoá thì một bản đảo ngược (khoá cho khớp vụ, không cho khớp tiếp nhận) vẫn qua.
    $fromMatter = $arrays->sole(fn (array $a) => $a['matter_code'] === $matter->code);
    $fromIntake = $arrays->sole(fn (array $a) => str_starts_with($a['matter_code'], 'TN-'));

    expect(array_keys($fromMatter))->toBe([
        'matter_code', 'matter_type_name', 'party_role', 'party_name', 'level', 'tier', 'our_party_role', 'our_party_name',
    ])
        ->and(array_keys($fromIntake))->toBe([
            'matter_code', 'matter_type_name', 'party_role', 'party_name', 'level', 'tier', 'our_party_role', 'our_party_name', 'contacted_on',
        ])
        ->and($fromIntake['contacted_on'])->toBe(now()->format('Y-m-d'));
});

it('gives a match from an intake a pair key with a prefix that never collides with a matter party id', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_phone' => '0955123456'], [['name' => 'Bên khác', 'role' => PartyRole::Defendant, 'phone' => '0966555444']]);

    $viaContact = ssCheckParties([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')])->allNewMatches->first();
    $viaParty = ssCheckParties([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0966555444')])->allNewMatches->first();

    // Trong chế độ vụ việc, bên phía mình chưa lưu thì `pairKey()` là null (không có gì để xác nhận).
    expect($viaContact->pairKey())->toBeNull()
        ->and($viaParty->pairKey())->toBeNull();

    // Ở chế độ tiếp nhận, mỗi bên phía mình mang một chữ ký nên `pairKey()` luôn có.
    $result = app(RunConflictCheck::class)->handle(
        collect([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')]),
        null, null, null, null,
        IntakeRequest::factory()->create(),
    );

    expect($result->allNewMatches->first()->pairKey())->toMatch('/^i:[0-9a-f]{64}::ir'.$intake->id.'$/');

    $viaParty = app(RunConflictCheck::class)->handle(
        collect([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0966555444')]),
        null, null, null, null,
        IntakeRequest::factory()->create(),
    );

    expect($viaParty->allNewMatches->first()->pairKey())->toMatch('/^i:[0-9a-f]{64}::ip'.IntakeParty::query()->value('id').'$/');
});

it('keeps the acknowledgement of each side separate: the same identity in two roles is two pairs', function () {
    // Bất biến C1 cho chế độ tiếp nhận: chữ ký có VAI.
    $assistant = ssStaff();
    ssRecord($assistant, ['contact_name' => 'Người Cũ', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);
    $subject = IntakeRequest::factory()->create();

    $asPlaintiff = app(RunConflictCheck::class)->handle(collect([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0955123456', ours: true)]), null, null, null, null, $subject);
    $asDefendant = app(RunConflictCheck::class)->handle(collect([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456', ours: true)]), null, null, null, null, $subject);

    expect($asPlaintiff->allNewMatches->first()->pairKey())->not->toBe($asDefendant->allNewMatches->first()->pairKey());
});

it('leaves a check run for the four old callers untouched: no subject means the matter, or nothing', function () {
    $matter = Matter::factory()->create();

    app(RunConflictCheck::class)->handle(collect([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0955123456')]), $matter);
    app(RunConflictCheck::class)->handle(collect([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0955123456')]));

    $rows = Activity::query()->where('event', 'conflict_check_run')->orderBy('id')->get();

    expect($rows[0]->subject_type)->toBe($matter->getMorphClass())
        ->and($rows[0]->subject_id)->toBe($matter->id)
        ->and($rows[1]->subject_type)->toBeNull();
});

it('does not reveal the lead lawyer, title, or story of anything: the match carries only the ConflictMatch fields', function () {
    $assistant = ssStaff();
    $a = ssRecord($assistant, ['contact_name' => 'Người Đã Gọi', 'contact_phone' => '0955123456']);
    app(RecordPrivacyNotice::class)->handle($assistant, $a, true);
    app(UpdateIntakeSummary::class)->handle($assistant, $a, 'CÂU CHUYỆN RIÊNG');

    $result = ssCheckParties([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')]);
    $match = $result->matches->first();

    // Ranh giới lộ thông tin: `ConflictMatch` không có thuộc tính nào chứa câu chuyện; bản ghi tìm
    // thấy là tham chiếu nội bộ, không bao giờ vào `toArray()`.
    expect(json_encode($result->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('CÂU CHUYỆN')
        ->and(array_keys($match->toArray()))->toBe([
            'matter_code', 'matter_type_name', 'party_role', 'party_name', 'level', 'tier', 'our_party_role', 'our_party_name', 'contacted_on',
        ]);
});

it('raises a new yellow warning on an open matter when an intake names one of its parties, on the next check', function () {
    // Hệ quả có chủ đích của nguồn thứ hai (R1): kết quả của các lần kiểm tra sau trên một vụ đang mở
    // có thể ra Vàng vì một cuộc gọi cũ. Ghi lại bằng test để không ai tưởng đó là lỗi.
    $lawyer = ssStaff(Role::Lawyer);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create([
        'role' => PartyRole::Defendant, 'is_our_client' => false, 'name' => 'Bị đơn của vụ',
    ]);
    $party->identify(null, '0955123456')->save();

    expect(app(RunConflictCheck::class)->handle(collect([$party]), $matter)->level)->toBe(ConflictLevel::Green);

    ssRecord(ssStaff(), ['contact_name' => 'Người gọi sau', 'contact_phone' => '0955123456']);

    $after = app(RunConflictCheck::class)->handle(collect([$party]), $matter);

    expect($after->level)->toBe(ConflictLevel::Yellow)
        ->and($after->matches->first()->tier)->toBe(ConflictMatchTier::Phone);
});

it('acknowledges through the intake gate a yellow that came from another intake, and remembers it', function () {
    $assistant = ssStaff();
    $first = ssRecord($assistant, ['contact_name' => 'Người Đầu', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Plaintiff]);

    $second = ssRecord($assistant, ['contact_name' => 'Người Sau', 'contact_phone' => '0977888999', 'contact_role' => PartyRole::Defendant], [
        ['name' => 'Bên kia', 'role' => PartyRole::Plaintiff, 'phone' => '0955123456'],
    ]);

    expect($second->conflict_level)->toBe(ConflictLevel::Yellow);

    app(AcknowledgeIntakeConflict::class)->handle($assistant, $second, ConflictLevel::Yellow);
    $again = app(RerunIntakeConflictCheck::class)->handle($assistant, $second->fresh());

    expect($again->matches)->toBeEmpty()
        ->and($again->confirmedMatches->first()->matterCode)->toBe($first->code);
});

it('does not dedupe a contact when the check is not a check of an intake: a client with the same phone and role still gets the yellow', function () {
    // Chỉ chế độ tiếp nhận coi "cùng số, cùng vai" là người gọi lại. Khi mở vụ cho một khách hàng
    // thật, một cuộc gọi cũ chưa chuyển đổi của cùng số vẫn phải hiện: văn phòng đã nghe chuyện.
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Người đã gọi', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Plaintiff]);

    $result = ssCheckParties([ssParty(PartyRole::Plaintiff, 'Người đã gọi', phone: '0955123456', ours: true)]);

    expect($result->matches)->toHaveCount(1)
        ->and($result->matches->first()->matterCode)->toBe($intake->code);
});

it('still finds an intake while a portal client session is open, like matter_parties do', function () {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Người đã gọi', 'contact_phone' => '0955123456']);

    $this->actingAs(ClientUser::factory()->create(), 'client');

    $result = ssCheckParties([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')]);

    expect($result->matches->pluck('matterCode')->all())->toBe([$intake->code]);

    // Cả bên đối lập của lần tiếp nhận cũng được tìm thấy (truy vấn `whereHas` cũng bỏ cổng khách).
    ssRecord($assistant, ['contact_name' => 'Người khác', 'contact_phone' => '0977000111'], [['name' => 'Bên kia', 'role' => PartyRole::Defendant, 'phone' => '0966555444']]);
    $viaParty = ssCheckParties([ssParty(PartyRole::Plaintiff, 'Ai đó', phone: '0966555444')]);

    expect($viaParty->matches)->toHaveCount(1);
});

it('treats a merged status without a merge target as closed, and a record with both as closed', function () {
    $assistant = ssStaff();
    $merged = ssRecord($assistant, ['contact_name' => 'Gộp thiếu đích', 'contact_phone' => '0955123456']);
    $merged->forceFill(['status' => IntakeStatus::Merged])->save();

    expect(ssCheckParties([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')])->matches)->toBeEmpty();
});

it('uses the plaintiff branch of the implied role too: a plaintiff opposing party makes the contact the defendant', function () {
    $client = Client::factory()->create(['phone' => '0912000333']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    $result = app(RecordIntake::class)->handle(ssStaff(), [
        'contact_name' => 'Người liên hệ chưa khai vai', 'contact_phone' => '0832270898', 'source' => 'phone',
    ], [['name' => 'Nguyên đơn trùng khách', 'role' => PartyRole::Plaintiff, 'phone' => '0912000333']]);

    expect($result->conflict->level)->toBe(ConflictLevel::Red);
});

it('rechecks an open matter and warns when a client identity edit brings it into contact with an unconverted intake', function () {
    // Hệ quả của nguồn thứ hai cho lần kiểm tra lại R13(e): sửa định danh khách hàng chạy lại kiểm tra
    // cho vụ đang mở, và một cuộc gọi cũ khớp bên của vụ sẽ ra Vàng MỚI, kèm thông báo.
    $lead = ssStaff(Role::Lawyer);
    $client = Client::factory()->create(['phone' => '0900000001']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'client_id' => $client->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    ssRecord(ssStaff(), ['contact_name' => 'Người gọi từ trước', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);

    expect(Activity::query()->where('event', 'client_identity_conflict_detected')->count())->toBe(0);

    $client->update(['phone' => '0955123456']);

    $row = Activity::query()->where('event', 'client_identity_conflict_detected')->first();

    expect($row)->not->toBeNull()
        ->and($row->properties->get('level'))->toBe('yellow')
        ->and($lead->notifications()->count())->toBe(1);
});

it('excludes another intake named by the caller, which is how a conversion will keep an intake from matching itself', function () {
    $assistant = ssStaff();
    $converting = ssRecord($assistant, ['contact_name' => 'Đang được chuyển đổi', 'contact_phone' => '0955123456']);
    $other = ssRecord($assistant, ['contact_name' => 'Người khác cùng số', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);

    $parties = fn () => collect([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')]);

    $without = app(RunConflictCheck::class)->handle($parties());
    $with = app(RunConflictCheck::class)->handle($parties(), null, null, null, null, null, $converting->id);

    expect($without->matches->pluck('matterCode')->all())->toEqualCanonicalizing([$converting->code, $other->code])
        ->and($with->matches->pluck('matterCode')->all())->toBe([$other->code]);
});

it('excludes both the intake being checked and the extra intake named by the caller, not only one of them', function () {
    $assistant = ssStaff();
    $subject = ssRecord($assistant, ['contact_name' => 'Chủ thể', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);
    $extra = ssRecord($assistant, ['contact_name' => 'Bản loại thêm', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);
    $other = ssRecord($assistant, ['contact_name' => 'Người khác cùng số', 'contact_phone' => '0955123456', 'contact_role' => PartyRole::Related]);

    $result = app(RunConflictCheck::class)->handle(
        collect([ssParty(PartyRole::Defendant, 'Ai đó', phone: '0955123456')]), null, null, null, null, $subject, $extra->id,
    );

    expect($result->matches->pluck('matterCode')->all())->toBe([$other->code]);
});

it('matches an opposing party of an earlier intake by its id hash and by its name', function () {
    $assistant = ssStaff();
    $a = ssRecord($assistant, ['contact_name' => 'Người gọi'], [
        ['name' => 'Trần Văn Bị Đơn', 'role' => PartyRole::Defendant, 'id_number' => '079012345678'],
    ]);

    $byHash = ssCheckParties([ssParty(PartyRole::Plaintiff, 'Tên khác hẳn', idNumber: '079-012-345-678')]);
    $byName = ssCheckParties([ssParty(PartyRole::Plaintiff, 'tran van bi don')]);

    expect($byHash->matches)->toHaveCount(1)
        ->and($byHash->matches->first()->tier)->toBe(ConflictMatchTier::Hash)
        ->and($byHash->matches->first()->matterCode)->toBe($a->code)
        ->and($byName->matches)->toHaveCount(1)
        ->and($byName->matches->first()->tier)->toBe(ConflictMatchTier::Name);
});

it('matches an earlier intake contact by its id hash', function () {
    $assistant = ssStaff();
    $a = ssRecord($assistant, ['contact_name' => 'Người gọi', 'contact_phone' => '0955123456', 'contact_id_number' => '079012345678']);

    $result = ssCheckParties([ssParty(PartyRole::Defendant, 'Tên khác hẳn', idNumber: '079012345678')]);

    expect($result->matches->first()->tier)->toBe(ConflictMatchTier::Hash)
        ->and($result->matches->first()->matterCode)->toBe($a->code);
});

it('does not let an old acknowledgement cover a match again once our side changed identity', function (string $change) {
    // Chữ ký của bên phía mình gồm vai + tên + SĐT + CCCD: đổi một trong ba mà VẪN khớp đúng dòng cũ
    // (cùng mức Vàng) thì là một cặp MỚI, không phải cặp đã xác nhận.
    $matter = Matter::factory()->create();
    $found = MatterParty::factory()->for($matter)->create([
        'role' => PartyRole::Plaintiff, 'is_our_client' => false, 'name' => 'Lê Thị Hoa',
    ]);
    $found->identify('079012345678', '0977000111')->save();

    $assistant = ssStaff();
    $initial = match ($change) {
        'phone added' => ['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant],
        'id number added' => ['name' => 'Lê Thị Hoa', 'role' => PartyRole::Defendant],
        'name changed' => ['name' => 'Hoa Ban Đầu', 'role' => PartyRole::Defendant, 'phone' => '0977000111'],
    };
    $intake = ssRecord($assistant, [], [$initial]);
    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow);
    app(AcknowledgeIntakeConflict::class)->handle($assistant, $intake, ConflictLevel::Yellow);

    // Không đổi gì: không bị hỏi lại.
    expect(app(RerunIntakeConflictCheck::class)->handle($assistant, $intake->fresh())->matches)->toBeEmpty();

    $party = $intake->parties()->first();
    match ($change) {
        'phone added' => $party->identify(null, '0977000111'),
        'id number added' => $party->identify('079012345678', null),
        'name changed' => $party->fill(['name' => 'Hoa Đã Đổi Tên']),
    };
    $party->save();

    $after = app(RerunIntakeConflictCheck::class)->handle($assistant, $intake->fresh());

    expect($after->level)->toBe(ConflictLevel::Yellow)
        ->and($after->matches)->toHaveCount(1)
        ->and(IntakeSummaryGate::blockers($intake->fresh()))->toContain(IntakeSummaryBlocker::ConflictAcknowledgement);
})->with(['phone added', 'id number added', 'name changed']);

it('ignores an opposing party of an intake that was converted, merged, anonymised or soft deleted', function (string $how) {
    $assistant = ssStaff();
    $intake = ssRecord($assistant, ['contact_name' => 'Người gọi', 'contact_phone' => '0977000111'], [
        ['name' => 'Bên bị khai', 'role' => PartyRole::Defendant, 'phone' => '0955123456'],
    ]);

    $probe = fn () => ssCheckParties([ssParty(PartyRole::Plaintiff, 'Tên khác hẳn', phone: '0955123456')]);
    expect($probe()->matches)->toHaveCount(1);

    match ($how) {
        'converted' => $intake->forceFill(['status' => IntakeStatus::Won, 'matter_id' => Matter::factory()->create()->id])->save(),
        'merged' => $intake->forceFill(['status' => IntakeStatus::Merged, 'merged_into_id' => ssRecord($assistant, ['contact_name' => 'Đích gộp', 'contact_phone' => '0900000009'])->id])->save(),
        'anonymised' => $intake->forceFill(['anonymised_at' => now()])->save(),
        'soft deleted' => $intake->delete(),
    };

    expect($probe()->matches)->toBeEmpty();
})->with(['converted', 'merged', 'anonymised', 'soft deleted']);
