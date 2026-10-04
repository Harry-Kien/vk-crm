<?php

use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\IntakeRequests\Pages\CreateIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/*
 * M10 Task 3 — màn hình tiếp nhận trên panel admin, đi qua Livewire/HTTP thật (luật làn: không gọi
 * thẳng Action để chứng minh hành vi màn hình). Các test bắt buộc của kế hoạch, mỗi trường hợp một
 * `it()`: trợ lý nhập một cuộc gọi Xanh từ đầu đến cuối; tên 201 ký tự → lỗi tiếng Việt, không 500
 * (chạy thêm dưới `test:mariadb`); `(+84) 912 345 678` lưu đúng dạng chuẩn hoá; gợi ý trùng theo tên
 * không hiện cho trợ lý; lý do từ chối vì xung đột không hiện cho luật sư; vào thẳng URL bản ghi
 * không có quyền → 404. Các hành động (đổi trạng thái, gộp, từ chối, xử lý Đỏ, cổng câu chuyện) ở
 * `IntakeRequestActionsTest.php`.
 *
 * Hàm toàn cục mang tiền tố `irr…` (ParaTest chạy mỗi tệp trong một tiến trình riêng).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function irrStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Dữ liệu form tạo, đủ để ra Xanh đủ định danh: bên đối lập có số điện thoại, không trùng ai. */
function irrCreateData(array $overrides = []): array
{
    return [...[
        'contact_name' => 'Lê Văn Gọi',
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

function irrRecord(User $actor, array $overrides = [], array $parties = []): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], $parties)->intake;
}

it('lets an assistant record a green call from the first ring to a saved story and a status change', function () {
    $assistant = irrStaff();
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData())
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->where('contact_name', 'Lê Văn Gọi')->sole();

    expect($intake->created_by)->toBe($assistant->id)
        ->and($intake->status)->toBe(IntakeStatus::New)
        ->and($intake->conflict_level)->toBe(ConflictLevel::Green)
        ->and($intake->privacy_notice_acknowledged_at)->not->toBeNull()
        ->and($intake->privacy_notice_recorded_by)->toBe($assistant->id)
        ->and($intake->parties()->sole()->phone_normalized)->toBe('84977000111');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(__('intake.check.checked_at', ['at' => $intake->conflict_checked_at->format('d/m/Y H:i')]))
        ->assertSee(__('intake.gate.open'))
        ->assertFormFieldIsEnabled('summary')
        ->set('data.summary', 'Ông Gọi kể về hợp đồng thuê nhà bị chấm dứt sớm.')
        ->callAction(TestAction::make('saveSummary')->schemaComponent('storyActions'))
        ->assertHasNoErrors()
        ->callAction('changeStatus', data: ['status' => IntakeStatus::Contacted->value])
        ->assertHasNoErrors();

    expect($intake->fresh()->summary)->toBe('Ông Gọi kể về hợp đồng thuê nhà bị chấm dứt sớm.')
        ->and($intake->fresh()->status)->toBe(IntakeStatus::Contacted);
});

it('answers a 201-character contact name with a Vietnamese validation error and saves nothing, never a 500', function () {
    $this->actingAs(irrStaff(), 'web');

    $component = $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => str_repeat('Ạ', 201)]))
        ->call('create')
        ->assertHasFormErrors(['contact_name' => 'max']);

    expect(collect($component->errors()->get('data.contact_name'))->implode(' '))->toContain('không được dài hơn 200 ký tự')
        ->and(IntakeRequest::query()->count())->toBe(0);

    // 200 ký tự (nhiều byte) thì lưu được — đúng độ dài cột, cả trên MariaDB strict.
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => str_repeat('Ạ', 200)]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(mb_strlen(IntakeRequest::query()->sole()->contact_name))->toBe(200);
});

it('stores (+84) 912 345 678 as the normalised 84912345678 and keeps the typed form for calling back', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_phone' => '(+84) 912 345 678']))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();

    expect($intake->contact_phone_normalized)->toBe('84912345678')
        ->and($intake->contact_phone)->toBe('(+84) 912 345 678');
});

it('refuses a phone number the normaliser cannot read, with a Vietnamese error on the field', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_phone' => 'không có số']))
        ->call('create')
        ->assertHasFormErrors(['contact_phone']);

    expect(IntakeRequest::query()->count())->toBe(0);
});

it('answers a phone that fits the field but not its column once normalised with a Vietnamese error on the field, never a 500', function () {
    $this->actingAs(irrStaff(), 'web');

    // '0' + 19 chữ số: vừa ô 20 ký tự, nhưng `Normalizer::phone()` thay số 0 đầu bằng '84' → 21 ký tự,
    // quá cột chuẩn hoá (20) — lỗi 1406 thành trang 500 trên MariaDB strict (rà soát Task 2, m2).
    $tooLong = '09123456780987654321';

    $component = $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_phone' => $tooLong]))
        ->call('create')
        ->assertHasFormErrors(['contact_phone']);

    expect(collect($component->errors()->get('data.contact_phone'))->implode(' '))->toContain(__('intake.errors.phone_too_long'));

    // Ô SĐT của một dòng bên đối lập: lỗi nằm ở đúng ô của dòng đó, không ở cả danh sách.
    $component = $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['parties' => [[
            'role' => PartyRole::Defendant->value,
            'name' => 'Bị Đơn Số Dài',
            'phone' => $tooLong,
            'id_number' => null,
        ]]]))
        ->call('create');

    $rowPhoneErrors = collect($component->errors()->messages())
        ->filter(fn (array $messages, string $key): bool => preg_match('/^data\.parties\.[^.]+\.phone$/', $key) === 1);

    expect($rowPhoneErrors)->toHaveCount(1)
        ->and($rowPhoneErrors->flatten()->implode(' '))->toContain(__('intake.errors.phone_too_long'))
        ->and(IntakeRequest::query()->count())->toBe(0);

    // 20 ký tự mà dạng chuẩn hoá vẫn vừa cột thì lưu được (cả dưới `test:mariadb`).
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData([
            'contact_phone' => '84912345678098765432',
            'parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bị Đơn Số Dài',
                'phone' => '84987654321012345678',
                'id_number' => null,
            ]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();

    expect($intake->contact_phone_normalized)->toBe('84912345678098765432')
        ->and($intake->parties()->sole()->phone_normalized)->toBe('84987654321012345678');
});

it('asks for the role of the contact, and for a phone unless an email is given', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_role' => null, 'contact_phone' => null, 'contact_email' => null]))
        ->call('create')
        ->assertHasFormErrors(['contact_role' => 'required', 'contact_phone' => 'required_without']);

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_phone' => null, 'contact_email' => 'goi@example.com']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(IntakeRequest::query()->sole()->contact_email)->toBe('goi@example.com');
});

it('does not record the privacy notice unless the box is ticked, and then keeps the story closed for that reason', function () {
    $assistant = irrStaff();
    $this->actingAs($assistant, 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['privacy_notice' => false]))
        ->call('create')
        ->assertHasNoFormErrors();

    $intake = IntakeRequest::query()->sole();

    expect($intake->privacy_notice_acknowledged_at)->toBeNull();

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(__('intake.gate.locked'))
        ->assertSee(__('enums.intake_summary_blocker.privacy_notice'))
        ->assertFormFieldIsDisabled('summary');
});

it('parses the quoted fee through Money::parse and answers a bad amount on the field', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['quoted_amount' => 'mười triệu']))
        ->call('create')
        ->assertHasFormErrors(['quoted_amount']);

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['quoted_amount' => '15.000.000']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(IntakeRequest::query()->sole()->quoted_amount)->toBe(15_000_000);
});

it('caps every text field of the form at the length of its column', function () {
    $this->actingAs(irrStaff(), 'web');

    $intake = irrRecord(auth()->user(), [], [['name' => 'Bên Đối Lập', 'role' => PartyRole::Defendant]]);

    $component = $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
    $fields = $component->instance()->getSchema('form')->getFlatFields(withHidden: true);

    $lengths = collect($fields)
        ->filter(fn (Field $field): bool => $field instanceof TextInput || $field instanceof Textarea)
        ->mapWithKeys(fn (Field $field, string $key): array => [$key => $field->getMaxLength()])
        ->all();

    expect($lengths)->toMatchArray([
        'contact_name' => 200,
        'contact_phone' => 20,
        'contact_email' => 150,
        'contact_id_number' => 30,
        'referred_by' => 200,
        'quoted_amount' => 15,
        'summary' => 20000,
    ]);

    $partyFields = collect(collect($fields['parties']->getDefaultChildSchemas())->first()->getFlatFields(withHidden: true))
        ->filter(fn (Field $field): bool => $field instanceof TextInput)
        ->mapWithKeys(fn (Field $field, string $key): array => [$key => $field->getMaxLength()])
        ->all();

    expect($partyFields)->toMatchArray(['name' => 200, 'phone' => 20, 'id_number' => 30]);
});

it('shows the date and time of the last check, never the words "đã kiểm tra"', function () {
    $assistant = irrStaff();
    $this->actingAs($assistant, 'web');

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 41));
    $intake = irrRecord($assistant);

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee('01/10/2026 09:41')
        ->assertDontSee('đã kiểm tra')
        ->assertDontSee('Đã kiểm tra');
});

it('never shows a name-only duplicate hint to an assistant, while a manager sees it', function () {
    $assistant = irrStaff();
    $earlier = irrRecord($assistant, ['contact_name' => 'Trần Thị Bích', 'contact_phone' => '0911000222']);

    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => 'Trần Thị Bích', 'contact_phone' => '0911000333']))
        ->call('create')
        ->assertHasNoFormErrors();
    $mine = IntakeRequest::query()->latest('id')->first();

    $this->livewire(EditIntakeRequest::class, ['record' => $mine->getRouteKey()])
        ->assertDontSee(__('intake.duplicates.same_name'));

    $manager = irrStaff(Role::Manager);
    $this->actingAs($manager, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => 'Trần Thị Bích', 'contact_phone' => '0911000444']))
        ->call('create')
        ->assertHasNoFormErrors();
    $theirs = IntakeRequest::query()->latest('id')->first();

    $this->livewire(EditIntakeRequest::class, ['record' => $theirs->getRouteKey()])
        ->assertSee(__('intake.duplicates.same_name'))
        ->assertSeeInOrder([__('intake.duplicates.same_name'), $earlier->code]);
});

it('suggests an earlier record of the same phone in another way of writing it, by code, to its own assistant', function () {
    $assistant = irrStaff();
    $earlier = irrRecord($assistant, ['contact_phone' => '0832270898']);

    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_phone' => '+84 832 270 898', 'contact_role' => PartyRole::Defendant->value]))
        ->call('create')
        ->assertHasNoFormErrors();
    $later = IntakeRequest::query()->latest('id')->first();

    $this->livewire(EditIntakeRequest::class, ['record' => $later->getRouteKey()])
        ->assertSeeInOrder([__('intake.duplicates.same_identity'), $earlier->code])
        ->assertSee(__('intake.duplicates.merge_hint'));
});

it('tells an assistant only that an earlier record they cannot see exists, never its code or name', function () {
    $other = irrStaff();
    $hidden = irrRecord($other, ['contact_name' => 'Tên Bản Ghi Ẩn', 'contact_phone' => '0832270898']);

    $assistant = irrStaff();
    $this->actingAs($assistant, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => 'Người Khác', 'contact_phone' => '0832270898']))
        ->call('create')
        ->assertHasNoFormErrors();
    $mine = IntakeRequest::query()->latest('id')->first();

    $this->livewire(EditIntakeRequest::class, ['record' => $mine->getRouteKey()])
        ->assertSee(__('intake.duplicates.hidden_same_identity'))
        ->assertDontSee(__('intake.duplicates.same_identity'))
        ->assertDontSee('Tên Bản Ghi Ẩn');
});

it('says only "already a client of the office" for a phone of an existing client, never the client', function () {
    $client = Client::factory()->create(['name' => 'Khách Cũ Kín Tiếng', 'phone' => '0933444555']);
    MatterParty::factory()->for(Matter::factory()->for($client)->create())->ourClient($client)->create();

    $lawyer = irrStaff(Role::Lawyer);
    $this->actingAs($lawyer, 'web');
    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['contact_name' => 'Người Mới', 'contact_phone' => '0933 444 555']))
        ->call('create')
        ->assertHasNoFormErrors();
    $intake = IntakeRequest::query()->sole();

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(__('intake.duplicates.existing_client'));
});

it('hides a conflict decline reason from the lawyer who recorded the call, on the list and on the record page', function () {
    $lawyer = irrStaff(Role::Lawyer);
    $manager = irrStaff(Role::Manager);
    $intake = irrRecord($lawyer, ['contact_name' => 'Người Bị Từ Chối']);

    app(DeclineIntake::class)->handle($manager, $intake, 'Bên kia là khách của vụ VK-2026-0042', true);

    $this->actingAs($lawyer, 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$intake])
        ->assertSee(IntakeStatus::Declined->label())
        ->assertDontSee('VK-2026-0042');

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(IntakeStatus::Declined->label())
        ->assertSee(__('intake.decision.outward_answer'))
        ->assertDontSee('VK-2026-0042')
        ->assertDontSee(__('intake.decision.declined_for_conflict'));

    $this->actingAs($manager, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee('VK-2026-0042')
        ->assertSee(__('intake.decision.declined_for_conflict'));
});

it('sends the browser only the fields of the form: no decline or override reason and no stored hash, not even in the page state', function () {
    $lawyer = irrStaff(Role::Lawyer);
    $manager = irrStaff(Role::Manager);
    $intake = irrRecord($lawyer, ['contact_id_number' => '079123456789']);
    $hash = $intake->contact_id_number_hash;

    app(DeclineIntake::class)->handle($manager, $intake, 'Bên kia là khách của vụ VK-2026-0042', true);

    $this->actingAs($lawyer, 'web');
    $component = $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);

    // Mặc định `EditRecord` đổ MỌI thuộc tính của model vào `data` — tức vào ảnh chụp trạng thái
    // Livewire (`wire:snapshot`) mà trình duyệt nhận, dù không ô nào hiện chúng.
    expect(array_values(array_intersect(array_keys($component->get('data')), [
        'decline_reason', 'decline_reason_is_conflict', 'conflict_override_reason', 'conflict_result',
        'contact_id_number_hash', 'contact_phone_normalized', 'contact_name_normalized',
    ])))->toBe([])
        ->and($hash)->not->toBeNull();

    $component->assertDontSee('VK-2026-0042', stripInitialData: false)
        ->assertDontSee($hash, stripInitialData: false);
});

it('shows the "Kết quả xử lý" block only once the record was declined or merged', function () {
    $assistant = irrStaff();
    $intake = irrRecord($assistant);

    $this->actingAs($assistant, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertDontSee(__('intake.sections.decision'));

    app(DeclineIntake::class)->handle($assistant, $intake, 'Ngoài lĩnh vực của văn phòng', false);

    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee(__('intake.sections.decision'))
        ->assertSee('Ngoài lĩnh vực của văn phòng');
});

it('shows a decline reason that is not about a conflict to the lawyer who recorded the call', function () {
    $lawyer = irrStaff(Role::Lawyer);
    $intake = irrRecord($lawyer);

    app(DeclineIntake::class)->handle($lawyer, $intake, 'Ngoài lĩnh vực hành nghề của văn phòng', false);

    $this->actingAs($lawyer, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSee('Ngoài lĩnh vực hành nghề của văn phòng')
        ->assertDontSee(__('intake.decision.declined_for_conflict'));
});

it('answers 404 to a direct URL of a record the user cannot see, and to an accountant on the whole resource', function () {
    $owner = irrStaff();
    $intake = irrRecord($owner);

    $this->actingAs($owner, 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertOk();

    $this->actingAs(irrStaff(), 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertNotFound();

    $accountant = irrStaff(Role::Accountant);
    $this->actingAs($accountant, 'web')
        ->get(IntakeRequestResource::getUrl('index', panel: 'admin'))
        ->assertNotFound();
    $this->actingAs($accountant, 'web')
        ->get(IntakeRequestResource::getUrl('edit', ['record' => $intake], panel: 'admin'))
        ->assertNotFound();
    $this->actingAs($accountant, 'web')
        ->get(IntakeRequestResource::getUrl('create', panel: 'admin'))
        ->assertNotFound();
});

it('lists only the records an assistant recorded or was given, and everything for a manager', function () {
    $assistantA = irrStaff();
    $assistantB = irrStaff();
    $mine = irrRecord($assistantA, ['contact_phone' => '0901000001']);
    $given = irrRecord($assistantB, ['contact_phone' => '0901000002', 'assigned_to' => $assistantA->id]);
    $notMine = irrRecord($assistantB, ['contact_phone' => '0901000003']);

    $this->actingAs($assistantA, 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$mine, $given])
        ->assertCanNotSeeTableRecords([$notMine]);

    $this->actingAs(irrStaff(Role::Manager), 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$mine, $given, $notMine]);
});

it('puts the calls nobody has answered yet first, the longest waiting on top', function () {
    $manager = irrStaff(Role::Manager);

    $answered = irrRecord($manager, ['contact_phone' => '0901000001', 'received_at' => now()->subMinutes(10)]);
    $answered->forceFill(['status' => IntakeStatus::Contacted])->save();
    $waitingLong = irrRecord($manager, ['contact_phone' => '0901000002', 'received_at' => now()->subHours(5)]);
    $waitingShort = irrRecord($manager, ['contact_phone' => '0901000003', 'received_at' => now()->subMinutes(5)]);

    $this->actingAs($manager, 'web');
    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords([$waitingLong, $waitingShort, $answered], inOrder: true);
});

it('filters the list by status, source and assignee', function () {
    $manager = irrStaff(Role::Manager);
    $assistant = irrStaff();

    $phone = irrRecord($manager, ['contact_phone' => '0901000001', 'source' => IntakeSource::Phone]);
    $zalo = irrRecord($manager, ['contact_phone' => '0901000002', 'source' => IntakeSource::Zalo, 'assigned_to' => $assistant->id]);
    $zalo->forceFill(['status' => IntakeStatus::Consulting])->save();

    $this->actingAs($manager, 'web');
    $this->livewire(ListIntakeRequests::class)
        ->filterTable('source', IntakeSource::Zalo->value)
        ->assertCanSeeTableRecords([$zalo])
        ->assertCanNotSeeTableRecords([$phone]);

    $this->livewire(ListIntakeRequests::class)
        ->filterTable('status', IntakeStatus::New->value)
        ->assertCanSeeTableRecords([$phone])
        ->assertCanNotSeeTableRecords([$zalo]);

    $this->livewire(ListIntakeRequests::class)
        ->filterTable('assigned_to', $assistant->id)
        ->assertCanSeeTableRecords([$zalo])
        ->assertCanNotSeeTableRecords([$phone]);
});

it('offers no delete, restore or bulk action anywhere: erasing is anonymising (R7)', function () {
    $manager = irrStaff(Role::Admin);
    $intake = irrRecord($manager);

    $this->actingAs($manager, 'web');
    $this->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');

    $this->livewire(ListIntakeRequests::class)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete');
});

it('refuses a reception time in the future', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData(['received_at' => now()->addDay()->format('Y-m-d H:i')]))
        ->call('create')
        ->assertHasFormErrors(['received_at']);

    expect(IntakeRequest::query()->count())->toBe(0);
});

it('never offers "opposing counsel" as the role of the contact', function () {
    $this->actingAs(irrStaff(), 'web');

    $options = $this->livewire(CreateIntakeRequest::class)->instance()
        ->getSchema('form')->getFlatFields(withHidden: true)['contact_role']->getOptions();

    expect($options)->not->toHaveKey(PartyRole::OpposingCounsel->value)
        ->and($options)->toHaveKey(PartyRole::Plaintiff->value);
});

it('shows no duplicate block when nothing matched', function () {
    $this->actingAs(irrStaff(), 'web');

    $this->livewire(CreateIntakeRequest::class)
        ->fillForm(irrCreateData())
        ->call('create')
        ->assertHasNoFormErrors();

    $this->livewire(EditIntakeRequest::class, ['record' => IntakeRequest::query()->sole()->getRouteKey()])
        ->assertDontSee(__('intake.sections.duplicates'));
});
