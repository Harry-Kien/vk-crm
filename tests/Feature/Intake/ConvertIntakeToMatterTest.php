<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\MergeIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\RerunIntakeConflictCheck;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\OpenMatter;
use App\Actions\RunConflictCheck;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ExistingClientConfirmationRequired;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\ConflictCheckResult;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/*
 * M10 Task 4 — luật của `ConvertIntakeToMatter` (R3) mà màn hình không tự nói ra được: cổng quyền của
 * CHÍNH Action, từng nhánh "không chuyển đổi được", lần kiểm tra lại có khoá TRONG transaction lưu của
 * `OpenMatter` (hai lần chuyển đổi không sinh hai vụ; một thay đổi xen giữa không để lại vụ mồ côi),
 * bản ghi đang chuyển đổi không tự khớp chính nó qua nguồn dò thứ hai, dấu băm CCCD của bên đối lập
 * sang `matter_parties` nguyên vẹn, và đường ghi dấu băm có kiểm soát của `BuildsMatterParties`.
 * Hành vi MÀN HÌNH ở `tests/Feature/Filament/ConvertIntakeRequestTest.php`.
 *
 * Hàm toàn cục mang tiền tố `cvt…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function cvtStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

function cvtType(): MatterType
{
    return MatterType::factory()->withStages()->create(['is_active' => true]);
}

/** Một lần tiếp nhận Xanh: bên đối lập có SĐT không trùng ai. */
function cvtRecord(User $actor, array $overrides = [], ?array $parties = null): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Trần Thị Mới',
        'contact_phone' => '0832270898',
        'contact_email' => 'moi@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties ?? [['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977000222']])->intake;
}

/** @return array<string, mixed> */
function cvtAttributes(User $lead, MatterType $type, array $overrides = []): array
{
    return [...[
        'title' => 'Tranh chấp hợp đồng mua bán',
        'matter_type_id' => $type->id,
        'lead_lawyer_id' => $lead->id,
        'client_role' => PartyRole::Plaintiff->value,
        'opened_at' => today()->toDateString(),
        'confidentiality' => Confidentiality::Normal->value,
        'client_type' => ClientType::Individual->value,
    ], ...$overrides];
}

function cvtConvert(User $actor, IntakeRequest $intake, array $overrides = [], ?string $overrideReason = null, ?ConflictLevel $ack = null, ?int $confirmedClientId = null)
{
    return app(ConvertIntakeToMatter::class)->handle($actor, $intake, cvtAttributes($actor, cvtType(), $overrides), $overrideReason, $ack, $confirmedClientId);
}

/** Khách hiện hữu D (SĐT 0912000111, không CCCD) có một vụ thường. */
function cvtClientD(): Client
{
    return cvtExistingClient(['phone' => '0912000111', 'name' => 'Công Ty D']);
}

/** Một khách hiện hữu có một vụ thường (luật sư tra được, M6.5 R4a). */
function cvtExistingClient(array $attributes): Client
{
    $client = Client::factory()->create([...['id_number' => null], ...$attributes]);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $client->id]))->ourClient($client)->create();

    return $client;
}

/** Câu đầu tiên của khoá `$key` trong lời từ chối của một lần gọi, hoặc tên lớp ngoại lệ khác, hoặc null. */
function cvtRefusal(Closure $call, string $key): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors()[$key][0] ?? (string) json_encode($e->errors());
    } catch (Throwable $e) {
        return $e::class;
    }

    return null;
}

/** @return array<string, int> số dòng của các bảng mà một lần chuyển đổi hỏng không được để lại gì. */
function cvtCounts(): array
{
    return [
        'matters' => Matter::query()->count(),
        'clients' => Client::query()->count(),
        'matter_parties' => MatterParty::query()->count(),
    ];
}

it('refuses an actor without intake.convert or without matter.create, before anything is looked up or written', function () {
    $assistant = cvtStaff(Role::Assistant);
    $intake = cvtRecord($assistant);
    $before = cvtCounts();
    $lookups = Activity::query()->where('event', 'client_lookup')->count();

    expect(fn () => cvtConvert($assistant, $intake))->toThrow(AuthorizationException::class)
        ->and(cvtCounts())->toBe($before)
        ->and(Activity::query()->where('event', 'client_lookup')->count())->toBe($lookups)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);

    // Có `intake.convert` nhưng mất `matter.create`: vẫn từ chối (R3 đòi CẢ HAI).
    $lawyer = cvtStaff();
    $own = cvtRecord($lawyer, ['contact_phone' => '0832270899']);
    SpatieRole::findByName(Role::Lawyer->value, 'web')->revokePermissionTo('matter.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => cvtConvert($lawyer->fresh(), $own))->toThrow(AuthorizationException::class)
        ->and($own->fresh()->matter_id)->toBeNull();
});

it('converts: one matter, the contact as the client, the opposing parties with their stored identities, the record won and linked', function () {
    // Người ghi bản ghi KHÁC người chuyển đổi: dấu "ai sửa cuối" phải là người chuyển đổi.
    $recorder = cvtStaff(Role::Assistant);
    $lawyer = cvtStaff();
    $intake = cvtRecord($recorder, ['quoted_amount' => '12.000.000', 'assigned_to' => $lawyer->id], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079 088 000 111'],
    ]);
    $party = $intake->parties()->sole();

    $result = cvtConvert($lawyer, $intake);
    $matter = $result->opening->matter->fresh();
    $client = Client::query()->findOrFail($matter->client_id);

    expect($result->clientCreated)->toBeTrue()
        ->and($client->id_number)->toBeNull()
        ->and($client->name)->toBe('Trần Thị Mới')
        ->and($client->phone)->toBe('0832270898')
        ->and($client->email)->toBe('moi@example.test')
        ->and($matter->code)->not->toBeEmpty();

    $opposing = $matter->parties()->where('is_our_client', false)->sole();
    $own = $matter->parties()->where('is_our_client', true)->sole();

    expect($opposing->name)->toBe('Công Ty Bên Kia')
        ->and($opposing->role)->toBe(PartyRole::Defendant)
        ->and($opposing->phone_normalized)->toBe($party->phone_normalized)
        ->and($opposing->id_number_hash)->toBe($party->id_number_hash)
        ->and($opposing->id_number_hash)->toBe(Normalizer::idNumberHash('079088000111'))
        ->and($own->client_id)->toBe($client->id)
        ->and($own->role)->toBe(PartyRole::Plaintiff);

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::Won)
        ->and($intake->matter_id)->toBe($matter->id)
        ->and($intake->client_id)->toBe($client->id)
        ->and($intake->updated_by)->toBe($lawyer->id);

    $log = Activity::query()->where('event', 'intake_converted')->sole();

    // Nhật ký tự động của model tắt cho lần lưu này (causer phải là actor, không phải phiên).
    expect(Activity::query()->where('subject_type', 'intake_request')->where('subject_id', $intake->id)->where('event', 'updated')->exists())->toBeFalse();

    expect($log->subject_id)->toBe($intake->id)
        ->and($log->causer_id)->toBe($lawyer->id)
        ->and($log->properties->all())->toBe(['matter_id' => $matter->id, 'client_id' => $client->id, 'client_created' => true]);
});

it('does not match the record being converted against itself through the second source', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);

    $result = cvtConvert($lawyer, $intake);

    expect($result->opening->result->level)->toBe(ConflictLevel::Green)
        ->and($result->opening->result->matches)->toBeEmpty();

    $run = Activity::query()->where('event', 'conflict_check_run')
        ->where('subject_type', 'matter')->where('subject_id', $result->opening->matter->id)->sole();

    expect(collect($run->properties['matches'] ?? [])->pluck('matter_code')->all())->not->toContain($intake->code);
});

it('still shows another open intake of the same person as an earlier contact, only the converted one is excluded', function () {
    $lawyer = cvtStaff();
    $earlier = cvtRecord($lawyer, ['contact_role' => PartyRole::Related, 'contact_name' => 'Trần Thị Mới']);
    $intake = cvtRecord($lawyer);

    expect(fn () => cvtConvert($lawyer, $intake))->toThrow(function (ConflictAcknowledgementRequired $e) use ($earlier, $intake): void {
        $codes = $e->result->matches->pluck('matterCode')->all();

        expect($codes)->toContain($earlier->code)->not->toContain($intake->code);
    });
});

it('refuses a record that cannot be converted, with the reason, and touches nothing', function (Closure $prepare, string $messageKey) {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);
    $prepare($intake, $lawyer);
    $before = cvtCounts();
    $runs = Activity::query()->whereIn('event', ['conflict_check_run', 'client_lookup'])->count();

    try {
        cvtConvert($lawyer, $intake->fresh());
        $this->fail('Chuyển đổi phải bị từ chối.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('intake')
            ->and($e->errors()['intake'][0])->toBe(__($messageKey, ['status' => $intake->fresh()->status->label()]));
    }

    // Từ chối ở bước khoá ĐẦU: không tra khách (không tốn suất), không chạy kiểm tra xung đột.
    expect(cvtCounts())->toBe($before)
        ->and(Activity::query()->whereIn('event', ['conflict_check_run', 'client_lookup'])->count())->toBe($runs);
})->with([
    'already converted (won + matter)' => [function (IntakeRequest $intake, User $lawyer): void {
        cvtConvert($lawyer, $intake);
    }, 'intake.errors.convert_already'],
    'matter linked while still open (forced)' => [function (IntakeRequest $intake): void {
        $intake->forceFill(['matter_id' => Matter::factory()->create()->id])->saveQuietly();
    }, 'intake.errors.convert_already'],
    'won without a matter (forced)' => [function (IntakeRequest $intake): void {
        $intake->forceFill(['status' => IntakeStatus::Won])->saveQuietly();
    }, 'intake.errors.convert_already'],
    'merged into another record' => [function (IntakeRequest $intake, User $lawyer): void {
        $target = cvtRecord($lawyer, ['contact_phone' => '0832270898', 'contact_name' => 'Trần Thị Mới']);
        app(MergeIntake::class)->handle($lawyer, $intake, $target);
    }, 'intake.errors.record_final'],
    'anonymised while still open (forced)' => [function (IntakeRequest $intake): void {
        $intake->forceFill(['anonymised_at' => now()])->saveQuietly();
    }, 'intake.errors.record_final'],
    'declined' => [function (IntakeRequest $intake, User $lawyer): void {
        app(DeclineIntake::class)->handle($lawyer, $intake, 'Ngoài lĩnh vực hành nghề');
    }, 'intake.errors.convert_status'],
    'lost' => [function (IntakeRequest $intake): void {
        $intake->forceFill(['status' => IntakeStatus::Lost])->saveQuietly();
    }, 'intake.errors.convert_status'],
    'red still waiting for a manager' => [function (IntakeRequest $intake): void {
        $intake->forceFill(['conflict_red_pending_since' => now()])->saveQuietly();
    }, 'intake.errors.convert_red_pending'],
]);

it('converts from every open status', function (IntakeStatus $status) {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);
    $intake->forceFill(['status' => $status])->saveQuietly();

    expect(cvtConvert($lawyer, $intake->fresh())->intake->status)->toBe(IntakeStatus::Won);
})->with([IntakeStatus::New, IntakeStatus::Contacted, IntakeStatus::Consulting, IntakeStatus::Quoted]);

it('lets a manager convert once the red of the record has been resolved', function () {
    $manager = cvtStaff(Role::Manager);
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null]);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    $intake = cvtRecord($manager, [], [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);

    expect($intake->hasUnresolvedRed())->toBeTrue();

    app(ResolveIntakeRedConflict::class)->handle($manager, $intake, 'Đã hỏi ý kiến, khách cũ đồng ý bằng văn bản');

    // `OpenMatter` chạy lại kiểm tra: vẫn Đỏ (khách hiện hữu là bị đơn) — quản lý ghi đè lần nữa ở
    // đúng cổng của `OpenMatter`, lý do đi vào `matter_opened`.
    expect(fn () => cvtConvert($manager, $intake->fresh()))->toThrow(ConflictBlocked::class);

    $result = cvtConvert($manager, $intake->fresh(), [], 'Khách cũ đồng ý bằng văn bản ngày 01/10');

    expect($result->opening->overridden)->toBeTrue()
        ->and($result->intake->status)->toBe(IntakeStatus::Won);
});

it('leaves no matter and no client when the record changes between the check and the save, and links nothing', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);
    $before = cvtCounts();

    // Một thay đổi XEN GIỮA bước kiểm tra và bước lưu của `OpenMatter` (lúc khoá dòng ở đầu đã nhả):
    // người khác từ chối bản ghi. Mô phỏng bằng cách ghi thẳng ngay sau lần kiểm tra xung đột.
    app()->bind(RunConflictCheck::class, fn () => new class extends RunConflictCheck
    {
        public function handle(Collection $parties, ?Matter $matter = null, ?User $actor = null, ?int $excludePartyId = null, ?Collection $ignoreConfirmedForPartyIds = null, ?Model $subject = null, ?int $excludeIntakeId = null): ConflictCheckResult
        {
            $result = parent::handle($parties, $matter, $actor, $excludePartyId, $ignoreConfirmedForPartyIds, $subject, $excludeIntakeId);

            DB::table('intake_requests')->where('id', $excludeIntakeId)->update(['status' => IntakeStatus::Declined->value]);

            return $result;
        }
    });

    expect(fn () => cvtConvert($lawyer, $intake))->toThrow(ValidationException::class);

    expect(cvtCounts())->toBe($before)
        ->and($intake->fresh()->matter_id)->toBeNull()
        ->and($intake->fresh()->client_id)->toBeNull()
        ->and(Activity::query()->where('event', 'intake_converted')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

it('makes the second of two conversions of the same record fail at the save, not open a second matter', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);
    $stale = $intake->fresh();

    // Lần thứ hai đã qua bước khoá đầu (bản trong bộ nhớ còn `new`) khi lần thứ nhất lưu xong: mô
    // phỏng bằng cách để "lần kia" hoàn tất ngay sau lần kiểm tra của lần này. Khoá `conflict-check`
    // không tái nhập, nên "lần kia" được giả lập bằng đúng thứ một lần chuyển đổi thành công để lại.
    $decorator = new class extends RunConflictCheck
    {
        public ?int $intakeId = null;

        public bool $done = false;

        public function handle(Collection $parties, ?Matter $matter = null, ?User $actor = null, ?int $excludePartyId = null, ?Collection $ignoreConfirmedForPartyIds = null, ?Model $subject = null, ?int $excludeIntakeId = null): ConflictCheckResult
        {
            $result = parent::handle($parties, $matter, $actor, $excludePartyId, $ignoreConfirmedForPartyIds, $subject, $excludeIntakeId);

            if (! $this->done && $excludeIntakeId !== null && $excludeIntakeId === $this->intakeId) {
                $this->done = true;
                $other = Matter::factory()->create();
                DB::table('intake_requests')->where('id', $this->intakeId)
                    ->update(['status' => IntakeStatus::Won->value, 'matter_id' => $other->id, 'client_id' => $other->client_id]);
            }

            return $result;
        }
    };
    $decorator->intakeId = $intake->id;
    app()->instance(RunConflictCheck::class, $decorator);

    $matters = Matter::query()->count();

    expect(fn () => cvtConvert($lawyer, $stale))->toThrow(ValidationException::class);

    // Chỉ còn đúng vụ của lần "kia"; không có vụ thứ hai.
    expect(Matter::query()->count())->toBe($matters + 1)
        ->and(IntakeRequest::query()->find($intake->id)->matter_id)->not->toBeNull();
});

it('requires the raw ID number typed at conversion to match the hash stored at intake, when one is stored', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer, ['contact_id_number' => '079 090 000 555']);

    try {
        cvtConvert($lawyer, $intake, ['client_id_number' => '079090000556']);
        $this->fail('Phải từ chối số không khớp.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('client_id_number');
    }

    expect($intake->fresh()->matter_id)->toBeNull();

    $result = cvtConvert($lawyer, $intake->fresh(), ['client_id_number' => '079090000555']);

    expect(Client::query()->findOrFail($result->opening->matter->client_id)->id_number)->toBe('079090000555')
        ->and($result->opening->matter->parties()->where('is_our_client', true)->sole()->id_number_hash)
        ->toBe(Normalizer::idNumberHash('079090000555'));

    // Không có chữ số nào: không phải một số căn cước.
    $noDigits = cvtRecord($lawyer, ['contact_phone' => '0832270666', 'contact_name' => 'Người Thứ Ba'], [['name' => 'Bên Thứ Ba', 'role' => PartyRole::Defendant, 'phone' => '0977000888']]);

    try {
        cvtConvert($lawyer, $noDigits, ['client_id_number' => 'không nhớ']);
        $this->fail('Phải từ chối.');
    } catch (ValidationException $e) {
        expect($e->errors()['client_id_number'][0])->toBe(__('intake.errors.id_number_invalid'));
    }

    // Bản ghi KHÔNG có dấu băm: số gõ lúc chuyển đổi không phải so với gì.
    $other = cvtRecord($lawyer, ['contact_phone' => '0832270777', 'contact_name' => 'Người Khác'], [['name' => 'Bên Khác Hẳn', 'role' => PartyRole::Defendant, 'phone' => '0977000999']]);
    $converted = cvtConvert($lawyer, $other, ['client_id_number' => '001122334455']);

    expect(Client::query()->findOrFail($converted->opening->matter->client_id)->id_number)->toBe('001122334455');
});

it('finds the existing client by the ID number typed at conversion before trying the phone', function () {
    $lawyer = cvtStaff();
    // Hai khách KHÁC nhau: một mang đúng SĐT của người liên hệ, một mang đúng số căn cước. Số căn
    // cước là định danh mạnh hơn (một số máy có thể dùng chung), nên nó được tra trước.
    Client::factory()->create(['phone' => '0832270898', 'id_number' => null, 'name' => 'Người Dùng Chung Máy']);
    $byIdNumber = Client::factory()->create(['phone' => '0900111222', 'id_number' => '079090000555', 'name' => 'Trần Thị Mới']);
    $intake = cvtRecord($lawyer, ['contact_id_number' => '079090000555']);

    // Lượt đầu: hệ thống nói khách nào nó định gắn (fix vòng 1, I1), chưa gắn gì.
    expect(fn () => cvtConvert($lawyer, $intake, ['client_id_number' => '079090000555'], null, ConflictLevel::Yellow))
        ->toThrow(fn (ExistingClientConfirmationRequired $e) => expect($e->client->id)->toBe($byIdNumber->id));

    $result = cvtConvert($lawyer, $intake->fresh(), ['client_id_number' => '079090000555'], null, ConflictLevel::Yellow, $byIdNumber->id);

    expect($result->clientCreated)->toBeFalse()
        ->and($result->opening->matter->client_id)->toBe($byIdNumber->id)
        ->and($result->intake->client_id)->toBe($byIdNumber->id)
        ->and(Activity::query()->where('event', 'intake_converted')->sole()->properties['client_created'])->toBeFalse();
});

it('validates the matter fields itself, for callers that are not the screen', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);

    try {
        app(ConvertIntakeToMatter::class)->handle($lawyer, $intake, [
            'client_role' => 'boss',
            'client_type' => 'robot',
            'confidentiality' => 'secret',
            'title' => str_repeat('a', 251),
            'description_internal' => str_repeat('ă', 30001),
            'court_name' => str_repeat('b', 201),
            'case_number' => str_repeat('c', 81),
            'client_id_number' => str_repeat('1', 21),
        ]);
        $this->fail('Phải từ chối.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing([
            'title', 'matter_type_id', 'lead_lawyer_id', 'client_role', 'client_type', 'confidentiality', 'opened_at',
            'description_internal', 'court_name', 'case_number', 'client_id_number',
        ]);
    }

    // Id không tồn tại.
    try {
        cvtConvert($lawyer, $intake->fresh(), ['matter_type_id' => 999999, 'lead_lawyer_id' => 999999]);
        $this->fail('Phải từ chối.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['matter_type_id', 'lead_lawyer_id']);
    }

    expect($intake->fresh()->matter_id)->toBeNull();

    // Đúng trần thì qua: 60.000 byte ghi chú, 250/200/80 ký tự.
    $ok = cvtConvert($lawyer, $intake->fresh(), [
        'title' => str_repeat('a', 250),
        'description_internal' => str_repeat('ă', 30000),
        'court_name' => str_repeat('b', 200),
        'case_number' => str_repeat('c', 80),
    ]);

    expect($ok->intake->status)->toBe(IntakeStatus::Won);
});

it('carries a known hash through the controlled path only: 64 lowercase hex, never together with a raw number', function () {
    $lawyer = cvtStaff();
    $client = Client::factory()->create();
    $type = cvtType();
    $attributes = ['client_id' => $client->id, 'client_role' => PartyRole::Plaintiff, 'matter_type_id' => $type->id, 'title' => 'X', 'lead_lawyer_id' => $lawyer->id, 'opened_at' => today()];
    $hash = Normalizer::idNumberHash('079088000111');

    $opened = app(OpenMatter::class)->handle($lawyer, $attributes, [
        ['role' => PartyRole::Defendant, 'name' => 'Bên Có Dấu Băm', 'phone' => '84977000222', 'id_number_hash' => $hash],
    ], acknowledged: ConflictLevel::Green);

    $party = $opened->matter->parties()->where('is_our_client', false)->sole();

    expect($party->id_number_hash)->toBe($hash)
        ->and($party->phone_normalized)->toBe('84977000222');

    expect(fn () => app(OpenMatter::class)->handle($lawyer, $attributes, [
        ['role' => PartyRole::Defendant, 'name' => 'Sai Dạng', 'id_number_hash' => '079088000111'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(OpenMatter::class)->handle($lawyer, $attributes, [
        ['role' => PartyRole::Defendant, 'name' => 'Cả Hai', 'id_number' => '079088000111', 'id_number_hash' => $hash],
    ]))->toThrow(InvalidArgumentException::class);
});

it('takes the converted record out of the second source: a later call by the same person meets the matter, not the old intake', function () {
    $lawyer = cvtStaff();
    $intake = cvtRecord($lawyer);
    $matter = cvtConvert($lawyer, $intake)->opening->matter;

    $later = cvtRecord($lawyer, ['contact_role' => PartyRole::Related], [['name' => 'Bên Mới', 'role' => PartyRole::Defendant, 'phone' => '0977000444']]);
    $codes = collect($later->conflict_result['matches'] ?? [])->pluck('matter_code')->all();

    expect($codes)->toContain($matter->code)->not->toContain($intake->code);
});

/*
 * Fix vòng 1 (rà soát Task 4). Hàm toàn cục mang tiền tố `cvt…` như trên.
 */

/** Chuyển đổi; nếu `OpenMatter` đòi xác nhận một mức Vàng/"thiếu định danh" thì xác nhận đúng mức đó. */
function cvtConvertAcknowledging(User $actor, IntakeRequest $intake, array $overrides = [], ?int $confirmedClientId = null)
{
    try {
        return cvtConvert($actor, $intake->fresh(), $overrides, null, null, $confirmedClientId);
    } catch (ConflictAcknowledgementRequired $e) {
        return cvtConvert($actor, $intake->fresh(), $overrides, null, $e->result->level, $confirmedClientId);
    }
}

/** Lần gọi ĐẦU của người gọi P (nguyên đơn, 0832270898): bên đối lập không có định danh — không Đỏ. */
function cvtFirstCallOfP(User $actor): IntakeRequest
{
    return cvtRecord($actor, ['contact_name' => 'Người Gọi P'], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant]]);
}

it('refuses a record whose caller is held by the repeat-call lock of another call, before any lookup, in the same words for a red and for a conflict decline', function (Closure $lockingCall) {
    cvtClientD();
    $lawyer = cvtStaff();
    $b = cvtFirstCallOfP($lawyer);
    $lockingCall($lawyer);

    // Chính B không có Đỏ nào: chỉ khoá người gọi lại của lần gọi kia giữ nó.
    expect($b->fresh()->hasUnresolvedRed())->toBeFalse();

    $before = cvtCounts();
    $runs = Activity::query()->whereIn('event', ['conflict_check_run', 'client_lookup'])->count();

    expect(cvtRefusal(fn () => cvtConvert($lawyer, $b->fresh(), [], null, ConflictLevel::Yellow), 'intake'))
        ->toBe(__('intake.errors.convert_caller_locked'))
        ->and(ConvertIntakeToMatter::refusal($b->fresh()))->toBe(__('intake.errors.convert_caller_locked'));

    expect(cvtCounts())->toBe($before)
        ->and(Activity::query()->whereIn('event', ['conflict_check_run', 'client_lookup'])->count())->toBe($runs)
        ->and($b->fresh()->status)->toBe(IntakeStatus::New);
})->with([
    // P gọi lại, nêu tên khách hiện hữu D kèm SĐT của D: Đỏ chờ quản lý.
    'the other call still red' => [fn (User $lawyer) => cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [
        ['name' => 'Công Ty D', 'role' => PartyRole::Defendant, 'phone' => '0912000111'],
    ])],
    // P gọi lại, không Đỏ nào trong hệ thống, nhưng quản lý từ chối vì xung đột (R8).
    'the other call declined for a conflict' => [function (User $lawyer): void {
        $other = cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [['name' => 'Bên Nào Đó', 'role' => PartyRole::Defendant]]);

        expect($other->hasUnresolvedRed())->toBeFalse();

        app(DeclineIntake::class)->handle(cvtStaff(Role::Manager), $other, 'Bên kia là khách hiện hữu của văn phòng', true);
    }],
]);

it('reads the contact role the conflict check reads: a role not declared is implied from the opposing party', function () {
    cvtClientD();
    $lawyer = cvtStaff();
    // Không khai vai; bên đối lập là bị đơn → lần kiểm tra coi người liên hệ là nguyên đơn.
    $b = cvtRecord($lawyer, ['contact_name' => 'Người Gọi P', 'contact_role' => null], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant]]);
    cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [['name' => 'Công Ty D', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);

    expect($b->fresh()->contact_role)->toBeNull()
        ->and(cvtRefusal(fn () => cvtConvert($lawyer, $b->fresh(), [], null, ConflictLevel::Yellow), 'intake'))
        ->toBe(__('intake.errors.convert_caller_locked'));
});

it('is not held by another call of the same number that does not lock, or that declared another role', function (Closure $otherCall) {
    cvtClientD();
    $lawyer = cvtStaff();
    $b = cvtFirstCallOfP($lawyer);
    $otherCall($lawyer);

    expect(ConvertIntakeToMatter::refusal($b->fresh()))->toBeNull()
        ->and(cvtConvertAcknowledging($lawyer, $b)->intake->status)->toBe(IntakeStatus::Won);
})->with([
    'same caller, an ordinary open call' => [fn (User $lawyer) => cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [
        ['name' => 'Bên Khác', 'role' => PartyRole::Defendant, 'phone' => '0977000555'],
    ])],
    // Cùng số máy, vai khác (bị đơn) và Đỏ: không phải cùng người gọi lại (vợ chồng chung máy bàn).
    'same number, another declared role, red' => [function (User $lawyer): void {
        $other = cvtRecord($lawyer, ['contact_name' => 'Người Gọi Q', 'contact_role' => PartyRole::Defendant], [
            ['name' => 'Công Ty D', 'role' => PartyRole::Plaintiff, 'phone' => '0912000111'],
        ]);

        expect($other->hasUnresolvedRed())->toBeTrue();
    }],
]);

it('lets the conversion through once a manager has overridden the lock on this record itself', function () {
    cvtClientD();
    $lawyer = cvtStaff();
    $manager = cvtStaff(Role::Manager);
    $b = cvtFirstCallOfP($lawyer);
    $a = cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [['name' => 'Công Ty D', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);

    expect(ConvertIntakeToMatter::refusal($b->fresh()))->toBe(__('intake.errors.convert_caller_locked'));

    app(ResolveIntakeRedConflict::class)->handle($manager, $b->fresh(), 'Đã xem cả hai lần gọi, nhận phần việc không liên quan Công Ty D');

    $result = cvtConvertAcknowledging($manager, $b);

    expect($result->intake->status)->toBe(IntakeStatus::Won)
        // Lần gọi kia vẫn chờ quản lý xử lý riêng: ghi đè trên B không xử lý Đỏ của nó.
        ->and($a->fresh()->hasUnresolvedRed())->toBeTrue();
});

it('rolls the save back when the lock of another call appears between the check and the save', function () {
    $lawyer = cvtStaff();
    $b = cvtFirstCallOfP($lawyer);
    $a = cvtRecord($lawyer, ['contact_name' => 'Người Gọi P'], [['name' => 'Bên Khác', 'role' => PartyRole::Defendant, 'phone' => '0977000555']]);
    $before = cvtCounts();

    $decorator = new class extends RunConflictCheck
    {
        public ?int $lockAfterCheckOf = null;

        public ?int $otherId = null;

        public function handle(Collection $parties, ?Matter $matter = null, ?User $actor = null, ?int $excludePartyId = null, ?Collection $ignoreConfirmedForPartyIds = null, ?Model $subject = null, ?int $excludeIntakeId = null): ConflictCheckResult
        {
            $result = parent::handle($parties, $matter, $actor, $excludePartyId, $ignoreConfirmedForPartyIds, $subject, $excludeIntakeId);

            if ($excludeIntakeId !== null && $excludeIntakeId === $this->lockAfterCheckOf) {
                DB::table('intake_requests')->where('id', $this->otherId)->update(['conflict_red_pending_since' => now()]);
            }

            return $result;
        }
    };
    $decorator->lockAfterCheckOf = $b->id;
    $decorator->otherId = $a->id;
    app()->instance(RunConflictCheck::class, $decorator);

    // Lượt đã xác nhận đúng mức (Vàng: lần gọi kia là "đã liên hệ"), nên lượt này đi tới bước lưu.
    expect(cvtRefusal(fn () => cvtConvert($lawyer, $b->fresh(), [], null, ConflictLevel::Yellow), 'intake'))
        ->toBe(__('intake.errors.convert_caller_locked'));

    expect(cvtCounts())->toBe($before)
        ->and($b->fresh()->matter_id)->toBeNull()
        ->and($b->fresh()->status)->toBe(IntakeStatus::New)
        ->and(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

it('shows the existing client it would attach and attaches only once that exact client is confirmed', function () {
    $lawyer = cvtStaff();
    // Cùng số máy, KHÁC tên: chỉ người bấm biết đây có phải cùng một người không.
    $mother = cvtExistingClient(['phone' => '0832270898', 'name' => 'Nguyễn Thị Mẹ']);
    $someoneElse = cvtExistingClient(['phone' => '0900111333', 'name' => 'Người Khác Hẳn']);
    $intake = cvtRecord($lawyer);
    $before = cvtCounts();
    $runs = Activity::query()->where('event', 'conflict_check_run')->count();

    foreach ([null, $someoneElse->id] as $confirmed) {
        try {
            cvtConvert($lawyer, $intake->fresh(), [], null, null, $confirmed);
            $this->fail('Phải hỏi xác nhận trước khi gắn.');
        } catch (ExistingClientConfirmationRequired $e) {
            expect($e->client->id)->toBe($mother->id)
                ->and($e->getMessage())->toBe(__('intake.errors.convert_client_confirmation_required', ['code' => $mother->code, 'name' => 'Nguyễn Thị Mẹ']));
        }
    }

    // Chưa gắn gì, chưa mở gì, chưa chạy kiểm tra xung đột nào.
    expect(cvtCounts())->toBe($before)
        ->and(Activity::query()->where('event', 'conflict_check_run')->count())->toBe($runs)
        ->and($intake->fresh()->matter_id)->toBeNull();

    $result = cvtConvertAcknowledging($lawyer, $intake, [], $mother->id);

    expect($result->clientCreated)->toBeFalse()
        ->and($result->opening->matter->client_id)->toBe($mother->id)
        ->and(Client::query()->count())->toBe($before['clients']);
});

it('never attaches a client found by phone whose ID number differs from the one known for the contact', function (array $typed, ?string $recorded) {
    $lawyer = cvtStaff();
    $mother = cvtExistingClient(['phone' => '0832270898', 'id_number' => '079080000111', 'name' => 'Nguyễn Thị Mẹ']);
    // Con gọi bằng máy của mẹ, khai số căn cước của chính mình (lúc tiếp nhận, lúc chuyển đổi, hay cả hai).
    $intake = cvtRecord($lawyer, ['contact_name' => 'Nguyễn Thị Con', 'contact_id_number' => $recorded]);
    $before = cvtCounts();

    // Kể cả khi người gọi Action "đã xác nhận" đúng hồ sơ đó.
    expect(cvtRefusal(fn () => cvtConvert($lawyer, $intake->fresh(), $typed, null, ConflictLevel::Yellow, $mother->id), 'client_id_number'))
        ->toBe(__('intake.errors.convert_client_id_differs', ['code' => $mother->code, 'name' => 'Nguyễn Thị Mẹ']));

    expect(cvtCounts())->toBe($before)
        ->and($intake->fresh()->matter_id)->toBeNull();
})->with([
    'ID number recorded at intake, not typed again' => [[], '079090000555'],
    'ID number recorded at intake and typed again' => [['client_id_number' => '079 090 000 555'], '079090000555'],
    'ID number typed at conversion only' => [['client_id_number' => '079 090 000 555'], null],
]);

it('attaches a client found by phone whose ID number does not contradict the contact', function (?string $recorded) {
    $lawyer = cvtStaff();
    $same = cvtExistingClient(['phone' => '0832270898', 'id_number' => '079090000555', 'name' => 'Trần Thị Mới']);
    $intake = cvtRecord($lawyer, ['contact_id_number' => $recorded]);

    $result = cvtConvertAcknowledging($lawyer, $intake, [], $same->id);

    expect($result->opening->matter->client_id)->toBe($same->id)
        ->and($result->clientCreated)->toBeFalse();
})->with([
    'the same ID number recorded at intake' => ['079090000555'],
    // Người liên hệ không khai số căn cước: không có gì để mâu thuẫn — chỉ cần xác nhận đúng người.
    'no ID number known for the contact' => [null],
]);

it('refuses to drop an ID number typed for an existing client that has none on file, and attaches when it is left empty', function () {
    $lawyer = cvtStaff();
    $existing = cvtExistingClient(['phone' => '0832270898', 'name' => 'Trần Thị Mới']);
    $intake = cvtRecord($lawyer, ['contact_id_number' => '079090000555']);
    $before = cvtCounts();

    expect(cvtRefusal(fn () => cvtConvert($lawyer, $intake->fresh(), ['client_id_number' => '079090000555'], null, ConflictLevel::Yellow, $existing->id), 'client_id_number'))
        ->toBe(__('intake.errors.convert_id_number_not_carried', ['code' => $existing->code, 'name' => 'Trần Thị Mới']));

    expect(cvtCounts())->toBe($before);

    // Không gõ lại số: không có gì bị bỏ âm thầm (dấu băm lúc tiếp nhận không bao giờ là số trên hồ sơ khách).
    $result = cvtConvertAcknowledging($lawyer, $intake, [], $existing->id);

    expect($result->opening->matter->client_id)->toBe($existing->id)
        ->and($existing->fresh()->id_number)->toBeNull();
});

it('takes no more story, notice or conflict-check writes once the record has been converted', function () {
    $lawyer = cvtStaff();
    $manager = cvtStaff(Role::Manager);

    $withStory = cvtRecord($lawyer);
    app(RecordPrivacyNotice::class)->handle($lawyer, $withStory, true);
    app(UpdateIntakeSummary::class)->handle($lawyer, $withStory->fresh(), 'Câu chuyện gốc.');
    cvtConvert($lawyer, $withStory->fresh());

    $withoutNotice = cvtRecord($lawyer, ['contact_phone' => '0832270111', 'contact_name' => 'Người Thứ Hai'], [
        ['name' => 'Bên Thứ Hai', 'role' => PartyRole::Defendant, 'phone' => '0977000666'],
    ]);
    cvtConvert($lawyer, $withoutNotice->fresh());

    $this->travel(5)->minutes();

    $checkedAt = $withStory->fresh()->conflict_checked_at->toIso8601String();
    $runs = Activity::query()->where('event', 'conflict_check_run')->count();
    $closed = __('intake.errors.record_closed');

    expect(cvtRefusal(fn () => app(UpdateIntakeSummary::class)->handle($lawyer, $withStory->fresh(), null), 'summary'))->toBe($closed)
        ->and(cvtRefusal(fn () => app(UpdateIntakeSummary::class)->handle($lawyer, $withStory->fresh(), 'Viết đè sau khi chuyển'), 'summary'))->toBe($closed)
        ->and(cvtRefusal(fn () => app(RecordPrivacyNotice::class)->handle($lawyer, $withoutNotice->fresh(), true), 'intake'))->toBe($closed)
        ->and(cvtRefusal(fn () => app(RerunIntakeConflictCheck::class)->handle($lawyer, $withStory->fresh()), 'intake'))->toBe($closed)
        ->and(cvtRefusal(fn () => app(AcknowledgeIntakeConflict::class)->handle($lawyer, $withStory->fresh(), ConflictLevel::Green), 'intake'))->toBe($closed)
        ->and(cvtRefusal(fn () => app(ResolveIntakeRedConflict::class)->handle($manager, $withStory->fresh(), 'Ghi đè sau khi đã chuyển thành vụ'), 'intake'))->toBe($closed);

    expect($withStory->fresh()->summary)->toBe('Câu chuyện gốc.')
        ->and($withStory->fresh()->conflict_checked_at->toIso8601String())->toBe($checkedAt)
        ->and($withStory->fresh()->conflict_overridden_by)->toBeNull()
        ->and($withoutNotice->fresh()->privacy_notice_acknowledged_at)->toBeNull()
        ->and(Activity::query()->where('event', 'conflict_check_run')->count())->toBe($runs);
});
