<?php

use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\ClientLookupThrottle;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/*
 * M10 Task 4 — chuyển một lần tiếp nhận thành vụ việc QUA MÀN HÌNH (R3): nút "Chuyển thành vụ việc"
 * trên trang làm việc của bản ghi, trang chuyển đổi điền sẵn mọi thứ từ bản ghi, dùng lại hai lượt
 * xác nhận/ghi đè của form mở vụ, và gợi ý `quoted_amount` ở form soạn hợp đồng M9. Luật riêng của
 * Action (từng nhánh từ chối, lần kiểm tra lại có khoá, dấu băm) ở
 * `tests/Feature/Intake/ConvertIntakeToMatterTest.php`.
 *
 * Hàm toàn cục mang tiền tố `cvs…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function cvsStaff(Role $role = Role::Lawyer): User
{
    return User::factory()->withRole($role)->create();
}

function cvsType(): MatterType
{
    return MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Dân sự']);
}

/**
 * Một lần tiếp nhận Xanh đầy đủ: người liên hệ là nguyên đơn, một bên đối lập CÓ SĐT và CCCD (không
 * trùng ai), lĩnh vực, phí đã báo, thông báo đã ghi nhận và câu chuyện đã ghi.
 */
function cvsRecord(User $actor, array $overrides = [], ?array $parties = null): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Trần Thị Mới',
        'contact_phone' => '0832 270 898',
        'contact_email' => 'moi@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => cvsType()->id,
        'quoted_amount' => '15.000.000',
    ], ...$overrides], $parties ?? [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;

    if ($intake->conflict_level?->value === 'green' && $intake->conflict_result['incomplete_parties'] === []) {
        app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
        app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');
    }

    return $intake->fresh();
}

function cvsPage(IntakeRequest $intake)
{
    return test()->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

function cvsEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

/** Một khách hiện hữu có một vụ THƯỜNG — luật sư tra được (M6.5 R4a). */
function cvsExistingClient(array $attributes): Client
{
    $client = Client::factory()->create([...['id_number' => null], ...$attributes]);
    MatterParty::factory()->for(Matter::factory()->create(['client_id' => $client->id]))->ourClient($client)->create();

    return $client;
}

it('hides the conversion from an assistant, and answers 404 to the page and to the Livewire component', function () {
    $assistant = cvsStaff(Role::Assistant);
    $intake = cvsRecord($assistant);

    $this->actingAs($assistant, 'web');
    cvsEdit($intake)->assertActionHidden('convert');

    $this->get(IntakeRequestResource::getUrl('convert', ['record' => $intake], panel: 'admin'))->assertNotFound();
    cvsPage($intake)->assertNotFound();

    expect(Matter::query()->count())->toBe(0)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);

    // Luật sư được giao bản ghi đó thì thấy nút, và nút dẫn tới trang chuyển đổi.
    $lawyer = cvsStaff();
    $intake->forceFill(['assigned_to' => $lawyer->id])->save();
    $this->actingAs($lawyer, 'web');
    cvsEdit($intake)
        ->assertActionVisible('convert')
        ->assertActionHasUrl('convert', IntakeRequestResource::getUrl('convert', ['record' => $intake], panel: 'admin'));
    $this->get(IntakeRequestResource::getUrl('convert', ['record' => $intake], panel: 'admin'))->assertOk();
});

it('answers 404 to a lawyer who cannot see the record', function () {
    $intake = cvsRecord(cvsStaff());

    $this->actingAs(cvsStaff(), 'web');
    $this->get(IntakeRequestResource::getUrl('convert', ['record' => $intake], panel: 'admin'))->assertNotFound();
});

it('converts a brand-new person with nothing typed again: a client, a coded matter, the parties carried over', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);
    $party = $intake->parties()->sole();

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->assertFormSet([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $intake->matter_type_id,
            'lead_lawyer_id' => $lawyer->id,
            'title' => 'Trần Thị Mới — Dân sự',
            'description_internal' => 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.',
            'client_type' => 'individual',
        ])
        ->assertSee(__('intake.convert.contact_line', ['name' => 'Trần Thị Mới']))
        ->assertSee(__('intake.convert.contact_phone_line', ['phone' => '0832 270 898']))
        ->assertSee(__('intake.convert.contact_email_line', ['email' => 'moi@example.test']))
        ->assertSee(__('intake.convert.contact_no_id_line'))
        ->assertSee(__('intake.convert.party_line', ['role' => PartyRole::Defendant->label(), 'name' => 'Công Ty Bên Kia']))
        ->assertDontSee(__('intake.convert.no_parties'))
        ->assertSee(__('intake.convert.id_number_help'))
        ->call('convert')
        ->assertHasNoFormErrors();

    $intake->refresh();
    $matter = Matter::query()->sole();
    $client = Client::query()->findOrFail($matter->client_id);

    $page->assertRedirect(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'));

    expect($intake->status)->toBe(IntakeStatus::Won)
        ->and($intake->matter_id)->toBe($matter->id)
        ->and($intake->client_id)->toBe($client->id)
        ->and($matter->code)->not->toBeEmpty()
        ->and($matter->title)->toBe('Trần Thị Mới — Dân sự')
        ->and($matter->description_internal)->toBe('Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.')
        ->and($matter->lead_lawyer_id)->toBe($lawyer->id)
        ->and($client->name)->toBe('Trần Thị Mới')
        ->and($client->phone)->toBe('0832 270 898')
        ->and($client->email)->toBe('moi@example.test');

    $opposing = $matter->parties()->where('is_our_client', false)->sole();

    expect($opposing->name)->toBe('Công Ty Bên Kia')
        ->and($opposing->phone_normalized)->toBe($party->phone_normalized)
        ->and($opposing->id_number_hash)->toBe($party->id_number_hash)
        ->and($matter->parties()->where('is_our_client', true)->sole()->client_id)->toBe($client->id);
});

it('attaches the contact to the existing client with exactly the same phone, and creates no second client', function () {
    $lawyer = cvsStaff();
    $existing = cvsExistingClient(['phone' => '+84 832 270 898', 'name' => 'Trần Thị Mới']);
    $intake = cvsRecord($lawyer);
    $clients = Client::query()->count();

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->fillForm(['confirm_existing_client' => true])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('id', $intake->fresh()->matter_id)->sole();

    expect(Client::query()->count())->toBe($clients)
        ->and($matter->client_id)->toBe($existing->id)
        ->and($intake->fresh()->client_id)->toBe($existing->id);
});

it('shows who the phone belongs to before attaching, and attaches nobody until the lawyer confirms that person', function () {
    $lawyer = cvsStaff();
    // Cùng số máy, khác tên (con gọi bằng máy của mẹ): chỉ người bấm biết có phải cùng người không.
    $mother = cvsExistingClient(['phone' => '0832270898', 'name' => 'Nguyễn Thị Mẹ']);
    $intake = cvsRecord($lawyer);
    $clients = Client::query()->count();
    $matters = Matter::query()->count();

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->assertDontSee('Nguyễn Thị Mẹ')
        ->assertFormFieldDoesNotExist('confirm_existing_client')
        ->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->assertSee(__('intake.convert.client_match', ['code' => $mother->code, 'name' => 'Nguyễn Thị Mẹ']));

    // Lượt hai KHÔNG tích: vẫn không gắn.
    $page->call('convert')->assertHasFormErrors(['confirm_existing_client']);

    expect(Matter::query()->count())->toBe($matters)
        ->and($intake->fresh()->client_id)->toBeNull();

    // Đổi số căn cước là đổi khách được tra ra: hồ sơ đang hiện không còn là câu trả lời.
    $page->fillForm(['client_id_number' => '079090000123']);

    expect($page->instance()->matchedClientId)->toBeNull();

    $page->fillForm(['client_id_number' => null])
        ->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->fillForm(['confirm_existing_client' => true])
        ->call('convert')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->client_id)->toBe($mother->id)
        ->and(Client::query()->count())->toBe($clients);
});

it('clears the tick when the lookup now finds a different client than the one the lawyer confirmed', function () {
    $lawyer = cvsStaff();
    $first = cvsExistingClient(['phone' => '0832270898', 'name' => 'Nguyễn Thị Mẹ']);
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->fillForm(['confirm_existing_client' => true]);

    // Giữa hai lượt, số máy đó thành của một khách KHÁC (hồ sơ kia đổi số, một hồ sơ mới mang số này).
    $first->forceFill(['phone' => '0900111444'])->save();
    $second = cvsExistingClient(['phone' => '0832270898', 'name' => 'Lê Văn Khác']);

    $page->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->assertSee(__('intake.convert.client_match', ['code' => $second->code, 'name' => 'Lê Văn Khác']))
        // Dấu tích đã cho hồ sơ trước không được chuyển sang hồ sơ mới hiện ra.
        ->assertFormSet(['confirm_existing_client' => false]);

    expect($page->instance()->matchedClientId)->toBe($second->id)
        ->and($intake->fresh()->matter_id)->toBeNull();
});

it('does not attach a client found by phone whose ID number is not the contact\'s, and says why on the ID field', function (?string $typed) {
    $lawyer = cvsStaff();
    $mother = cvsExistingClient(['phone' => '0832270898', 'id_number' => '079080000111', 'name' => 'Nguyễn Thị Mẹ']);
    $intake = cvsRecord($lawyer, ['contact_name' => 'Nguyễn Thị Con', 'contact_id_number' => '079090000555']);
    $clients = Client::query()->count();
    $matters = Matter::query()->count();

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->fillForm(['client_id_number' => $typed])
        ->call('convert')
        ->assertHasFormErrors(['client_id_number'])
        ->assertSee(__('intake.errors.convert_client_id_differs', ['code' => $mother->code, 'name' => 'Nguyễn Thị Mẹ']))
        ->assertFormFieldDoesNotExist('confirm_existing_client');

    expect(Matter::query()->count())->toBe($matters)
        ->and(Client::query()->count())->toBe($clients)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);
})->with([
    'ID number only recorded at intake' => [null],
    'ID number typed again' => ['079090000555'],
]);

it('says a typed ID number will not go onto an existing client that has none, instead of dropping it', function () {
    $lawyer = cvsStaff();
    $existing = cvsExistingClient(['phone' => '0832270898', 'name' => 'Trần Thị Mới']);
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->assertSee(__('intake.convert.id_number_help'))
        ->fillForm(['client_id_number' => '079090000555'])
        ->call('convert')
        ->assertHasFormErrors(['client_id_number'])
        ->assertSee(__('intake.errors.convert_id_number_not_carried', ['code' => $existing->code, 'name' => 'Trần Thị Mới']));

    expect($intake->fresh()->matter_id)->toBeNull();

    $page->fillForm(['client_id_number' => null])
        ->call('convert')
        ->assertHasFormErrors(['confirm_existing_client'])
        ->fillForm(['confirm_existing_client' => true])
        ->call('convert')
        ->assertHasNoFormErrors();

    expect($intake->fresh()->client_id)->toBe($existing->id)
        ->and($existing->fresh()->id_number)->toBeNull();
});

it('keeps a record from converting while another call of the same caller holds the repeat-call lock, until a manager overrides it on this record', function (bool $declineTheOtherCall) {
    cvsExistingClient(['phone' => '0912000111', 'name' => 'Công Ty D']);
    $lawyer = cvsStaff();
    $b = cvsRecord($lawyer, ['contact_name' => 'Người Gọi P'], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant]]);
    $editUrl = IntakeRequestResource::getUrl('edit', ['record' => $b], panel: 'admin');

    $this->actingAs($lawyer, 'web');
    // Trang mở TRƯỚC lần gọi lại (lúc đó B còn chuyển đổi được).
    $stale = cvsPage($b)->assertNoRedirect();

    // P gọi lại, nêu tên khách hiện hữu D kèm SĐT: Đỏ. Quản lý có thể từ chối luôn vì xung đột (R8).
    $a = cvsRecord($lawyer, ['contact_name' => 'Người Gọi P', 'matter_type_id' => $b->matter_type_id], [
        ['name' => 'Công Ty D', 'role' => PartyRole::Defendant, 'phone' => '0912000111'],
    ]);

    if ($declineTheOtherCall) {
        app(DeclineIntake::class)->handle(cvsStaff(Role::Manager), $a, 'Bên kia là khách hiện hữu của văn phòng', true);
    }

    expect($b->fresh()->hasUnresolvedRed())->toBeFalse();

    cvsEdit($b)->assertActionHidden('convert');

    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
    cvsPage($b)->assertNotified(__('intake.errors.convert_caller_locked'))->assertRedirect($editUrl);

    $stale->call('convert')->assertNotified(__('actions.failed_title'));

    expect(Matter::query()->where('id', $b->fresh()->matter_id)->exists())->toBeFalse()
        ->and($b->fresh()->status)->toBe(IntakeStatus::New);

    // Quản lý xử lý trên CHÍNH B: kiểm tra lại (B nhận khoá), rồi ghi đè kèm lý do.
    $this->actingAs(cvsStaff(Role::Manager), 'web');
    cvsEdit($b)->callAction(TestAction::make('rerun')->schemaComponent('checkActions'))->assertHasNoErrors();
    cvsEdit($b)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã xem cả hai lần gọi, phần việc này không liên quan Công Ty D'])
        ->assertHasNoErrors();

    cvsEdit($b)->assertActionVisible('convert');

    $page = cvsPage($b)->assertNoRedirect()->call('convert');

    if ($b->fresh()->matter_id === null) {
        $page->fillForm(['acknowledge_conflict' => true])->call('convert')->assertHasNoFormErrors();
    }

    expect($b->fresh()->status)->toBe(IntakeStatus::Won);
})->with([
    'the other call is still red' => [false],
    'the other call was declined for a conflict' => [true],
]);

/*
 * Fix vòng 2 (rà soát lại Task 4, N1): ghi đè của quản lý trên B chỉ che các khoá người gọi lại mà lần
 * kiểm tra gần nhất của B đã thấy. Một lần gọi khác của cùng người bắt đầu khoá SAU ghi đè đó thì nút
 * ẩn lại và trang từ chối, kể cả sau "Kiểm tra lại", tới khi quản lý ghi đè lại trên B.
 */
it('hides the conversion again when another call of the same caller starts to lock after a manager overrode this record, until a manager overrides it again', function (bool $laterDecline) {
    cvsExistingClient(['phone' => '0912000111', 'name' => 'Công Ty D']);
    cvsExistingClient(['phone' => '0912000222', 'name' => 'Công Ty F']);
    $lawyer = cvsStaff();
    $manager = cvsStaff(Role::Manager);
    $call = fn (array $parties): IntakeRequest => cvsRecord($lawyer, ['contact_name' => 'Người Gọi P'], $parties);

    if ($laterDecline) {
        // A chưa khoá ai; C bị từ chối vì xung đột — C là khoá mà quản lý thấy khi ghi đè trên B.
        $a = $call([['name' => 'Bên A', 'role' => PartyRole::Defendant]]);
        $c = $call([['name' => 'Bên C', 'role' => PartyRole::Defendant]]);
        app(DeclineIntake::class)->handle($manager, $c, 'Bên kia là khách hiện hữu của văn phòng', true);
    } else {
        // C nêu khách hiện hữu D kèm SĐT: Đỏ — khoá mà quản lý thấy khi ghi đè trên B.
        $call([['name' => 'Công Ty D', 'role' => PartyRole::Defendant, 'phone' => '0912000111']]);
    }

    $b = $call([['name' => 'Ai Đó', 'role' => PartyRole::Defendant]]);
    $editUrl = IntakeRequestResource::getUrl('edit', ['record' => $b], panel: 'admin');

    $this->actingAs($manager, 'web');
    cvsEdit($b)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã xem lần gọi trước, phần việc này không liên quan'])
        ->assertHasNoErrors();

    $this->actingAs($lawyer, 'web');
    cvsEdit($b)->assertActionVisible('convert');
    // Trang mở TRƯỚC khi lần gọi kia khoá (lúc đó B chuyển đổi được).
    $stale = cvsPage($b)->assertNoRedirect();

    if ($laterDecline) {
        app(DeclineIntake::class)->handle($manager, $a->fresh(), 'Bên kia là khách hiện hữu của văn phòng', true);
    } else {
        // A: P gọi lần nữa, nêu khách hiện hữu F — Đỏ, chưa quản lý nào xem.
        $a = $call([['name' => 'Công Ty F', 'role' => PartyRole::Defendant, 'phone' => '0912000222']]);
    }

    expect($a->fresh()->locksRepeatCalls())->toBeTrue()
        ->and($b->fresh()->hasConflictOverride())->toBeTrue();

    cvsEdit($b)->assertActionHidden('convert');

    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
    cvsPage($b)->assertNotified(__('intake.errors.convert_caller_locked'))->assertRedirect($editUrl);

    $stale->call('convert')->assertNotified(__('actions.failed_title'));

    expect($b->fresh()->matter_id)->toBeNull()
        ->and($b->fresh()->status)->toBe(IntakeStatus::New);

    // "Kiểm tra lại" của luật sư không mở lại nút.
    cvsEdit($b)->callAction(TestAction::make('rerun')->schemaComponent('checkActions'))->assertHasNoErrors();
    cvsEdit($b)->assertActionHidden('convert');

    // Quản lý ghi đè LẠI trên B.
    $this->actingAs($manager, 'web');
    cvsEdit($b)
        ->callAction(TestAction::make('resolveRed')->schemaComponent('checkActions'), data: ['override_reason' => 'Đã xem cả lần gọi mới, phần việc này vẫn không liên quan'])
        ->assertHasNoErrors();

    $this->actingAs($lawyer, 'web');
    cvsEdit($b)->assertActionVisible('convert');

    $page = cvsPage($b)->assertNoRedirect()->call('convert');

    if ($b->fresh()->matter_id === null) {
        $page->fillForm(['acknowledge_conflict' => true])->call('convert')->assertHasNoFormErrors();
    }

    expect($b->fresh()->status)->toBe(IntakeStatus::Won)
        ->and($a->fresh()->locksRepeatCalls())->toBeTrue();
})->with([
    'a later call of the caller is red' => [false],
    'a later decline of a call of the caller for a conflict' => [true],
]);

it('does not attach the contact to a client who only shares the name, and asks to review the name match first', function () {
    $lawyer = cvsStaff();
    $namesake = cvsExistingClient(['phone' => '0900999888', 'name' => 'Trần Thị Mới']);
    $intake = cvsRecord($lawyer);
    $clients = Client::query()->count();

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['acknowledge_conflict'])
        ->assertSee(__('matters.conflict.heading_attention'));

    // Lượt hai KHÔNG tích "đã xem xét": vẫn chặn.
    $page->call('convert')->assertHasFormErrors(['acknowledge_conflict']);

    expect($intake->fresh()->matter_id)->toBeNull()
        ->and(Client::query()->count())->toBe($clients);

    $page->fillForm(['acknowledge_conflict' => true])->call('convert')->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    expect(Client::query()->count())->toBe($clients + 1)
        ->and($matter->client_id)->not->toBe($namesake->id);
});

it('blocks the conversion when a matter opened since the intake makes the result red, and leaves the record as it was', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    // Sau lần tiếp nhận: văn phòng nhận "Công Ty Bên Kia" làm khách ở một vụ khác, cùng SĐT.
    cvsExistingClient(['phone' => '0977000222', 'name' => 'Công Ty Bên Kia']);
    $matters = Matter::query()->count();
    $clients = Client::query()->count();

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['override_reason'])
        ->assertSee(__('matters.conflict.heading_red'))
        ->assertFormFieldIsDisabled('override_reason');

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::New)
        ->and($intake->matter_id)->toBeNull()
        ->and($intake->client_id)->toBeNull()
        ->and(Matter::query()->count())->toBe($matters)
        ->and(Client::query()->count())->toBe($clients);
});

it('lets a manager convert over a red result with a reason, through the same second pass as the matter form', function () {
    $manager = cvsStaff(Role::Manager);
    $intake = cvsRecord($manager);
    cvsExistingClient(['phone' => '0977000222', 'name' => 'Công Ty Bên Kia']);

    $this->actingAs($manager, 'web');
    $page = cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['override_reason'])
        ->assertFormFieldIsEnabled('override_reason');

    $page->fillForm(['override_reason' => 'Khách cũ đồng ý bằng văn bản ngày 01/10'])
        ->call('convert')
        ->assertHasNoFormErrors()
        ->assertNotified(__('matters.conflict.saved_overridden'));

    expect($intake->fresh()->status)->toBe(IntakeStatus::Won);
});

it('pre-fills the quoted amount as the suggested total when drafting the contract of the new matter', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)->call('convert')->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    test()->livewire(BillingRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->mountAction(TestAction::make('draftContract')->table())
        ->assertActionDataSet(['total_amount' => '15.000.000']);

    // Vụ không đến từ tiếp nhận nào: không có gợi ý.
    $plain = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    test()->livewire(BillingRelationManager::class, ['ownerRecord' => $plain, 'pageClass' => ViewMatter::class])
        ->mountAction(TestAction::make('draftContract')->table())
        ->assertActionDataSet(['total_amount' => null]);
});

it('keeps a converted record read-only, shows the matter it became, and never converts it a second time', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)->call('convert')->assertHasNoFormErrors();

    $intake->refresh();
    $matter = Matter::query()->findOrFail($intake->matter_id);

    cvsEdit($intake)
        ->assertActionHidden('convert')
        ->assertFormFieldIsDisabled('contact_name')
        ->assertSee(__('intake.decision.converted_into', ['code' => $matter->code]))
        ->fillForm(['contact_name' => 'Đổi Sau Khi Chuyển'])
        ->call('save');

    expect($intake->fresh()->contact_name)->toBe('Trần Thị Mới');

    // Thông báo của các lượt trước đã được "nhận" (redirect) — chỉ đọc thông báo của lượt dưới đây.
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);

    cvsPage($intake)
        ->assertRedirect(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertNotified(__('intake.errors.convert_already'));

    expect(Matter::query()->where('client_id', $matter->client_id)->count())->toBe(1)
        ->and($intake->fresh()->matter_id)->toBe($matter->id);
});

it('hides the button and turns the page away while a red still waits for a manager', function () {
    $lawyer = cvsStaff();
    cvsExistingClient(['phone' => '0977000222', 'name' => 'Công Ty Bên Kia']);
    $intake = cvsRecord($lawyer);

    expect($intake->hasUnresolvedRed())->toBeTrue();

    $this->actingAs($lawyer, 'web');
    cvsEdit($intake)->assertActionHidden('convert');
    cvsPage($intake)->assertNotified(__('intake.errors.convert_red_pending'))
        ->assertRedirect(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'));
});

it('asks for the ID number only to match what was recorded, and stores it on the new client', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer, ['contact_id_number' => '079090000555']);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)
        ->assertSee(__('intake.convert.id_number_recorded_help'))
        ->assertSee(__('intake.convert.contact_id_line'))
        ->fillForm(['client_id_number' => '079090000556'])
        ->call('convert')
        ->assertHasFormErrors(['client_id_number'])
        // Lỗi gắn đúng ô thì không kèm một thông báo "thất bại" trôi nổi.
        ->assertNotNotified(__('actions.failed_title'));

    expect($intake->fresh()->matter_id)->toBeNull();

    $page->fillForm(['client_id_number' => '079 090 000 555'])->call('convert')->assertHasNoFormErrors();

    $client = Client::query()->findOrFail($intake->fresh()->client_id);

    expect(Normalizer::idNumberHash($client->id_number))->toBe(Normalizer::idNumberHash('079090000555'));
});

it('turns a spent client lookup allowance into a Vietnamese error on the form, and creates nothing', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    for ($i = 0; $i < ClientLookupThrottle::MAX_ATTEMPTS; $i++) {
        ClientLookupThrottle::hit($lawyer);
    }

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['client_id_number'])
        ->assertSee(__('exceptions.client_lookup_throttled'));

    expect(Matter::query()->count())->toBe(0)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::New);
});

it('refuses, without naming anyone, a phone that belongs to a client known only through a restricted matter of another lawyer', function () {
    $lawyer = cvsStaff();
    $hidden = Client::factory()->create(['phone' => '0832270898', 'id_number' => null, 'name' => 'Khách Bí Mật']);
    $restricted = Matter::factory()->restricted()->create(['client_id' => $hidden->id, 'lead_lawyer_id' => cvsStaff()->id]);
    MatterParty::factory()->for($restricted)->ourClient($hidden)->create();
    $intake = cvsRecord($lawyer, [], [['name' => 'Ai Đó', 'role' => PartyRole::Defendant, 'phone' => '0977000333']]);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->call('convert')
        ->assertHasFormErrors(['client_id_number'])
        ->assertSee(__('exceptions.duplicate_client_not_visible'))
        ->assertDontSee('Khách Bí Mật')
        ->assertDontSee($hidden->code);

    expect($intake->fresh()->matter_id)->toBeNull()
        ->and(Matter::query()->where('client_id', $hidden->id)->count())->toBe(1);
});

it('sends the lawyer to the intake list when the new matter is restricted to another lead lawyer', function () {
    $lawyer = cvsStaff();
    $otherLead = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->fillForm(['confidentiality' => 'restricted', 'lead_lawyer_id' => $otherLead->id])
        ->call('convert')
        ->assertHasNoFormErrors()
        ->assertRedirect(IntakeRequestResource::getUrl('index', panel: 'admin'));

    $matter = Matter::query()->findOrFail(IntakeRequest::query()->findOrFail($intake->id)->matter_id);

    expect($matter->lead_lawyer_id)->toBe($otherLead->id)
        ->and($matter->team()->where('users.id', $lawyer->id)->exists())->toBeFalse();
});

it('rejects a lead lawyer outside the eligible list', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);
    $assistant = cvsStaff(Role::Assistant);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->fillForm(['lead_lawyer_id' => $assistant->id])
        ->call('convert')
        ->assertHasFormErrors(['lead_lawyer_id']);

    expect($intake->fresh()->matter_id)->toBeNull();
});

it('forgets the shown result when the client role changes, so an old acknowledgement is not taken for a new check', function () {
    $lawyer = cvsStaff();
    cvsExistingClient(['phone' => '0900999888', 'name' => 'Trần Thị Mới']);
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)->call('convert')->assertHasFormErrors(['acknowledge_conflict']);

    expect($page->instance()->conflictResult)->not->toBeNull();

    $page->fillForm(['client_role' => PartyRole::Related->value]);

    expect($page->instance()->conflictResult)->toBeNull()
        ->and($page->instance()->pendingConflictLevel)->toBeNull();

    // Số căn cước cũng vậy: nó đổi khách hàng được tra ra.
    $page->call('convert')->assertHasFormErrors(['acknowledge_conflict']);

    expect($page->instance()->conflictResult)->not->toBeNull();

    $page->fillForm(['client_id_number' => '079090000123']);

    expect($page->instance()->conflictResult)->toBeNull()
        ->and($page->instance()->pendingConflictLevel)->toBeNull();
});

it('keeps a lawyer of the team on the new matter page when the lawyer is not the lead', function () {
    $lawyer = cvsStaff();
    $otherLead = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake)->fillForm(['lead_lawyer_id' => $otherLead->id])->call('convert')->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    $page->assertRedirect(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'));

    $role = $matter->team()->where('users.id', $lawyer->id)->first()?->pivot->role_in_matter;

    expect($role instanceof MatterRole ? $role : MatterRole::tryFrom((string) $role))->toBe(MatterRole::Associate);
});

it('answers 404 to the next request once the lawyer has lost the right to open matters', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake);

    SpatieRole::findByName(Role::Lawyer->value, 'web')->revokePermissionTo('matter.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $lawyer->unsetRelation('roles')->unsetRelation('permissions');

    // Một request bất kỳ của trang (đây: sửa một ô) đã là 404 — trang không dựng lại form, câu chuyện
    // hay thông tin người liên hệ cho người không còn quyền.
    $page->set('data.title', 'Sửa sau khi mất quyền')->assertNotFound();

    cvsPage($intake)->assertNotFound();

    expect($intake->fresh()->matter_id)->toBeNull()
        ->and(Matter::query()->count())->toBe(0);
});

it('leaves the matter type empty and suggests only the name when the type of the intake is no longer in use', function () {
    $lawyer = cvsStaff();
    $retired = MatterType::factory()->withStages()->create(['is_active' => false, 'name' => 'Loại Đã Ngưng']);
    $intake = cvsRecord($lawyer, ['matter_type_id' => $retired->id]);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)->assertFormSet([
        'matter_type_id' => null,
        'title' => 'Trần Thị Mới',
    ]);
});

it('says so when no opposing party was recorded', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer, ['contact_email' => null], []);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)
        ->assertSee(__('intake.convert.no_parties'))
        ->assertSee(__('intake.convert.contact_email_line', ['email' => '—']));
});

it('tells the user, as a notification, when the record stopped being convertible in another tab', function () {
    $lawyer = cvsStaff();
    $intake = cvsRecord($lawyer);

    $this->actingAs($lawyer, 'web');
    $page = cvsPage($intake);

    app(DeclineIntake::class)->handle($lawyer, $intake->fresh(), 'Khách đổi ý, không theo nữa');

    $page->call('convert')
        ->assertHasNoFormErrors()
        ->assertNotified(__('actions.failed_title'));

    expect(Matter::query()->count())->toBe(0)
        ->and($intake->fresh()->status)->toBe(IntakeStatus::Declined);
});

it('shows the matter a record became, as a link only to someone who can open that matter', function () {
    $assistant = cvsStaff(Role::Assistant);
    $lawyer = cvsStaff();
    $intake = cvsRecord($assistant, ['assigned_to' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');
    cvsPage($intake)->call('convert')->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);
    $url = MatterResource::getUrl('view', ['record' => $matter], panel: 'admin');

    cvsEdit($intake)->assertSee($url, escape: false);

    // Trợ lý đã ghi bản ghi vẫn thấy bản ghi (R9) và mã vụ thường, nhưng không vào được vụ đó.
    $this->actingAs($assistant, 'web');
    cvsEdit($intake)
        ->assertSee(__('intake.decision.converted_into', ['code' => $matter->code]))
        ->assertDontSee($url, escape: false);
});
