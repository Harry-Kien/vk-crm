<?php

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\Matter\UpdateMatterDetails;
use App\Actions\OpenMatter;
use App\Enums\Confidentiality;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Models\Client;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Làn fm, vòng sửa 2, phát hiện F2: "Ghi chú nội bộ" và "Ngày mở hồ sơ" có MỘT luật cho cả
 * ba đường ghi (mở vụ, chuyển từ tiếp nhận, trang Sửa), và trang Sửa chỉ hỏi luật đó khi hai ô này
 * THẬT SỰ đổi. Trước bản sửa: mở vụ nhận ngày mở trong tương lai, chuyển đổi nhận ghi chú tới
 * 20.000 ký tự, còn trang Sửa (luôn gửi lại cả hai ô) đòi ngày không sau hôm nay và ghi chú tối đa
 * 16.000 ký tự — vụ đó không lưu được gì nữa, kể cả tiêu đề hay chuyển sang "hạn chế".
 *
 * Hàm toàn cục mang tiền tố `fmRules…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Carbon::setTestNow('2026-10-09 10:00:00');
});

function fmRulesEditData(Matter $matter, array $overrides = []): array
{
    return [
        'title' => $matter->title,
        'summary_for_client' => $matter->summary_for_client,
        'court_name' => $matter->court_name,
        'case_number' => $matter->case_number,
        'confidentiality' => $matter->confidentiality->value,
        'description_internal' => $matter->description_internal,
        'opened_at' => $matter->opened_at->toDateString(),
        ...$overrides,
    ];
}

/** Một ghi chú dài đúng `$chars` ký tự tiếng Việt (3 byte mỗi ký tự có dấu). */
function fmRulesNote(int $chars, string $char = 'ệ'): string
{
    return str_repeat($char, $chars);
}

it('saves title, case number and confidentiality on a matter whose opening date is still in the future', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-20']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter, [
            'title' => 'Tiêu đề đã sửa',
            'case_number' => '12/2026/DS-ST',
            'confidentiality' => Confidentiality::Restricted->value,
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $matter->fresh();

    expect($fresh->title)->toBe('Tiêu đề đã sửa')
        ->and($fresh->case_number)->toBe('12/2026/DS-ST')
        ->and($fresh->confidentiality)->toBe(Confidentiality::Restricted)
        ->and($fresh->opened_at->toDateString())->toBe('2026-10-20');
});

/**
 * Như test trên, nhưng chỉ gõ tiêu đề: ngày mở và ghi chú là state Filament tự nạp từ bản ghi (đúng
 * điều người dùng thấy khi không chạm vào hai ô đó), không phải chuỗi test tự dựng.
 */
it('saves only the title when the future opening date and the long note are left as the page loaded them', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $legacy = fmRulesNote(UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX + 500, 'a');
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-20', 'description_internal' => $legacy]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(['title' => 'Chỉ sửa tiêu đề'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $matter->fresh();

    expect($fresh->title)->toBe('Chỉ sửa tiêu đề')
        ->and($fresh->opened_at->toDateString())->toBe('2026-10-20')
        ->and($fresh->description_internal)->toBe($legacy);
});

it('still refuses moving such an opening date to another future day', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-20']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        // Lịch của ô ngày dừng ở hết ngày mở đang lưu (để form nhận lại đúng ngày đó).
        ->assertSeeHtml('<input x-ref="maxDate" type="hidden" value="2026-10-20 23:59:59" />')
        ->fillForm(fmRulesEditData($matter, ['opened_at' => '2026-10-15']))
        ->call('save')
        ->assertHasFormErrors(['opened_at']);

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-10-20');

    // Kéo về hôm nay (hoặc trước) thì được.
    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter, ['opened_at' => '2026-10-09']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->opened_at->toDateString())->toBe('2026-10-09');
});

it('saves the title of a matter whose internal note is longer than the cap, as long as the note is not touched', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $legacy = fmRulesNote(UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX + 500, 'a');
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-01', 'description_internal' => $legacy]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter, ['title' => 'Tiêu đề đã sửa']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->title)->toBe('Tiêu đề đã sửa')
        ->and($matter->fresh()->description_internal)->toBe($legacy);

    // Đổi chính ghi chú đó mà vẫn quá trần thì bị từ chối trên đúng ô.
    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter->fresh(), ['description_internal' => $legacy.'b']))
        ->call('save')
        ->assertHasFormErrors(['description_internal']);

    expect($matter->fresh()->description_internal)->toBe($legacy);
});

it('accepts on the edit page an internal note as long as the conversion page accepts, and refuses one character more', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-01']);
    $note = fmRulesNote(20000);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter, ['description_internal' => $note]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->description_internal)->toBe($note);

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter->fresh(), ['description_internal' => fmRulesNote(20001)]))
        ->call('save')
        ->assertHasFormErrors(['description_internal']);

    expect($matter->fresh()->description_internal)->toBe($note);
});

/**
 * Ký tự 4 byte (biểu tượng cảm xúc): 16.000 ký tự đã là 64.000 byte — quá trần 60.000 byte chung
 * của cột `text` dù chưa quá trần ký tự. Trang Sửa từ chối trên đúng ô, không để MariaDB strict nổ.
 */
it('refuses on the edit page an internal note under the character cap but over the byte cap', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'opened_at' => '2026-10-01', 'description_internal' => 'Cũ']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertSeeHtml('<input x-ref="maxDate" type="hidden" value="2026-10-09 23:59:59" />')
        ->fillForm(fmRulesEditData($matter, ['description_internal' => fmRulesNote(16000, '😀')]))
        ->call('save')
        ->assertHasFormErrors(['description_internal']);

    expect($matter->fresh()->description_internal)->toBe('Cũ');
});

function fmRulesCreateData(Client $client, User $lead, MatterType $type, array $overrides = []): array
{
    return [...[
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Tranh chấp hợp đồng thuê nhà',
        'lead_lawyer_id' => $lead->id,
        'summary_for_client' => 'Tóm tắt gửi khách hàng.',
        'other_parties' => [],
    ], ...$overrides];
}

function fmRulesVisibleClient(User $lawyer): Client
{
    $client = Client::factory()->create();
    $prior = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($prior)->ourClient($client, PartyRole::Plaintiff)->create();

    return $client;
}

it('refuses on the create form an opening date after today, and opens the matter dated today', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = fmRulesVisibleClient($lawyer);
    $type = MatterType::factory()->withStages()->create();

    $this->actingAs($lawyer, 'web');

    // Lịch của ô ngày dừng ở hết ngày hôm nay.
    $this->livewire(CreateMatter::class)
        ->assertSeeHtml('<input x-ref="maxDate" type="hidden" value="2026-10-09 23:59:59" />')
        ->fillForm(fmRulesCreateData($client, $lawyer, $type, ['opened_at' => '2026-10-10']))
        ->call('create')
        ->assertHasFormErrors(['opened_at']);

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->exists())->toBeFalse();

    $this->livewire(CreateMatter::class)
        ->fillForm(fmRulesCreateData($client, $lawyer, $type, ['opened_at' => '2026-10-09']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->sole()->opened_at->toDateString())->toBe('2026-10-09');
});

it('refuses on the create form an internal note over the byte cap', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = fmRulesVisibleClient($lawyer);
    $type = MatterType::factory()->withStages()->create();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(fmRulesCreateData($client, $lawyer, $type, ['description_internal' => fmRulesNote(16000, '😀')]))
        ->call('create')
        ->assertHasFormErrors(['description_internal']);

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->exists())->toBeFalse();
});

/** Một lần tiếp nhận Xanh đầy đủ, câu chuyện dài `$summary` (điền sẵn vào ghi chú nội bộ). */
function fmRulesIntake(User $actor, string $summary): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [
        'contact_name' => 'Trần Thị Mới',
        'contact_phone' => '0832 270 898',
        'contact_email' => 'moi@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Dân sự'])->id,
    ], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), $summary);

    return $intake->fresh();
}

it('lets the matter converted from an intake with a long story be edited afterwards', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $story = fmRulesNote(19000);
    $intake = fmRulesIntake($lawyer, $story);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['description_internal' => $story])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->sole();

    // Ngày mở điền sẵn là HÔM NAY (chuỗi `Y-m-d` mang giờ hiện tại trong state) — trần ngày phải nhận nó.
    expect($matter->description_internal)->toBe($story)
        ->and($matter->opened_at->toDateString())->toBe('2026-10-09');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->fillForm(fmRulesEditData($matter, ['title' => 'Tiêu đề đã sửa', 'confidentiality' => Confidentiality::Restricted->value]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($matter->fresh()->title)->toBe('Tiêu đề đã sửa')
        ->and($matter->fresh()->confidentiality)->toBe(Confidentiality::Restricted);
});

it('refuses on the conversion page an opening date after today', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake = fmRulesIntake($lawyer, 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');

    $this->actingAs($lawyer, 'web');

    // Lịch của ô ngày dừng ở hết ngày hôm nay (trang tự chặn, không đợi Action).
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertSeeHtml('<input x-ref="maxDate" type="hidden" value="2026-10-09 23:59:59" />')
        ->fillForm(['opened_at' => '2026-10-10'])
        ->call('convert')
        ->assertHasFormErrors(['opened_at']);

    expect(Matter::query()->count())->toBe(0);
});

/**
 * Cổng của Action chuyển đổi (không chỉ ô chọn ngày): cùng câu "không được sau hôm nay" như trang
 * Sửa, gắn trên `opened_at`.
 */
it('refuses an opening date after today in the conversion action itself', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake = fmRulesIntake($lawyer, 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');

    try {
        app(ConvertIntakeToMatter::class)->handle($lawyer, $intake, [
            'title' => 'Trần Thị Mới — Dân sự',
            'matter_type_id' => $intake->matter_type_id,
            'lead_lawyer_id' => $lawyer->id,
            'client_role' => PartyRole::Plaintiff->value,
            'opened_at' => '2026-10-10',
            'confidentiality' => Confidentiality::Normal->value,
            'client_type' => 'individual',
        ]);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('opened_at')
            ->and($exception->errors()['opened_at'])->toBe([__('lifecycle.details.opened_at_future')]);
    }

    expect(Matter::query()->count())->toBe(0);
});

/**
 * Cổng của Action chuyển đổi cho ghi chú nội bộ: cùng trần KÝ TỰ với trang Sửa (không chỉ trần byte),
 * để một lối vào gọi thẳng Action không tạo được vụ có ghi chú dài hơn mức trang Sửa nhận.
 */
it('refuses in the conversion action itself an internal note longer than the edit page accepts', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $intake = fmRulesIntake($lawyer, 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');

    try {
        app(ConvertIntakeToMatter::class)->handle($lawyer, $intake, [
            'title' => 'Trần Thị Mới — Dân sự',
            'matter_type_id' => $intake->matter_type_id,
            'lead_lawyer_id' => $lawyer->id,
            'client_role' => PartyRole::Plaintiff->value,
            'opened_at' => '2026-10-09',
            'confidentiality' => Confidentiality::Normal->value,
            'client_type' => 'individual',
            // 20.001 ký tự ASCII: dưới trần byte, quá trần ký tự.
            'description_internal' => fmRulesNote(UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX + 1, 'a'),
        ]);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('description_internal');
    }

    expect(Matter::query()->count())->toBe(0);
});

/**
 * Cổng của chính `OpenMatter` (không chỉ ô của form mở vụ): mọi đường mở vụ hỏi CÙNG luật với trang
 * Sửa (`UpdateMatterDetails::assertNewMatterDetails()`), nên một lối vào gọi thẳng Action không tạo
 * được vụ mà sau đó trang Sửa không lưu được.
 */
dataset('fm rules open matter refusals', [
    'ngày mở sau hôm nay' => [['opened_at' => '2026-10-10'], 'opened_at', 'Ngày mở hồ sơ không được sau hôm nay.'],
    'ngày mở là đối tượng ngày sau hôm nay' => [fn () => ['opened_at' => Carbon::parse('2026-10-10 00:00:01')], 'opened_at', 'Ngày mở hồ sơ không được sau hôm nay.'],
    'ghi chú quá trần ký tự' => [fn () => ['description_internal' => str_repeat('a', UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX + 1)], 'description_internal', 'Ghi chú nội bộ dài tối đa 20000 ký tự.'],
    'ghi chú dưới trần ký tự nhưng quá trần byte' => [fn () => ['description_internal' => str_repeat('😀', 15001)], 'description_internal', 'Ghi chú nội bộ quá dài: ký tự đặc biệt (như biểu tượng cảm xúc) chiếm nhiều chỗ hơn chữ thường. Hãy rút gọn ghi chú.'],
]);

it('refuses in OpenMatter itself what the edit page would refuse', function (array $overrides, string $field, string $message) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();

    try {
        app(OpenMatter::class)->handle($lawyer, fmRulesCreateData($client, $lawyer, $type, $overrides), []);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([$field => [$message]]);
    }

    expect(Matter::query()->count())->toBe(0);
})->with('fm rules open matter refusals');

it('opens in OpenMatter a matter dated today, late in the day, with a note exactly at both caps', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = MatterType::factory()->withStages()->create();
    // 20.000 ký tự đúng trần ký tự (ASCII), và 15.000 ký tự 4 byte = đúng 60.000 byte.
    $atCharCap = str_repeat('a', UpdateMatterDetails::DESCRIPTION_INTERNAL_MAX);

    $first = app(OpenMatter::class)->handle($lawyer, fmRulesCreateData($client, $lawyer, $type, [
        'opened_at' => Carbon::parse('2026-10-09 23:59:59'),
        'description_internal' => $atCharCap,
    ]), []);
    $second = app(OpenMatter::class)->handle($lawyer, fmRulesCreateData($client, $lawyer, $type, [
        'title' => 'Vụ thứ hai',
        'opened_at' => '2026-10-09',
        'description_internal' => str_repeat('😀', 15000),
    ]), []);

    expect($first->matter->opened_at->toDateString())->toBe('2026-10-09')
        ->and($first->matter->description_internal)->toBe($atCharCap)
        ->and($second->matter->description_internal)->toBe(str_repeat('😀', 15000));
});
