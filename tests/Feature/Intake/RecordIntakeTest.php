<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictCheckBusy;
use App\Models\Client;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Đầu vào tối thiểu hợp lệ của một lần liên hệ qua điện thoại. */
function m10Input(array $overrides = []): array
{
    return [...[
        'contact_name' => 'Trần Thị Lan',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides];
}

function m10Staff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

it('records only the identity part of a contact, with the source, status and a TN code', function () {
    $actor = m10Staff();

    $result = app(RecordIntake::class)->handle($actor, m10Input(['contact_email' => 'lan@example.com', 'referred_by' => 'Anh Bình']));

    $intake = $result->intake;

    expect($intake->exists)->toBeTrue()
        ->and($intake->code)->toBe('TN-'.now()->format('Y').'-0001')
        ->and($intake->status)->toBe(IntakeStatus::New)
        ->and($intake->source)->toBe(IntakeSource::Phone)
        ->and($intake->contact_name)->toBe('Trần Thị Lan')
        ->and($intake->contact_email)->toBe('lan@example.com')
        ->and($intake->referred_by)->toBe('Anh Bình')
        ->and($intake->contact_role)->toBe(PartyRole::Plaintiff)
        ->and($intake->received_at)->not->toBeNull()
        ->and($intake->summary)->toBeNull()
        ->and($intake->client_id)->toBeNull()
        ->and($intake->matter_id)->toBeNull();
});

it('never writes the story, even when the caller passes a summary', function () {
    $result = app(RecordIntake::class)->handle(m10Staff(), m10Input(['summary' => 'Câu chuyện bí mật của người gọi']));

    expect($result->intake->summary)->toBeNull()
        ->and(IntakeRequest::query()->whereNotNull('summary')->count())->toBe(0);
});

it('does not create a client: the contact is not a client until the office takes the case', function () {
    $before = Client::count();

    app(RecordIntake::class)->handle(m10Staff(), m10Input());

    expect(Client::count())->toBe($before);
});

it('normalises the four ways of writing one phone number to the same value', function () {
    $actor = m10Staff();
    $normalised = [];

    foreach (['0832270898', '+84832270898', '84832270898', '(+84) 832 270 898'] as $index => $phone) {
        $normalised[] = app(RecordIntake::class)->handle($actor, m10Input([
            'contact_name' => 'Người gọi '.$index,
            'contact_phone' => $phone,
        ]))->intake->contact_phone_normalized;
    }

    expect(array_unique($normalised))->toHaveCount(1)
        ->and($normalised[0])->toBe(Normalizer::phone('0832270898'));
});

it('stores only a hash of the id number, and only hashes for the opposing parties', function () {
    $result = app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(['contact_id_number' => '079012345678']),
        [['name' => 'Nguyễn Văn Hùng', 'role' => PartyRole::Defendant, 'phone' => '0911222333', 'id_number' => '001099887766']],
    );

    $row = (array) DB::table('intake_requests')->where('id', $result->intake->id)->first();
    $party = (array) DB::table('intake_parties')->where('intake_request_id', $result->intake->id)->first();

    expect($row['contact_id_number_hash'])->toBe(Normalizer::idNumberHash('079012345678'))
        ->and(json_encode($row))->not->toContain('079012345678')
        ->and($party['id_number_hash'])->toBe(Normalizer::idNumberHash('001099887766'))
        ->and($party['phone_normalized'])->toBe(Normalizer::phone('0911222333'))
        ->and(json_encode($party))->not->toContain('001099887766')
        ->and(json_encode($party))->not->toContain('0911222333');
});

it('runs the conflict check at first touch and stores the result, the level and when it ran', function () {
    $result = app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_id_number' => '079012345678']));

    $intake = $result->intake;

    expect($result->conflict->level)->toBe(ConflictLevel::Green)
        ->and($intake->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->conflict_checked_at)->not->toBeNull()
        ->and($intake->conflict_result['level'])->toBe('green')
        ->and($intake->conflict_result['fingerprint'])->toBe($intake->identityFingerprint());
});

it('writes exactly one conflict_check_run row per run, and its subject is the intake record', function () {
    $result = app(RecordIntake::class)->handle(m10Staff(), m10Input());

    $rows = Activity::query()->where('event', 'conflict_check_run')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->subject_type)->toBe('intake_request')
        ->and($rows->first()->subject_id)->toBe($result->intake->id);
});

it('takes created_by and every log causer from the explicit actor, not from the signed-in user', function () {
    $actor = m10Staff();
    $sessionUser = m10Staff(Role::Manager);
    $this->actingAs($sessionUser, 'web');

    $intake = app(RecordIntake::class)->handle($actor, m10Input())->intake;

    $rows = Activity::query()->whereIn('event', ['intake_recorded', 'conflict_check_run'])->get();

    expect($intake->created_by)->toBe($actor->id)
        ->and($intake->updated_by)->toBe($actor->id)
        ->and($rows)->toHaveCount(2)
        ->and($rows->every(fn (Activity $row) => $row->causer?->is($actor) === true))->toBeTrue()
        ->and($rows->firstWhere('event', 'intake_recorded')->properties->get('actor_explicit'))->toBeTrue()
        ->and($rows->firstWhere('event', 'conflict_check_run')->properties->get('actor_explicit'))->toBeTrue()
        // Bản ghi tự động của model bị tắt ở lần tạo: nó sẽ gán causer theo phiên.
        ->and(Activity::query()->where('event', 'created')->where('subject_type', 'intake_request')->count())->toBe(0);
});

it('records with no actor for a non-staff entry point, without inventing one', function () {
    $result = app(RecordIntake::class)->handle(null, m10Input(['source' => IntakeSource::WebsiteForm]));

    $row = Activity::query()->where('event', 'intake_recorded')->first();

    expect($result->intake->created_by)->toBeNull()
        ->and($result->intake->source)->toBe(IntakeSource::WebsiteForm)
        ->and($row->causer)->toBeNull()
        ->and($row->properties->get('actor_explicit'))->toBeFalse()
        ->and($result->duplicates->clientLookupUnavailable)->toBeTrue();
});

it('lets the assistant, lawyer and manager record a contact, and refuses the accountant', function () {
    foreach ([Role::Assistant, Role::Lawyer, Role::Manager, Role::Admin] as $role) {
        expect(app(RecordIntake::class)->handle(m10Staff($role), m10Input(['contact_name' => 'Người của '.$role->value]))->intake->exists)->toBeTrue();
    }

    expect(fn () => app(RecordIntake::class)->handle(m10Staff(Role::Accountant), m10Input()))
        ->toThrow(AuthorizationException::class);
});

it('refuses opposing_counsel as the role of the contact', function () {
    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_role' => PartyRole::OpposingCounsel])))
        ->toThrow(ValidationException::class);

    expect(IntakeRequest::count())->toBe(0);
});

it('needs only a name and a source', function () {
    $result = app(RecordIntake::class)->handle(m10Staff(), ['contact_name' => 'Người Gọi Không Nói Gì', 'source' => 'walk_in']);

    expect($result->intake->contact_phone)->toBeNull()
        ->and($result->intake->contact_role)->toBeNull()
        // Không định danh nào: kết quả chỉ ra "thiếu định danh" — ô câu chuyện đi qua cổng xác nhận.
        ->and($result->conflict->hasIncompleteParties())->toBeTrue();
});

it('validates every field against the column length, so MariaDB strict never answers 500', function () {
    $tooLong = fn (int $length) => str_repeat('a', $length + 1);

    foreach ([
        ['contact_name', 200], ['contact_phone', 20], ['referred_by', 200],
    ] as [$field, $length]) {
        expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input([$field => $tooLong($length)])))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_email' => 'a@'.str_repeat('b', 150).'.com'])))
        ->toThrow(ValidationException::class);

    // Đúng bằng độ dài cột thì qua.
    $ok = app(RecordIntake::class)->handle(m10Staff(), m10Input([
        'contact_name' => str_repeat('a', 200), 'contact_phone' => str_repeat('1', 20), 'referred_by' => str_repeat('a', 200),
    ]));
    expect($ok->intake->exists)->toBeTrue();
});

it('validates the opposing parties: a name and a role each, and at most ten', function () {
    $record = fn (array $parties) => fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(), $parties);

    expect($record([['role' => PartyRole::Defendant]]))->toThrow(ValidationException::class)
        ->and($record([['name' => 'Bên A']]))->toThrow(ValidationException::class)
        ->and($record([['name' => str_repeat('a', 201), 'role' => PartyRole::Defendant]]))->toThrow(ValidationException::class)
        ->and($record(array_fill(0, 11, ['name' => 'Bên A', 'role' => PartyRole::Defendant])))->toThrow(ValidationException::class);

    expect(IntakeRequest::count())->toBe(0)
        ->and(IntakeParty::count())->toBe(0);
});

it('parses the quoted amount with Money::parse and refuses a badly typed one', function () {
    $result = app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => '1.250.000']));

    expect($result->intake->quoted_amount)->toBe(1_250_000);

    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => '1.25'])))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => -5])))
        ->toThrow(ValidationException::class);
});

it('accepts an assignee only if active and able to record intake', function () {
    $assistant = m10Staff();
    $accountant = m10Staff(Role::Accountant);
    $inactive = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    expect(app(RecordIntake::class)->handle(m10Staff(), m10Input(['assigned_to' => $assistant->id]))->intake->assigned_to)->toBe($assistant->id);

    foreach ([$accountant, $inactive] as $bad) {
        expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['assigned_to' => $bad->id])))
            ->toThrow(ValidationException::class);
    }
});

it('gives two contacts two consecutive codes', function () {
    $actor = m10Staff();
    $first = app(RecordIntake::class)->handle($actor, m10Input(['contact_name' => 'Một']))->intake;
    $second = app(RecordIntake::class)->handle($actor, m10Input(['contact_name' => 'Hai']))->intake;

    expect($first->code)->toEndWith('-0001')
        ->and($second->code)->toEndWith('-0002');
});

it('keeps the record when the result is red: the contact must stay visible to later checks', function () {
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => '079012345678']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client)->create();

    $result = app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(),
        [['name' => 'Bị đơn trùng khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']],
    );

    expect($result->conflict->level)->toBe(ConflictLevel::Red)
        ->and($result->intake->exists)->toBeTrue()
        ->and($result->intake->conflict_level)->toBe(ConflictLevel::Red)
        ->and($result->intake->summary)->toBeNull();
});

it('derives the role of the contact from the opposing party for the check when none was given', function () {
    $client = Client::factory()->create(['phone' => '0912000222']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client)->create();

    // Không khai `contact_role`: nếu không suy ra, Đỏ tắt lặng lẽ.
    $result = app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(['contact_role' => null]),
        [['name' => 'Bị đơn trùng khách', 'role' => PartyRole::Defendant, 'phone' => '0912000222']],
    );

    expect($result->conflict->level)->toBe(ConflictLevel::Red)
        ->and($result->intake->contact_role)->toBeNull();
});

it('writes no personal data to the activity log', function () {
    app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(['contact_id_number' => '079012345678', 'contact_email' => 'lan@example.com', 'referred_by' => 'Anh Bình']),
        [['name' => 'Bên Đối Lập Bí Mật', 'role' => PartyRole::Defendant, 'phone' => '0911222333']],
    );

    $recorded = Activity::query()->where('event', 'intake_recorded')->first();
    $json = json_encode($recorded->properties, JSON_UNESCAPED_UNICODE);

    foreach (['Trần Thị Lan', '0832270898', '079012345678', 'lan@example.com', 'Anh Bình', 'Bên Đối Lập Bí Mật', '0911222333'] as $needle) {
        expect($json)->not->toContain($needle);
    }
});

it('refuses a contact with no name, no source, an unknown source, a bad email or a bad date', function (array $overrides) {
    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input($overrides)))
        ->toThrow(ValidationException::class);

    expect(IntakeRequest::count())->toBe(0);
})->with([
    'no name' => [['contact_name' => null]],
    'blank name' => [['contact_name' => '   ']],
    'no source' => [['source' => null]],
    'unknown source' => [['source' => 'carrier_pigeon']],
    'unknown role' => [['contact_role' => 'emperor']],
    'bad email' => [['contact_email' => 'không phải email']],
    'bad date' => [['received_at' => 'hôm qua lúc nào đó']],
    'unknown matter type' => [['matter_type_id' => 999999]],
    'unknown assignee' => [['assigned_to' => 999999]],
]);

it('refuses a quoted amount that is too large or of the wrong type', function (mixed $amount) {
    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => $amount])))
        ->toThrow(ValidationException::class);
})->with([
    'integer above the ceiling' => [1_000_000_000_000],
    'string above the ceiling' => ['1.000.000.000.000'],
    'a float' => [1.5],
    'an array' => [[1]],
]);

it('accepts a quoted amount of zero and an empty one, and keeps a plain integer', function () {
    expect(app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => 0]))->intake->quoted_amount)->toBe(0)
        ->and(app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => '']))->intake->quoted_amount)->toBeNull()
        ->and(app(RecordIntake::class)->handle(m10Staff(), m10Input(['quoted_amount' => 2_500_000]))->intake->quoted_amount)->toBe(2_500_000);
});

it('stores the received time when given and the matter type when it exists', function () {
    $type = MatterType::factory()->create();

    $intake = app(RecordIntake::class)->handle(m10Staff(), m10Input([
        'received_at' => '2026-08-01 09:30:00', 'matter_type_id' => $type->id,
    ]))->intake;

    expect($intake->received_at->format('Y-m-d H:i'))->toBe('2026-08-01 09:30')
        ->and($intake->matter_type_id)->toBe($type->id);
});

it('trims the name and turns blank optional fields into null', function () {
    $intake = app(RecordIntake::class)->handle(m10Staff(), m10Input([
        'contact_name' => '  Trần Thị Lan  ', 'contact_email' => '  ', 'referred_by' => '', 'contact_phone' => ' 0832270898 ',
    ]), [['name' => '  Bên A  ', 'role' => 'defendant', 'phone' => '  ', 'id_number' => '']])->intake;

    expect($intake->contact_name)->toBe('Trần Thị Lan')
        ->and($intake->contact_email)->toBeNull()
        ->and($intake->referred_by)->toBeNull()
        ->and($intake->contact_phone)->toBe('0832270898')
        ->and($intake->parties()->first()->name)->toBe('Bên A')
        ->and($intake->parties()->first()->phone_normalized)->toBeNull();
});

it('answers with a Vietnamese refusal, not a 500, when the conflict lock stays busy, and saves nothing', function () {
    $actor = m10Staff();

    $lock = Cache::store('database')->lock('conflict-check', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(RecordIntake::class)->handle($actor, m10Input()))
            ->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));

        expect(IntakeRequest::count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('refuses the accountant before anything is written', function () {
    expect(fn () => app(RecordIntake::class)->handle(m10Staff(Role::Accountant), m10Input()))
        ->toThrow(AuthorizationException::class);

    // Không chỉ "có ném": bản ghi, bên đối lập và dòng nhật ký đều không được tạo.
    expect(IntakeRequest::count())->toBe(0)
        ->and(Activity::query()->where('event', 'conflict_check_run')->count())->toBe(0);
});

it('takes the email and the opposing party phone exactly up to their column length', function () {
    // 60 + 1 + 45 + 1 + 40 + 1 + 3 = 151 ký tự; mỗi nhãn tên miền dưới 64 ký tự.
    $email = fn (int $label): string => str_repeat('a', 60).'@'.str_repeat('b', $label).'.'.str_repeat('c', 40).'.com';

    expect(strlen($email(44)))->toBe(150);

    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_email' => $email(45)])))
        ->toThrow(ValidationException::class);
    expect(app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_name' => 'Email dài', 'contact_email' => $email(44)]))->intake->contact_email)
        ->toBe($email(44));

    $party = fn (string $phone): array => [['name' => 'Bên A', 'role' => PartyRole::Defendant, 'phone' => $phone]];

    expect(fn () => app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_name' => 'SĐT dài']), $party(str_repeat('1', 21))))
        ->toThrow(ValidationException::class);
    expect(app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_name' => 'SĐT vừa']), $party(str_repeat('1', 20)))->intake->exists)
        ->toBeTrue();
});

it('keeps a plain call with a plaintiff contact and a defendant opposing party green', function () {
    // Người liên hệ là "khách" của lần kiểm tra, bên đối lập thì KHÔNG: nếu cả hai cùng được coi là
    // khách, lần kiểm tra thấy hai khách ở hai phía của cùng một vụ và ra Đỏ vô cớ.
    $result = app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(['contact_phone' => '0901234567']),
        [['name' => 'Bị đơn bình thường', 'role' => PartyRole::Defendant, 'phone' => '0907654321', 'id_number' => '001099887766']],
    );

    expect($result->conflict->level)->toBe(ConflictLevel::Green)
        ->and($result->conflict->matches)->toBeEmpty();
});

it('checks the contact under its declared role, or as related when neither the role nor an opposing side is known', function () {
    $client = Client::factory()->create(['phone' => '0912000333']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    // Không vai, không bên nguyên/bị đối lập: vai dùng cho lần kiểm tra là "liên quan".
    $unknown = app(RecordIntake::class)->handle(m10Staff(), m10Input(['contact_role' => null, 'contact_phone' => '0912000333']));

    expect($unknown->intake->conflict_result['matches'][0]['our_party_role'])->toBe(PartyRole::Related->value);

    // Vai đã khai thắng vai suy ra (bên đối lập "liên quan" sẽ cho ra "liên quan").
    $declared = app(RecordIntake::class)->handle(
        m10Staff(),
        m10Input(['contact_name' => 'Có vai', 'contact_role' => PartyRole::Defendant, 'contact_phone' => '0912000333']),
        [['name' => 'Người liên quan', 'role' => PartyRole::Related]],
    );

    expect(collect($declared->intake->conflict_result['matches'])->firstWhere('matter_code', '!=', $unknown->intake->code)['our_party_role'])
        ->toBe(PartyRole::Defendant->value);
});

it('checks with the stored identifiers of the contact and of the opposing parties', function () {
    $actor = m10Staff();

    // Lần một khai hai bên đối lập: một bên chỉ có SĐT, một bên chỉ có CCCD.
    $first = app(RecordIntake::class)->handle($actor, m10Input(['contact_name' => 'Lần một', 'contact_phone' => '0901000001']), [
        ['name' => 'Bên Có Số', 'role' => PartyRole::Defendant, 'phone' => '0902000002'],
        ['name' => 'Bên Có Căn Cước', 'role' => PartyRole::Defendant, 'id_number' => '001088776655'],
    ])->intake;

    // Người liên hệ mới mang SĐT của bên thứ nhất (tên khác): khớp theo SĐT.
    $byPhone = app(RecordIntake::class)->handle($actor, m10Input(['contact_name' => 'Tên Khác Một', 'contact_phone' => '0902000002']))->intake;
    expect($byPhone->conflict_result['matches'][0]['tier'])->toBe('phone')
        ->and($byPhone->conflict_result['matches'][0]['matter_code'])->toBe($first->code);

    // Người liên hệ mới mang CCCD của bên thứ hai (tên khác, không SĐT): khớp theo dấu băm.
    $byHash = app(RecordIntake::class)->handle($actor, m10Input([
        'contact_name' => 'Tên Khác Hai', 'contact_phone' => null, 'contact_id_number' => '001088776655',
    ]))->intake;
    expect($byHash->conflict_result['matches'][0]['tier'])->toBe('hash')
        ->and($byHash->conflict_result['matches'][0]['matter_code'])->toBe($first->code);

    // Bên đối lập chỉ có CCCD của một khách hiện hữu, ở phía đối lập: Đỏ theo dấu băm.
    $client = Client::factory()->create(['phone' => '0912000444', 'id_number' => '079055554444']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();

    $red = app(RecordIntake::class)->handle($actor, m10Input(['contact_name' => 'Người Kiện', 'contact_phone' => '0903000003']), [
        ['name' => 'Tên Không Giống', 'role' => PartyRole::Defendant, 'id_number' => '079 055 554 444'],
    ]);
    expect($red->conflict->level)->toBe(ConflictLevel::Red)
        ->and($red->intake->conflict_result['matches'][0]['tier'])->toBe('hash');
});
