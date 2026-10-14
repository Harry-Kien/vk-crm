<?php

use App\Actions\Intake\AcknowledgeIntakeConflict;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\ResolveIntakeRedConflict;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\ClientType;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Audit;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Livewire\Features\SupportTesting\Testable;

/*
 * Làn fa, mục A1 của đợt kiểm tra nghiệp vụ 2026-10-09 — khách là DOANH NGHIỆP. Người gọi (ông A, giám
 * đốc Công ty X) gọi thay cho công ty: trước bản sửa, form tiếp nhận không có chỗ nào cho tổ chức, nên
 * kiểm tra xung đột lúc tiếp nhận chạy trên tên ông A, và lúc chuyển đổi hồ sơ khách "tổ chức" mang tên
 * ông A — Công ty X không bao giờ đi qua kiểm tra nào. Mọi hành vi đi qua màn hình (Livewire), trừ
 * dấu vân tay danh tính (một hàm của model, ghim rằng bản ghi cũ KHÔNG bị mở lại cổng kiểm tra).
 *
 * Hàm toàn cục mang tiền tố `iorg…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function iorgStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

/** Một vụ việc đang làm mà Công ty X là BỊ ĐƠN (không phải khách của văn phòng). */
function iorgMatterAgainst(string $name, ?string $taxCode = null): Matter
{
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->defendant()->identify($taxCode, null)->create(['name' => $name]);

    return $matter;
}

function iorgCreateData(array $overrides = []): array
{
    return [...[
        'contact_name' => 'Nguyễn Văn An',
        'contact_phone' => '0901234567',
        'contact_role' => PartyRole::Plaintiff->value,
        'source' => IntakeSource::Phone->value,
        'privacy_notice' => true,
        'parties' => [[
            'role' => PartyRole::Defendant->value,
            'name' => 'Bị Đơn Chưa Ai Biết',
            'phone' => '0977000111',
            'id_number' => null,
        ]],
    ], ...$overrides];
}

/** Một bản ghi Xanh của một tổ chức, câu chuyện đã ghi — sẵn sàng chuyển đổi. */
function iorgRecord(User $actor, array $overrides = []): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Nguyễn Văn An',
        'contact_phone' => '0901234567',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Kinh doanh thương mại'])->id,
        'organization_name' => 'Công ty TNHH Thương mại Sao Mai',
        'organization_id_number' => '0101234567',
    ], ...$overrides], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    // Tổ chức không có mã số thuế là một bên thiếu định danh: câu chuyện chỉ mở sau khi đã xác nhận xem.
    if (($intake->fresh()->conflict_result['incomplete_parties'] ?? []) !== []) {
        app(AcknowledgeIntakeConflict::class)->handle($actor, $intake->fresh(), $intake->fresh()->conflict_level);
    }
    app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), 'Công ty bị đối tác chậm thanh toán ba hoá đơn.');

    return $intake->fresh();
}

it('runs the conflict check on the organisation the caller speaks for: a company that is the defendant of a matter turns the call yellow', function () {
    iorgMatterAgainst('Công ty TNHH Xây dựng Hoà Bình');
    $this->actingAs(iorgStaff(Role::Assistant), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(iorgCreateData(['organization_name' => 'CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH']))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();
    $match = collect($intake->conflict_result['matches'])->sole();

    expect($intake->contact_name)->toBe('Nguyễn Văn An')
        ->and($intake->organization_name)->toBe('CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH')
        ->and($intake->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($match['party_name'])->toBe('Công ty TNHH Xây dựng Hoà Bình')
        ->and($match['our_party_name'])->toBe('CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH')
        ->and($match['our_party_role'])->toBe(PartyRole::Plaintiff->value);
});

it('matches the organisation by its tax code, never storing the raw number', function () {
    iorgMatterAgainst('Một Tên Khác Hẳn', '0101234567');
    $this->actingAs(iorgStaff(Role::Assistant), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(iorgCreateData(['organization_name' => 'Công ty Sao Mai', 'organization_id_number' => '0101 234 567']))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();

    expect($intake->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and(collect($intake->conflict_result['matches'])->sole()['tier'])->toBe(ConflictMatchTier::Hash->value)
        ->and($intake->organization_id_number_hash)->toBe(Normalizer::idNumberHash('0101234567'))
        ->and(json_encode($intake->getAttributes()))->not->toContain('0101234567');
});

it('refuses a tax code without the name of the organisation, on the field', function () {
    $this->actingAs(iorgStaff(Role::Assistant), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(iorgCreateData(['organization_id_number' => '0101234567']))
        ->call('create')
        ->assertHasFormErrors(['organization_name']);

    expect(IntakeRequest::query()->count())->toBe(0);
});

it('rechecks when the organisation is added on the edit page, and finds the company', function () {
    $assistant = iorgStaff(Role::Assistant);
    iorgMatterAgainst('Công ty Cổ phần Bình Minh');
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)->fillForm(iorgCreateData())->call('create')->assertHasNoFormErrors();
    $intake = IntakeRequest::query()->sole();
    expect($intake->conflict_level)->toBe(ConflictLevel::Green);

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['organization_name' => null, 'organization_id_number' => null])
        ->fillForm(['organization_name' => 'Công ty Cổ phần Bình Minh'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($intake->fresh()->organization_name)->toBe('Công ty Cổ phần Bình Minh');
});

it('finds the organisation of an earlier open call when a later caller names that company as the other side', function () {
    $assistant = iorgStaff(Role::Assistant);
    $earlier = iorgRecord($assistant);
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(iorgCreateData([
            'contact_name' => 'Người Gọi Khác',
            'contact_phone' => '0911222333',
            'parties' => [['role' => PartyRole::Defendant->value, 'name' => 'Công ty TNHH Thương mại Sao Mai', 'phone' => null, 'id_number' => null]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $later = IntakeRequest::query()->whereKeyNot($earlier->getKey())->sole();
    $match = collect($later->conflict_result['matches'])->sole();

    expect($later->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($match['matter_code'])->toBe($earlier->code)
        ->and($match['party_name'])->toBe('Công ty TNHH Thương mại Sao Mai');
});

it('labels the identifier of an opposing party as an ID card or tax code, and caps the new fields at their columns', function () {
    $assistant = iorgStaff(Role::Assistant);
    $this->actingAs($assistant, 'web');
    $intake = iorgRecord($assistant);

    $component = $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
    $fields = $component->instance()->getSchema('form')->getFlatFields(withHidden: true);

    expect($fields['organization_name']->getMaxLength())->toBe(200)
        ->and($fields['organization_id_number']->getMaxLength())->toBe(30);

    $partyFields = collect(collect($fields['parties']->getDefaultChildSchemas())->first()->getFlatFields(withHidden: true))
        ->filter(fn (Field $field): bool => $field instanceof TextInput);

    expect($partyFields['id_number']->getLabel())->toBe('Số căn cước / mã số thuế');
});

it('converts an organisation into a client named after the company, the caller as its representative, the tax code as its identifier', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer);
    $this->actingAs($lawyer, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet([
            'client_type' => ClientType::Organization->value,
            'title' => 'Công ty TNHH Thương mại Sao Mai — Kinh doanh thương mại',
        ])
        ->assertSee(__('intake_org.convert.organization_line', ['name' => 'Công ty TNHH Thương mại Sao Mai']))
        ->fillForm(['client_id_number' => '0101234567'])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->sole();
    $client = Client::query()->findOrFail($matter->client_id);

    expect($client->type)->toBe(ClientType::Organization)
        ->and($client->name)->toBe('Công ty TNHH Thương mại Sao Mai')
        ->and($client->representative_name)->toBe('Nguyễn Văn An')
        ->and($client->id_number)->toBe('0101234567')
        ->and($matter->parties()->where('is_our_client', true)->sole()->name)->toBe('Công ty TNHH Thương mại Sao Mai');
});

it('refuses a tax code typed at conversion that differs from the one recorded for the organisation', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer);
    $this->actingAs($lawyer, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['client_id_number' => '0109999999'])
        ->call('convert')
        ->assertHasFormErrors(['client_id_number']);

    expect(Matter::query()->count())->toBe(0);
});

it('refuses to convert into an organisation when the record names no organisation, instead of naming the company after the caller', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer, ['organization_name' => null, 'organization_id_number' => null]);
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['client_type' => ClientType::Individual->value])
        ->fillForm(['client_type' => ClientType::Organization->value])
        ->call('convert')
        ->assertHasFormErrors(['client_type']);

    expect(collect($component->errors()->get('data.client_type'))->implode(' '))->toBe(__('intake_org.errors.convert_needs_organization'))
        ->and(Matter::query()->count())->toBe(0)
        ->and(Client::query()->count())->toBe(0);
});

it('keeps the identity fingerprint of a record without an organisation exactly as before, so no saved check goes stale', function () {
    $assistant = iorgStaff(Role::Assistant);
    $intake = app(RecordIntake::class)->handle($assistant, [
        'contact_name' => 'Người Gọi Cũ', 'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ], [['name' => 'Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977000111']])->intake;

    $party = $intake->parties()->sole();
    $legacy = Audit::identifierHash((string) json_encode([
        PartyRole::Plaintiff->value,
        $intake->contact_name_normalized,
        $intake->contact_phone_normalized,
        null,
        [implode('|', [$party->role->value, $party->name_normalized, $party->phone_normalized, $party->id_number_hash])],
    ]));

    expect($intake->identityFingerprint())->toBe($legacy);
});

it('erases the organisation with the rest of the prospect data', function () {
    $intake = iorgRecord(iorgStaff(Role::Assistant));
    $this->actingAs(iorgStaff(Role::Admin), 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->callAction('eraseData', data: ['erase_reason' => 'Người liên hệ yêu cầu xoá toàn bộ dữ liệu.'])
        ->assertHasNoActionErrors();

    $intake->refresh();

    expect($intake->anonymised_at)->not->toBeNull()
        ->and($intake->organization_name)->toBeNull()
        ->and($intake->organization_name_normalized)->toBeNull()
        ->and($intake->organization_id_number_hash)->toBeNull();
});

it('keeps the recorded tax code when the field is left blank, and drops it with the organisation', function () {
    $assistant = iorgStaff(Role::Assistant);
    $intake = iorgRecord($assistant);
    $hash = $intake->organization_id_number_hash;
    $this->actingAs($assistant, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['organization_name' => 'Công ty TNHH Thương mại Sao Mai (đổi tên)'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->organization_id_number_hash)->toBe($hash);

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['organization_name' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->organization_name)->toBeNull()
        ->and($intake->fresh()->organization_id_number_hash)->toBeNull();
});

it('tells a lawyer before converting that a red overridden by the manager needs the manager to convert, and not the manager', function () {
    $client = Client::factory()->create(['phone' => '0912000111', 'id_number' => null, 'name' => 'Khách Hiện Hữu']);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $client->id]))->ourClient($client)->create();
    $lawyer = iorgStaff();
    $manager = iorgStaff(Role::Manager);
    $intake = app(RecordIntake::class)->handle($lawyer, [
        'contact_name' => 'Người Gọi Đỏ', 'contact_phone' => '0901999888',
        'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ], [['name' => 'Bị Đơn Là Khách', 'role' => PartyRole::Defendant, 'phone' => '0912000111']])->intake;
    app(ResolveIntakeRedConflict::class)->handle($manager, $intake->fresh(), 'Đã hỏi khách hiện hữu, đồng ý cho nhận vụ này.');
    $notice = __('intake_org.override_needs_manager');

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])->assertSee($notice);
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])->assertSee($notice);

    $this->actingAs($manager, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])->assertDontSee($notice);
});

/*
 * Fix vòng 1, I1 — người gọi cho công ty ĐÃ là khách CÁ NHÂN của văn phòng (cùng số điện thoại, có số
 * căn cước). Chuyển đổi "Tổ chức" không được tra theo số điện thoại của người gọi: trước bản sửa, mã số
 * thuế bị đem so với CCCD của ông An (lỗi "số căn cước khác", không chuyển đổi được), và khi không có mã
 * số thuế thì màn hình đòi xác nhận gắn hồ sơ CÁ NHÂN của ông An làm khách của vụ công ty (lỗi A1 cũ).
 */
function iorgEarlierIndividualClient(): Client
{
    $client = Client::factory()->create([
        'type' => ClientType::Individual,
        'name' => 'Nguyễn Văn An',
        'phone' => '0901234567',
        'id_number' => '079088123456',
    ]);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $client->id]))->ourClient($client)->create();

    return $client;
}

/** Bấm "Chuyển đổi"; nếu lần kiểm tra lại ra một khớp cần xác nhận đã xem, tích ô đó và bấm lại. */
function iorgConvert(Testable $page): Testable
{
    $page->call('convert');

    if ($page->errors()->has('data.acknowledge_conflict')) {
        $page->fillForm(['acknowledge_conflict' => true])->call('convert');
    }

    return $page;
}

/** Vụ mới duy nhất do lần chuyển đổi mở (các vụ dựng sẵn của test bị loại theo id). */
function iorgConvertedMatter(Client ...$existing): Matter
{
    return Matter::query()->whereNotIn('client_id', array_map(fn (Client $c): int => $c->getKey(), $existing))->sole();
}

it('converts the company of a caller who is already an individual client, by its tax code, into a new organisation client', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer);
    $an = iorgEarlierIndividualClient();
    $this->actingAs($lawyer, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['client_id_number' => '0101234567'])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasNoFormErrors()
        ->assertSet('matchedClientId', null);

    $client = Client::query()->findOrFail(iorgConvertedMatter($an)->client_id);

    expect($client->type)->toBe(ClientType::Organization)
        ->and($client->name)->toBe('Công ty TNHH Thương mại Sao Mai')
        ->and($client->id_number)->toBe('0101234567')
        ->and($client->representative_name)->toBe('Nguyễn Văn An')
        ->and($client->phone)->toBe('0901234567')
        ->and($an->fresh()->type)->toBe(ClientType::Individual)
        ->and($an->fresh()->id_number)->toBe('079088123456');
});

it('never offers the individual record of the caller as the client of the company when no tax code is known', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer, ['organization_id_number' => null]);
    $an = iorgEarlierIndividualClient();
    $this->actingAs($lawyer, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasNoFormErrors()
        ->assertSet('matchedClientId', null);

    $client = Client::query()->findOrFail(iorgConvertedMatter($an)->client_id);

    expect($client->type)->toBe(ClientType::Organization)
        ->and($client->name)->toBe('Công ty TNHH Thương mại Sao Mai');
});

it('does the same for a manager, who would otherwise be warned of a duplicate of the individual record', function () {
    $manager = iorgStaff(Role::Manager);
    $intake = iorgRecord($manager, ['organization_id_number' => null]);
    $an = iorgEarlierIndividualClient();
    $this->actingAs($manager, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasNoFormErrors();

    expect(Client::query()->findOrFail(iorgConvertedMatter($an)->client_id)->type)->toBe(ClientType::Organization);
});

it('offers an existing organisation client found by the tax code for confirmation, then attaches it', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer);
    $company = Client::factory()->create(['type' => ClientType::Organization, 'name' => 'Sao Mai hồ sơ cũ', 'phone' => '0287000111', 'id_number' => '0101234567']);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $company->id]))->ourClient($company)->create();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['client_id_number' => '0101234567'])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasFormErrors(['confirm_existing_client'])
        ->assertSet('matchedClientId', $company->getKey());

    iorgConvert($component->fillForm(['confirm_existing_client' => true]))->assertHasNoFormErrors();

    expect(Matter::query()->where('client_id', $company->getKey())->count())->toBe(2);
});

it('refuses a tax code that belongs to an individual client record, with a message about organisations', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer);
    $wrong = Client::factory()->create(['type' => ClientType::Individual, 'name' => 'Hồ Sơ Gõ Nhầm', 'phone' => '0287000999', 'id_number' => '0101234567']);
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->fillForm(['client_id_number' => '0101234567'])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasFormErrors(['client_id_number'])
        ->assertSet('matchedClientId', null);

    expect(collect($component->errors()->get('data.client_id_number'))->implode(' '))
        ->toBe(__('intake_org.errors.convert_existing_client_type_differs.organization', ['code' => $wrong->code, 'name' => $wrong->name]))
        ->and(Matter::query()->count())->toBe(0);
});

it('refuses to attach an individual caller to an organisation client found by the phone, instead of asking to confirm it', function () {
    $lawyer = iorgStaff();
    $intake = iorgRecord($lawyer, ['organization_name' => null, 'organization_id_number' => null]);
    $company = Client::factory()->create(['type' => ClientType::Organization, 'name' => 'Công ty Có Số Của Ông An', 'phone' => '0901234567', 'id_number' => null]);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $company->id]))->ourClient($company)->create();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['client_type' => ClientType::Individual->value])
        ->tap(fn ($page) => iorgConvert($page))
        ->assertHasFormErrors(['client_id_number'])
        ->assertSet('matchedClientId', null);

    expect(collect($component->errors()->get('data.client_id_number'))->implode(' '))
        ->toBe(__('intake_org.errors.convert_existing_client_type_differs.individual', ['code' => $company->code, 'name' => $company->name]))
        ->and(Matter::query()->count())->toBe(1);
});
