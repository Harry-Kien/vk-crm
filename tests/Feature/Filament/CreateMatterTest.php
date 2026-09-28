<?php

use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient as CreateClientPage;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Loại vụ việc kèm giai đoạn (Matter::creating() ném StageNotConfigured nếu thiếu) và một danh
 * mục hồ sơ đang hoạt động, để khẳng định được tiêu chí SPEC §13 "tạo được vụ việc end-to-end"
 * gồm cả phần danh mục hồ sơ mà OpenMatter sao chép.
 *
 * Tên khác `matterTypeWithTemplate()` của tests/Feature/Actions/OpenMatterTest.php có chủ đích:
 * hàm khai báo ở cấp cao nhất một tệp Pest là hàm TOÀN CỤC, hai tệp cùng tên sẽ lỗi nạp tệp.
 */
function createFormMatterType(int $checklistItems = 2): MatterType
{
    $type = MatterType::factory()->withStages()->create();
    ChecklistTemplate::factory()->withItems($checklistItems)->for($type, 'matterType')->create();

    return $type;
}

/**
 * Một khách hàng mà `$lawyer` THẬT SỰ nhìn thấy trong ô chọn của form.
 *
 * Luật sư không có `client.manage` (SPEC §5) nên `VisibleClientOptions` chỉ trả về khách hàng của
 * những vụ việc họ đã liệt kê được — muốn luật sư mở được vụ việc cho khách hàng này thì phải có
 * sẵn một vụ việc cũ nối hai người.
 *
 * **`MatterParty::factory()->ourClient()` — KHÔNG còn `Matter::factory()` trần (M6.5 Task 8,
 * conflict-02/`intake-10`).** Bản trước dùng `Matter::factory()->create()` để dựng vụ việc cũ,
 * cố ý KHÔNG kèm `matter_parties`, với lý do ghi thẳng trong docblock cũ: "để vụ việc cũ này
 * không làm nhiễu kết quả kiểm tra xung đột". Lý do đó là SAI: `OpenMatter::buildOwnClientParty()`
 * luôn dựng một bên `is_our_client` từ hồ sơ `Client` ở MỌI vụ việc mở qua nó, nên trên dữ liệu
 * THẬT một khách hàng quay lại LUÔN có một dòng `matter_parties` mang hash của chính họ ở vụ việc
 * trước. Test "lưu sạch" cũ xanh không phải vì hệ thống đúng, mà vì factory dựng một tình huống
 * không bao giờ xảy ra ngoài đời — đúng cách `conflict-02` lọt qua bộ test trong ba vòng sửa liền.
 * Giờ R13(a) đã loại trừ đúng self-match đó (xem `RunConflictCheck::matchesFor()`), nên factory
 * này dựng lại đúng hình dạng thật — một `MatterParty` `ourClient()` — và MỌI test dùng hàm này
 * vẫn phải xanh giống hệt trước: nếu R13(a) thoái lui, cả bộ test này sẽ đỏ hàng loạt.
 */
function clientVisibleTo(User $lawyer): Client
{
    $client = Client::factory()->create();
    $priorMatter = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($priorMatter)->ourClient($client, PartyRole::Plaintiff)->create();

    return $client;
}

/** Khách hàng hiện hữu của văn phòng ở một vụ khác — nguồn của mọi kịch bản mức đỏ bên dưới. */
function existingFirmClientParty(string $idNumber, string $name, ?Matter $matter = null): MatterParty
{
    $client = Client::factory()->create(['id_number' => $idNumber, 'name' => $name]);

    return MatterParty::factory()
        ->for($matter ?? Matter::factory()->create())
        ->ourClient($client, PartyRole::Plaintiff)
        ->create();
}

/** @return array<string, mixed> dữ liệu form tối thiểu để mở một vụ việc. */
function createMatterFormData(Client $client, User $leadLawyer, MatterType $type, array $overrides = []): array
{
    return [...[
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Tranh chấp hợp đồng thuê nhà',
        'lead_lawyer_id' => $leadLawyer->id,
        'summary_for_client' => 'Tóm tắt gửi khách hàng.',
        'other_parties' => [],
    ], ...$overrides];
}

/**
 * SPEC §13, tiêu chí nghiệm thu M3: "Tạo được vụ việc ... end-to-end". Đây là test đóng tiêu chí
 * đó — trước khi có màn hình này, `OpenMatter` không có nơi gọi nào ngoài test.
 */
it('opens a matter end to end through the create form, with its parties and its checklist items', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType(3);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bị đơn không trùng ai',
                'id_number' => '098765432100',
                'phone' => '0911222333',
            ]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->first();

    expect($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id)
        ->and($matter->lead_lawyer_id)->toBe($lawyer->id)
        ->and($matter->code)->toStartWith('VK-')
        ->and($matter->stage)->not->toBeEmpty()
        ->and($matter->created_by)->toBe($lawyer->id)
        ->and($matter->checklistItems()->count())->toBe(3);

    // Hai bên: khách hàng của chính vụ việc (OpenMatter tự dựng từ hồ sơ Client) và bị đơn từ form.
    expect($matter->parties()->count())->toBe(2)
        ->and($matter->parties()->where('is_our_client', true)->first()->role)->toBe(PartyRole::Plaintiff)
        ->and($matter->parties()->where('name', 'Bị đơn không trùng ai')->first()->role)->toBe(PartyRole::Defendant);

    // SPEC §6.10 bước 4: mọi lần chạy đều để lại bằng chứng, kể cả xanh.
    expect(Activity::query()->where('event', 'conflict_check_run')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'matter_opened')->count())->toBe(1);
});

/**
 * M6.5 Task 6 (R4, findings `intake/intake-03`/`roles/roles-04`) — đây là test THAY THẾ đường
 * `clientVisibleTo()` của test ngay trên cho đúng câu hỏi mà `intake-10` nêu tên: "tạo được vụ
 * việc end-to-end" phải xanh với một khách hàng HOÀN TOÀN MỚI, không dựng sẵn một vụ cũ nối luật
 * sư với khách hàng. Trước bản sửa Task 6, `VisibleClientOptions` chỉ liệt kê khách hàng của
 * những vụ luật sư đã liệt kê được — một khách chưa từng có vụ nào không bao giờ lọt vào ô chọn,
 * và luật sư không có `client.manage` để tự tạo hồ sơ khách qua màn hình "Khách hàng". Không
 * `client_id`, không `clientVisibleTo()` nào ở đây — chỉ khối "Tạo khách mới" của `MatterForm`.
 */
it('opens a matter end to end for a brand new client, without building any prior matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType(2);

    expect(Client::count())->toBe(0)
        ->and(Matter::count())->toBe(0);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Ly hôn cho khách hoàn toàn mới',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Trần Thị Mới',
                'id_number' => '079099001234',
                'phone' => '0909111222',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Ly hôn cho khách hoàn toàn mới')->first();
    $client = Client::query()->where('name', 'Trần Thị Mới')->first();

    expect($client)->not->toBeNull()
        ->and($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id)
        ->and($matter->lead_lawyer_id)->toBe($lawyer->id)
        ->and(Client::count())->toBe(1)
        ->and(Matter::count())->toBe(1);

    // Final review A-M7: bên khách hàng được dựng TRƯỚC khi hồ sơ tồn tại (kiểm tra xung đột), rồi
    // nhận đúng `client_id` và định danh của hồ sơ vừa lưu.
    $ownParty = $matter->parties()->where('is_our_client', true)->sole();

    expect($ownParty->client_id)->toBe($client->id)
        ->and($ownParty->id_number_hash)->toBe(Normalizer::idNumberHash('079099001234'))
        ->and($ownParty->phone_normalized)->toBe(Normalizer::phone('0909111222'));
});

/**
 * M6.5 Task 6 (R4b) — không phải một finding của brief, tự phát hiện bằng probe trong lúc rà soát.
 *
 * `mutateFormDataBeforeCreate()` (tạo `Client` mới qua nhánh "Tạo khách mới") chạy TRƯỚC
 * `handleRecordCreation()` (nơi `OpenMatter` kiểm tra xung đột) — nếu `OpenMatter` chặn (vàng cần
 * xác nhận, đỏ cần ghi đè), hồ sơ `Client` vừa tạo đã COMMIT dù `Matter` chưa hề tồn tại. Lượt gửi
 * THỨ HAI (sau khi tích "đã xem xét") gửi lại CÙNG dữ liệu khách hàng mới — không nhớ lại hồ sơ đã
 * tạo ở lượt 1 thì lượt 2 sẽ dò trùng qua `matter_parties` (vẫn rỗng, vì bên khách hàng chưa từng
 * được lưu) và tạo một hồ sơ Client THỨ HAI cho CÙNG một người, để lại hồ sơ đầu tiên mồ côi vĩnh
 * viễn. `resolveClientId()` giờ ghi kết quả tạo mới vào CHÍNH `resolvedClientId` để lượt gửi sau
 * dùng lại, không tạo thêm — đúng bằng chứng probe tìm thấy TRƯỚC khi sửa: 2 hồ sơ "Trùng tên với
 * người khác" thay vì 1.
 */
it('does not leave an orphan client behind when a new client is blocked by a yellow conflict, then acknowledged', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    // Một bên tên trùng (tầng tên, chưa chắc đối lập) ở một vụ khác không liên quan — đủ để đưa
    // mức kiểm tra lên vàng (cần xác nhận), không cần đỏ.
    $twinMatter = Matter::factory()->create();
    MatterParty::factory()->for($twinMatter)->create(['name' => 'Trùng tên với người khác', 'is_our_client' => false]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc có xung đột mức vàng',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Trùng tên với người khác',
            ],
        ]);

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);

    // Final review A-M7: lượt 1 bị chặn KHÔNG tạo hồ sơ Client nào — hồ sơ chỉ được tạo sau khi
    // kiểm tra xung đột cho qua (bước lưu của OpenMatter), nên không có khách hàng mồ côi.
    expect(Client::where('name', 'Trùng tên với người khác')->count())->toBe(0);

    $component->fillForm(['acknowledge_conflict' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ việc có xung đột mức vàng')->first();
    $client = Client::query()->where('name', 'Trùng tên với người khác')->first();

    expect(Client::where('name', 'Trùng tên với người khác')->count())->toBe(1)
        ->and($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id);
});

/**
 * M6.5 Task 6 (R4a): luật sư tra ĐÚNG số điện thoại của một khách hàng do trợ lý vừa tạo (không
 * gắn với vụ việc nào) — chọn được, mở vụ được, không cần trưởng phòng/admin can thiệp.
 */
it('lets the lawyer look up the exact phone of a client the assistant just created, then open the matter', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($assistant, 'web');
    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách do trợ lý tạo',
            'phone' => '0912345678',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Khách do trợ lý tạo')->firstOrFail();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType(1);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(['client_lookup_identifier' => '0912345678'])
        // afterStateUpdated() của Livewire::fillForm() không tự chạy — gọi thẳng phương thức mà
        // ô đó gọi, đúng cách CreateMatter::lookupClient() được thiết kế để test được trực tiếp.
        ->call('lookupClient', '0912345678')
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc của khách trợ lý tạo',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ việc của khách trợ lý tạo')->first();

    expect($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id)
        // Không tạo thêm một hồ sơ khách hàng thứ hai.
        ->and(Client::count())->toBe(1);
});

/**
 * M6.5 Task 6 (R4a) — số gần đúng (sai một chữ số cuối) không được gợi ý gì, không lộ tên: kết
 * quả phải giống HỆT một số hoàn toàn không tồn tại — không có ô nào trên form nói ra tên khách.
 */
it('shows no suggestion and no name when the lawyer looks up a near-miss phone number', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');
    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách hàng bí mật',
            'phone' => '0912345678',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->call('lookupClient', '0912345679'); // sai một chữ số cuối

    expect($component->instance()->resolvedClientId)->toBeNull()
        ->and($component->instance()->resolvedClientLabel)->toBeNull();

    $component->assertDontSee('Khách hàng bí mật');
});

/**
 * M6.5 Task 6 (R4) — audit `client_lookup` không bao giờ chứa số thô, trúng hay trượt.
 */
it('never writes the raw phone number into the client_lookup audit trail, on a hit or a miss', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->actingAs($assistant, 'web');
    $this->livewire(CreateClientPage::class)
        ->fillForm([
            'type' => ClientType::Individual->value,
            'name' => 'Khách hàng có số điện thoại nhạy cảm',
            'phone' => '0912345678',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)->call('lookupClient', '0912345678');
    $this->livewire(CreateMatter::class)->call('lookupClient', '0912345679');

    $lookups = Activity::query()->where('event', 'client_lookup')->get();

    expect($lookups)->toHaveCount(2);

    foreach ($lookups as $lookup) {
        $encoded = $lookup->properties->toJson();
        expect($encoded)->not->toContain('0912345678')
            ->not->toContain('912345678')
            // Final review X8: một sha256 TRẦN của 10–12 chữ số dò ngược được bằng vét cạn —
            // băm có khoá (HMAC với APP_KEY), không bao giờ sha256 trần.
            ->not->toContain(hash('sha256', '0912345678'))
            ->not->toContain(hash('sha256', '0912345679'));
    }

    expect($lookups->pluck('properties.identifier_hash')->all())
        ->toContain(hash_hmac('sha256', '0912345678', config('app.key')))
        ->toContain(hash_hmac('sha256', '0912345679', config('app.key')));

    expect($lookups->firstWhere('properties.hit', true))->not->toBeNull()
        ->and($lookups->firstWhere('properties.hit', false))->not->toBeNull();
});

// =========================================================================================
// Fix round 1 (task-6-fix1-findings.md) — C1: dò trùng khi tạo khách mới không còn dùng lại
// một hồ sơ mà actor không thấy được.
// =========================================================================================

/**
 * C1 (Critical): trước bản sửa này, `CreateClient::findExistingClient()` dùng lại BẤT KỲ bên
 * `is_our_client` nào khớp định danh, không hỏi actor có thấy được khách hàng đó không — một
 * luật sư không có `client.manage` gõ đúng CCCD của một khách mà vụ DUY NHẤT của họ là
 * `restricted` (luật sư phụ trách là NGƯỜI KHÁC) sẽ được gắn thẳng vào khách đó, và tên khách
 * hiện ra ngay trên hồ sơ vụ việc luật sư B vừa mở — lộ danh tính một khách hàng thuộc một vụ
 * hạn chế mà B không có quyền xem (Review Focus #1).
 *
 * Sau bản sửa: dò trùng không tìm thấy CŨNG không tạo hồ sơ thứ hai — bị TỪ CHỐI thẳng, bằng một
 * câu trung lập không nêu tên ai, không mã hồ sơ nào. Không có vụ việc nào được mở.
 */
it('refuses to silently reuse a duplicate client whose only matter is restricted and led by someone else', function () {
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    // Dựng đúng hình dạng OpenMatter thật để lại: khách hàng là CHÍNH client_id của vụ việc
    // (không chỉ một bên trong danh sách), và một MatterParty is_our_client mang định danh của
    // nó — đúng những gì OpenMatter::buildOwnClientParty() luôn tạo ra. Chỉ gắn một MatterParty
    // rời rạc (như existingFirmClientParty() làm cho các test kiểm tra xung đột) không đủ để
    // kiểm tra tầm nhìn: ClientVisibility::isVisibleTo() hỏi "khách hàng này có phải client_id
    // CHÍNH của một vụ việc actor liệt kê được không", đúng luật VisibleClientOptions gốc.
    $secretClient = Client::factory()->create(['name' => 'Khách hàng bí mật của luật sư khác', 'id_number' => '079011112222']);
    $restrictedMatter = Matter::factory()->create([
        'client_id' => $secretClient->id,
        'lead_lawyer_id' => $otherLawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);
    MatterParty::factory()->for($restrictedMatter)->ourClient($secretClient, PartyRole::Plaintiff)->create();

    // Baseline TRƯỚC khi luật sư B gửi form: hồ sơ bí mật cộng khách hàng riêng mà
    // Matter::factory() tự dựng cho $type (MatterType không cần khách hàng riêng, nhưng
    // createFormMatterType() không tạo Matter nào — chỉ $restrictedMatter tạo một Client).
    $clientsBefore = Client::count();

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc bị từ chối vì trùng khách hạn chế',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Tên khác hoàn toàn do luật sư B gõ',
                'id_number' => '079011112222',
            ],
        ]);

    $component->call('create')->assertHasFormErrors(['new_client.name']);

    // I2: câu từ chối trung lập, không nêu tên hay mã hồ sơ của khách hàng thật.
    $component->assertDontSee('Khách hàng bí mật của luật sư khác');
    expect($component->instance()->resolvedClientLabel)->toBeNull();

    expect(Client::count())->toBe($clientsBefore) // không tạo thêm một hồ sơ thứ hai
        ->and(Matter::query()->where('title', 'Vụ việc bị từ chối vì trùng khách hạn chế')->exists())->toBeFalse();
});

/** Vế dương: cùng tình huống, nhưng khách hàng trùng VẪN thấy được (vụ cũ luật sư có tên trong đội ngũ) — dùng lại, im lặng. */
it('silently reuses a duplicate client that is visible to the lawyer, without creating a second record', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    $client = clientVisibleTo($lawyer);
    $clientsBefore = Client::count();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc mới cho khách đã có, trùng CCCD',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Tên gõ khác đi, không trùng',
                'id_number' => $client->id_number,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ việc mới cho khách đã có, trùng CCCD')->first();

    expect(Client::count())->toBe($clientsBefore) // không tạo thêm một hồ sơ thứ hai
        ->and($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id);
});

// =========================================================================================
// Fix round 2 (E1, tinh chỉnh R4a) — ô TRA cũng phải áp đúng ranh giới `restricted` mà C1 đã vá
// cho nhánh "tạo khách mới": không tra ra được một khách hàng mà MỌI vụ việc đều `restricted`
// và không vụ nào actor liệt kê được.
// =========================================================================================

/**
 * E1 (ruling): trước bản sửa này, `FindClientByIdentifier::searchClients()` không lọc gì theo
 * tầm nhìn — một luật sư B gõ đúng CCCD của khách hàng C, mà vụ DUY NHẤT là `restricted` do luật
 * sư A phụ trách, vẫn tra ra được TÊN và MÃ HỒ SƠ của C qua chính ô tra, dù `ClientVisibility::
 * isVisibleTo()` (C1) đã chặn đúng con đường "tạo khách mới". Sau bản sửa: từ chối trung lập,
 * không tên, không mã hồ sơ — và vụ việc không mở được nếu chỉ dựa vào kết quả tra đó.
 *
 * **Fix round 3 — kiểm tra bằng CHÍNH dữ liệu Livewire thật sự gửi đi, không phải HTML.**
 * `assertDontSee($x, escape: true, stripInitialData: false)` (bản round 2) SAI ở hai chỗ, cả hai
 * đều làm khẳng định đó không đo được gì:
 *  - Nó gọi SAU một `->call('lookupClient', ...)` tiếp theo — không phải lượt render TRANG ĐẦU
 *    TIÊN. `assertDontSee()` đọc `$this->lastState->getHtml($stripInitialData)`, và sau một
 *    `call()`, `lastState` là phản hồi AJAX của CHÍNH lượt gọi đó: `getHtml()` chỉ trả về mảnh
 *    HTML nằm trong `effects['html']` của phản hồi — nó KHÔNG BAO GIỜ chứa thuộc tính
 *    `wire:snapshot="..."` (thuộc tính đó chỉ có ở lượt RENDER TRANG ĐẦU, gắn vào thẻ gốc của
 *    component). Đối số `stripInitialData: false` vì vậy không tắt được gì — không có gì để tắt.
 *  - Ngay cả nếu có: `json_encode()` (cách Livewire tuần tự hoá snapshot/effects thành JSON gửi
 *    đi) mặc định ESCAPE ký tự ngoài ASCII thành `\uXXXX`. Một chuỗi tiếng Việt thô
 *    ("Khách hàng...") không bao giờ khớp một chuỗi JSON đã escape — phép so khớp chuỗi thất bại
 *    vì LÝ DO SAI, không phải vì dữ liệu sạch.
 *
 * Kiểm tra ĐÚNG: `$component->snapshot`/`$component->effects` (thuộc tính ảo của `Testable`, đọc
 * qua `__get()`, luôn phản ánh state/effects của phản hồi GẦN NHẤT — kể cả sau một `call()`) mã
 * hoá lại bằng `JSON_UNESCAPED_UNICODE` để chuỗi tiếng Việt giữ nguyên dạng đọc được, rồi so
 * trực tiếp — đây là TOÀN BỘ những gì Livewire thực sự gửi về trình duyệt cho lượt gọi vừa rồi.
 */
it('refuses the identifier lookup for a client whose matters are all restricted and unlistable, revealing nothing raw', function () {
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $secretClient = Client::factory()->create(['name' => 'Khách hàng chỉ có vụ hạn chế', 'phone' => '0913000001']);
    Matter::factory()->create([
        'client_id' => $secretClient->id,
        'lead_lawyer_id' => $otherLawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->call('lookupClient', '0913000001');

    expect($component->instance()->resolvedClientId)->toBeNull()
        ->and($component->instance()->resolvedClientLabel)->toBeNull();

    // Đúng dữ liệu Livewire thật sự gửi đi cho lượt gọi vừa rồi — không phải HTML, không bị
    // json_encode() escape mất chữ tiếng Việt.
    $snapshotJson = json_encode($component->snapshot, JSON_UNESCAPED_UNICODE);
    $effectsJson = json_encode($component->effects, JSON_UNESCAPED_UNICODE);

    expect($snapshotJson)->not->toContain('Khách hàng chỉ có vụ hạn chế')
        ->and($snapshotJson)->not->toContain($secretClient->code)
        ->and($effectsJson)->not->toContain('Khách hàng chỉ có vụ hạn chế')
        ->and($effectsJson)->not->toContain($secretClient->code);

    // Không mở được vụ việc chỉ dựa vào kết quả tra bị từ chối đó (client_id vẫn thiếu).
    $component->fillForm(['client_role' => PartyRole::Plaintiff->value])
        ->call('create')
        ->assertHasFormErrors(['client_id']);

    expect(Matter::query()->where('client_id', $secretClient->id)->count())->toBe(1); // chỉ vụ hạn chế cũ
});

/**
 * Vế dương thứ nhất (khách chưa có vụ nào vẫn tra được — `intake-03`) đã có sẵn ở test "lets the
 * lawyer look up the exact phone of a client the assistant just created..." — không lặp lại ở
 * đây, nhưng probe của mục đó dùng chính test kia (xem báo cáo).
 *
 * Vế dương thứ hai: khách hàng có MỘT vụ THƯỜNG cộng MỘT vụ `restricted` (không liên quan tới
 * luật sư đang tra) — vẫn tra được, vì "mọi vụ đều restricted" SAI ngay khi có một vụ thường.
 */
it('still finds a client by lookup when it has one normal matter alongside an unrelated restricted matter', function () {
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $client = Client::factory()->create(['phone' => '0913000002']);
    Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $otherLawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);
    Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $otherLawyer->id,
        'confidentiality' => Confidentiality::Normal,
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->call('lookupClient', '0913000002');

    expect($component->instance()->resolvedClientId)->toBe($client->id)
        ->and($component->instance()->resolvedClientLabel)->not->toBeNull();
});

// =========================================================================================
// Fix round 2 (Minor) — một lượt tra bị chặn (quá tần suất, hay khớp một khách hàng không tra ra
// được) phải xoá SẠCH kết quả tra THÀNH CÔNG trước đó, không để nó sống sót.
// =========================================================================================

it('clears a previously resolved client when a later lookup is throttled', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['phone' => '0913000003']);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class);

    // 19 lượt trượt trước, dùng budget 1..19 — không đụng gì tới resolvedClientId (giữ null).
    for ($i = 1; $i <= 19; $i++) {
        $component->call('lookupClient', sprintf('090000%04d', $i));
    }

    // Lượt thứ 20 (còn trong hạn mức): tra TRÚNG — resolvedClientId phải khác null trước khi bị
    // chặn ở lượt kế tiếp, để phép thử này đo ĐÚNG việc chặn xoá được một kết quả THÀNH CÔNG chứ
    // không phải chỉ giữ nguyên một `null` đã có sẵn từ một lượt trượt trước đó.
    $component->call('lookupClient', '0913000003');
    expect($component->instance()->resolvedClientId)->toBe($client->id);

    // Lượt thứ 21: đã chạm trần, bị chặn — phải xoá sạch kết quả TRÚNG vừa có ở lượt 20.
    $component->call('lookupClient', '0900009999');

    expect($component->instance()->resolvedClientId)->toBeNull()
        ->and($component->instance()->resolvedClientLabel)->toBeNull();
});

/**
 * E1: `resolveClientId()` hỏi LẠI `isOfferableByLookup()` tại thời điểm LƯU, không chỉ tin kết
 * quả của lần TRA. Luật sư tra được khách hàng C lúc C CHƯA có vụ nào (offerable); giữa lúc đó và
 * lúc bấm "Lưu", một vụ `restricted` do người khác phụ trách được mở cho CHÍNH C (mô phỏng một
 * người khác vừa mở vụ trong lúc luật sư đang gõ form) — C không còn offerable nữa. Lượt lưu phải
 * từ chối, không mở vụ việc, dù `resolvedClientId` đã set từ trước.
 */
it('re-checks offerability at save time, refusing a lookup result that went stale', function () {
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    $client = Client::factory()->create(['phone' => '0913000004']);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->call('lookupClient', '0913000004');

    expect($component->instance()->resolvedClientId)->toBe($client->id);

    // Giữa lượt tra và lượt lưu: một vụ restricted xuất hiện, do người khác phụ trách.
    Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $otherLawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);

    $component->fillForm([
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Vụ việc lưu trên một kết quả tra đã cũ',
        'lead_lawyer_id' => $lawyer->id,
        'other_parties' => [],
    ])
        ->call('create')
        ->assertHasFormErrors(['client_lookup_identifier']);

    expect(Matter::query()->where('title', 'Vụ việc lưu trên một kết quả tra đã cũ')->exists())->toBeFalse();
});

// =========================================================================================
// Fix round 1 — I1: giới hạn 20 lần tra/giờ cho một nhân sự, chung một bộ đếm cho cả tra
// (lookupClient) lẫn dò trùng khi tạo khách mới (new_client.*).
// =========================================================================================

it('refuses the 21st client lookup within an hour for the same staff user, and audits the refusal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class);

    for ($i = 1; $i <= 20; $i++) {
        $component->call('lookupClient', sprintf('090000%04d', $i));
    }

    expect(Activity::query()->where('event', 'client_lookup')->count())->toBe(20)
        ->and(Activity::query()->where('event', 'client_lookup_throttled')->count())->toBe(0);

    $component->call('lookupClient', '0900009999');

    expect(Activity::query()->where('event', 'client_lookup')->count())->toBe(20)
        ->and(Activity::query()->where('event', 'client_lookup_throttled')->count())->toBe(1);

    // Không ghi số thô vào dòng audit đã chặn.
    $throttled = Activity::query()->where('event', 'client_lookup_throttled')->first();
    expect($throttled->properties->toJson())->not->toContain('0900009999')
        ->not->toContain(hash('sha256', '0900009999'))
        ->and($throttled->properties->get('identifier_hash'))->toBe(hash_hmac('sha256', '0900009999', config('app.key')));
});

/**
 * Cùng MỘT bộ đếm cho cả hai đường (I1 nêu rõ: "Rate-limit both paths"): 20 lần tra qua
 * `lookupClient()` đã dùng hết giờ, nên lần dò trùng THỨ 21 — lần này đi qua khối "Tạo khách
 * mới" — cũng bị chặn, không phải một bộ đếm riêng mà một luật sư có thể lách bằng cách đổi
 * đường.
 */
it('shares the same hourly limit between lookupClient and the new-client duplicate scan', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class);

    for ($i = 1; $i <= 20; $i++) {
        $component->call('lookupClient', sprintf('090000%04d', $i));
    }

    $component->fillForm([
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Vụ việc thứ 21 bị chặn vì quá tần suất',
        'lead_lawyer_id' => $lawyer->id,
        'other_parties' => [],
        'new_client' => [
            'type' => ClientType::Individual->value,
            'name' => 'Khách mới, không liên quan',
            'phone' => '0977000000',
        ],
    ]);

    $component->call('create')->assertHasFormErrors(['new_client.name']);

    expect(Client::where('name', 'Khách mới, không liên quan')->exists())->toBeFalse()
        ->and(Matter::query()->where('title', 'Vụ việc thứ 21 bị chặn vì quá tần suất')->exists())->toBeFalse();
});

/**
 * Fix round 2 (Minor): `CreateClient::guardThrottle()` chọn định danh để băm bằng `filled()`,
 * không phải `??` — một `phone` gửi lên là CHUỖI RỖNG (form còn để trống ô điện thoại, chỉ điền
 * CCCD) không phải `null`, nên `??` không rơi xuống `id_number` như mong đợi và băm nhầm một
 * chuỗi rỗng, làm mất hẳn giá trị nội bộ của dòng audit đó.
 */
it('hashes the id_number, not a blank phone, in the throttled audit for the new-client duplicate scan', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();
    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class);

    for ($i = 1; $i <= 20; $i++) {
        $component->call('lookupClient', sprintf('090001%04d', $i));
    }

    $component->fillForm([
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => 'Vụ việc bị chặn, phone rỗng nhưng có CCCD',
        'lead_lawyer_id' => $lawyer->id,
        'other_parties' => [],
        'new_client' => [
            'type' => ClientType::Individual->value,
            'name' => 'Khách mới',
            'phone' => '',
            'id_number' => '079088776655',
        ],
    ]);

    $component->call('create')->assertHasFormErrors(['new_client.name']);

    $throttled = Activity::query()->where('event', 'client_lookup_throttled')->latest('id')->first();

    expect($throttled)->not->toBeNull()
        ->and($throttled->properties->get('identifier_hash'))->toBe(hash_hmac('sha256', '079088776655', config('app.key')))
        ->and($throttled->properties->get('identifier_hash'))->not->toBe(hash('sha256', '079088776655'));
});

// =========================================================================================
// Fix round 1 — Minor: DuplicateClientDetected (hay bất kỳ luật nghiệp vụ nào của CreateClient)
// không còn thoát ra thành lỗi 500 khi actor có client.manage bị chỉnh sửa payload để đi qua
// khối "Tạo khách mới" vốn không dành cho họ.
// =========================================================================================

it('does not crash with a 500 when a client.manage actor submits a tampered new_client payload', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();

    $this->actingAs($manager, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc của trưởng phòng, payload new_client bị chỉnh tay',
            'lead_lawyer_id' => $manager->id,
            'other_parties' => [],
            // Trường này bị ẩn/không dehydrate với client.manage — mô phỏng một payload bị
            // chỉnh sửa để gửi thẳng khoá này lên máy chủ.
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Tên không nên được dùng',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ việc của trưởng phòng, payload new_client bị chỉnh tay')->first();

    expect($matter)->not->toBeNull()
        ->and($matter->client_id)->toBe($client->id)
        // new_client bị bỏ qua hoàn toàn — không có hồ sơ khách hàng nào tên "Tên không nên được dùng".
        ->and(Client::where('name', 'Tên không nên được dùng')->exists())->toBeFalse();
});

it('offers the create action to a lawyer but not to an accountant, whose create page is not there', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($lawyer, 'web');
    $this->livewire(ListMatters::class)->assertActionVisible('create');

    $this->actingAs($accountant, 'web');
    $this->livewire(ListMatters::class)->assertActionHidden('create');

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->get(MatterResource::getUrl('create', panel: 'admin'))->assertNotFound();
});

/**
 * SPEC §6.10: `client_role` quyết định vai đối lập dùng để tính mức đỏ, nên KHÔNG có mặc định —
 * `OpenMatter` từ chối khi thiếu. Form phải hỏi rõ, không im lặng đoán giúp.
 */
it('refuses to submit without the client role', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $data = createMatterFormData($client, $lawyer, $type);
    unset($data['client_role']);

    $this->livewire(CreateMatter::class)
        ->fillForm([...$data, 'client_role' => null])
        ->call('create')
        ->assertHasFormErrors(['client_role']);

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->exists())->toBeFalse();
});

/**
 * R13(f)/`conflict-12` (M6.5 Task 8): "Luật sư đối phương" không còn là một lựa chọn cho vai của
 * CHÍNH khách hàng — chọn nó cho khách hàng không chỉ vô nghĩa mà còn âm thầm tắt hẳn mức đỏ (xem
 * docblock `MatterForm::clientRolePartyOptions()`). `Select::options()` của Filament tự cài một
 * luật `in:` phía máy chủ dựng từ chính danh sách tuỳ chọn, nên gửi thẳng giá trị đó (bỏ qua ô
 * chọn trên trình duyệt) vẫn phải bị từ chối — đúng cổng thật, không chỉ giao diện.
 */
it('refuses opposing_counsel as the client role', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, ['client_role' => PartyRole::OpposingCounsel->value]))
        ->call('create')
        ->assertHasFormErrors(['client_role']);

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->exists())->toBeFalse();
});

/**
 * SPEC §11 "Xung đột lợi ích", bullet 1: bị đơn trùng số căn cước với một khách hàng hiện hữu →
 * chặn ở mức đỏ, không lưu được. Luật sư không được ghi đè, kể cả khi tự gõ lý do.
 */
it('blocks a red conflict for a lawyer and creates nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($lawyer, 'web');
    $matterCountBefore = Matter::count();

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
        ]))
        ->call('create')
        ->assertHasFormErrors(['override_reason']);

    expect(Matter::count())->toBe($matterCountBefore)
        ->and(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

/**
 * Ô "Lý do ghi đè" bị khoá với người không được ghi đè — màn hình không giả vờ ngược lại. Ô chỉ
 * tồn tại sau khi đã có một kết quả kiểm tra (Critical C-1), nên phải chạy một lượt bị chặn trước
 * khi có gì để khẳng định.
 */
it('disables the override reason field for a lawyer and enables it for a manager', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $redPartyForm = fn (Client $client, User $actor): array => createMatterFormData($client, $actor, $type, [
        'other_parties' => [[
            'role' => PartyRole::Defendant->value,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
    ]);

    $this->actingAs($lawyer, 'web');
    $this->livewire(CreateMatter::class)
        ->fillForm($redPartyForm(clientVisibleTo($lawyer), $lawyer))
        ->call('create')
        ->assertFormFieldDisabled('override_reason');

    $this->actingAs($manager, 'web');
    $this->livewire(CreateMatter::class)
        ->fillForm($redPartyForm(Client::factory()->create(), $manager))
        ->call('create')
        ->assertFormFieldEnabled('override_reason');
});

/** SPEC §11 bullet 2: cùng tình huống đỏ nhưng `manager` ghi đè có lý do → lưu được, lý do vào nhật ký. */
it('lets a manager override a red conflict with a reason and records the reason', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($manager, 'web');
    $reason = 'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người.';

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
        ]));

    // Lượt 1 chặn và hiện bảng kết quả; ô lý do ghi đè chỉ ra đời từ lúc này (Critical C-1).
    $component->call('create')->assertHasFormErrors(['override_reason']);

    $component->fillForm(['override_reason' => $reason])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->first();
    expect($matter)->not->toBeNull();

    $opened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($opened->properties->get('conflict_level'))->toBe('red')
        ->and($opened->properties->get('conflict_overridden'))->toBeTrue()
        ->and($opened->properties->get('override_reason'))->toBe($reason)
        ->and($opened->causer?->is($manager))->toBeTrue();
});

/**
 * SPEC §11 bullet 3: trùng tên nhưng khác căn cước và khác điện thoại → chỉ vàng, vẫn lưu được
 * sau khi tích xác nhận. Lần gửi đầu bị từ chối, lần gửi thứ hai (cùng phiên Livewire, sau khi
 * người dùng đọc bảng kết quả hiện ngay trong form) lưu được.
 */
it('refuses a yellow conflict until it is acknowledged, then saves', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $this->actingAs($lawyer, 'web');
    $matterCountBefore = Matter::count();

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => '  Lê   THỊ hoa ',
                'id_number' => '033344455566',
                'phone' => '0977888999',
            ]],
        ]));

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);

    expect(Matter::count())->toBe($matterCountBefore);

    $component->fillForm(['acknowledge_conflict' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->first();
    expect($matter)->not->toBeNull();

    // Trạng thái kiểm tra phải được dọn sau khi lưu: nút "Tạo & tạo thêm" giữ nguyên component,
    // nên một `conflictResult` sót lại sẽ hiện bảng kết quả của vụ việc TRƯỚC trên form trống mới.
    expect($component->instance()->conflictResult)->toBeNull()
        ->and($component->instance()->pendingConflictLevel)->toBeNull();

    $opened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($opened->properties->get('conflict_level'))->toBe('yellow');
});

/**
 * SPEC §6.10 đoạn cuối và §11 bullet 4 — ranh giới lộ thông tin có chủ đích: bảng kết quả chỉ
 * được hiện mã hồ sơ, loại vụ việc, vai và tên bên trùng. Vụ việc đối chiếu ở đây nằm NGOÀI quyền
 * của luật sư đang thao tác (không có tên trong đội ngũ), nên tiêu đề và tóm tắt của nó không
 * được xuất hiện ở bất kỳ đâu trong phản hồi.
 */
it('shows the conflicting matter code, type and role in the form but never its title or summary', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $secretMatter = Matter::factory()->create([
        'title' => 'TIEUDEBIMATKHONGDUOCLO',
        'summary_for_client' => 'TOMTATBIMATKHONGDUOCLO',
    ]);
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng', $secretMatter);

    expect(Matter::query()->listableBy($lawyer)->whereKey($secretMatter->getKey())->exists())->toBeFalse();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
        ]))
        ->call('create')
        // Bảng kết quả thật sự được dựng (tiêu đề mức đỏ và cột "Vai của bên đó" chỉ tồn tại
        // trong chính bảng này), và nó mang đúng mã hồ sơ + loại vụ việc của hồ sơ đối chiếu…
        ->assertSee(__('matters.conflict.heading_red'))
        ->assertSee(__('matters.conflict.column_party_role'))
        ->assertSee($secretMatter->code)
        ->assertSee($secretMatter->matterType->name)
        ->assertSee(PartyRole::Plaintiff->label())
        // …nhưng không một chữ nào từ nội dung hồ sơ đó.
        ->assertDontSee('TIEUDEBIMATKHONGDUOCLO')
        ->assertDontSee('TOMTATBIMATKHONGDUOCLO');
});

/**
 * Ô "Khách hàng" của form tạo vụ việc dùng chung `VisibleClientOptions` — một luật sư không có
 * `client.manage` không được nhìn thấy toàn bộ danh sách khách hàng của văn phòng.
 */
it('scopes the create form client picker to clients the actor can already see', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $visibleClient = clientVisibleTo($lawyer);
    $strangerClient = Client::factory()->create(['name' => 'KHACHHANGNGOAITAMNHIN']);

    $this->actingAs($lawyer, 'web');

    $options = VisibleClientOptions::forCurrentUser();

    expect($options)->toHaveKey($visibleClient->id)
        ->and($options)->not->toHaveKey($strangerClient->id);

    $this->livewire(CreateMatter::class)->assertDontSee('KHACHHANGNGOAITAMNHIN');
});

/**
 * Ô "Luật sư phụ trách" chỉ liệt kê nhân sự còn hoạt động và thật sự chạy được vụ việc
 * (`matter.transitionStage`, SPEC §5) — không phải toàn bộ bảng `users`.
 */
it('offers only active staff who may run a matter as lead lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $inactiveLawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

    $options = CreateMatter::leadLawyerOptions();

    expect($options)->toHaveKey($lawyer->id)
        ->and($options)->not->toHaveKey($accountant->id)
        ->and($options)->not->toHaveKey($inactiveLawyer->id);
});

/**
 * `intake/intake-09`: ô "Loại vụ việc" không lọc `is_active`, khác hẳn ô "Luật sư phụ trách"
 * ngay trên (test này) — một quản trị viên đã ngưng dùng một loại vụ việc (`MatterTypeForm`
 * toggle "Đang dùng") thì loại đó vẫn chọn được khi mở vụ mới. Đi đúng đường luật sư dùng: đọc
 * HTML thật của form qua Livewire, không gọi thẳng truy vấn.
 */
it('offers only active matter types in the matter-type picker', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    Client::factory()->create();

    $active = MatterType::factory()->withStages()->create(['name' => 'LOAIVUVIEC-CONHOATDONG']);
    $inactive = MatterType::factory()->withStages()->create([
        'name' => 'LOAIVUVIEC-DANGNGUNG', 'is_active' => false,
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->assertSee($active->name)
        ->assertDontSee($inactive->name);
});

/**
 * `CreateMatter::mutateFormDataBeforeCreate()` — cổng phía máy chủ cho ba id mà form gửi lên —
 * dưới dạng một closure gọi được. `protected`, nên phải buộc vào chính instance Livewire đang
 * chạy (nó đọc `Auth::user()` gián tiếp qua `VisibleClientOptions`/`leadLawyerOptions()`).
 *
 * Gọi thẳng vào đây là CÓ CHỦ ĐÍCH, không phải đi tắt: xem docblock của test dùng nó.
 */
function createMatterGuard(CreateMatter $page): Closure
{
    return Closure::bind(
        fn (array $data): array => $this->mutateFormDataBeforeCreate($data),
        $page,
        CreateMatter::class,
    );
}

/**
 * Mọi Notification mà request vừa rồi đã gửi. Đọc thẳng collection thay vì
 * `Notification::assertNotified()` vì ở đây cần CẢ màu, CẢ nội dung, CẢ SỐ LƯỢNG — và vì session
 * này bị `pull()` (đọc một lần là mất), nên mỗi test chỉ được gọi hàm này ĐÚNG MỘT LẦN.
 *
 * Tên khác `sentNotification()` của ViewMatterTest có chủ đích: hàm khai báo ở cấp cao nhất một
 * tệp Pest là hàm TOÀN CỤC, hai tệp cùng tên sẽ lỗi nạp tệp.
 *
 * @return Collection<int, Notification>
 */
function createFormNotifications(): Collection
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications;
}

/**
 * C-1 (Critical, fix round 3 review). Ô "Lý do ghi đè" từng hiện ra vô điều kiện, nên một manager
 * điền lý do TRƯỚC lượt gửi đầu tiên đi thẳng vào nhánh ghi đè của `OpenMatter` — nhánh đó trả về
 * bình thường, `$conflictResult` vẫn null, và màn hình báo "không tìm thấy bản ghi trùng nào" MÀU
 * XANH cho chính người vừa ghi đè một xung đột mức đỏ chưa từng được hiện ra.
 *
 * Test này khoá cả hai nửa của bản sửa: (1) lượt đầu KHÔNG lưu được dù đã điền sẵn lý do — bảng
 * kết quả phải hiện ra trước đã; (2) lượt hai lưu được và THÔNG BÁO nói đúng rằng vừa ghi đè mức
 * đỏ, kèm mã hồ sơ xung đột và lý do. Khẳng định trên NOTIFICATION, không chỉ trên nhật ký: nhật
 * ký vốn đã đúng từ trước, chính màn hình mới là chỗ nói dối.
 */
it('will not let a manager override a red conflict before it has been shown, and then says it was overridden', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();
    $conflictingParty = existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($manager, 'web');
    $reason = 'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người.';
    $matterCountBefore = Matter::count();

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
            // Điền NGAY lượt đầu, trước khi bất kỳ kết quả kiểm tra nào được hiện ra.
            'override_reason' => $reason,
        ]));

    $component->call('create')->assertHasFormErrors(['override_reason']);

    expect(Matter::count())->toBe($matterCountBefore)
        ->and(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse()
        ->and($component->instance()->conflictResult['level'])->toBe('red');

    // Lượt hai: bảng kết quả mức đỏ đã hiện, ô lý do giờ mới nhận được dữ liệu.
    $component->call('create')->assertHasNoFormErrors();

    expect(Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->exists())->toBeTrue();

    $overridden = createFormNotifications()
        ->first(fn (Notification $notification): bool => $notification->getTitle() === __('matters.conflict.saved_overridden'));

    expect($overridden)->not->toBeNull()
        ->and($overridden->getColor())->toBe('danger')
        ->and($overridden->getBody())->toContain($conflictingParty->matter->code)
        ->and($overridden->getBody())->toContain($reason);
});

/**
 * Nửa còn lại của C-1: ô lý do ghi đè chỉ tồn tại sau khi đã có một kết quả kiểm tra thật. Một lý
 * do viết cho một xung đột chưa ai thấy không phải là một quyết định.
 */
it('hides the override reason field until a conflict result exists, then shows it disabled to a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class);
    $component->assertFormFieldHidden('override_reason')
        ->assertFormFieldHidden('acknowledge_conflict');

    $component->fillForm(createMatterFormData($client, $lawyer, $type, [
        'other_parties' => [[
            'role' => PartyRole::Defendant->value,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
    ]))->call('create')->assertHasFormErrors(['override_reason']);

    // Giờ mới hiện — và vẫn khoá với luật sư, kèm câu giải thích ai mới ghi đè được.
    $component->assertFormFieldVisible('override_reason')
        ->assertFormFieldDisabled('override_reason')
        ->assertSee(__('matters.conflict.override_reason_help_denied'));
});

/**
 * I-5 (Important) — cổng THẬT, gọi thẳng vào `mutateFormDataBeforeCreate()` với đúng thứ một
 * request bị chỉnh sửa gửi lên.
 *
 * Vì sao không đi qua `fillForm()`: đã đo trên chính stack này — `Select::options()` của Filament
 * tự cài sẵn một luật xác thực `in:` dựng từ danh sách tuỳ chọn, nên một `client_id` giả mạo bị
 * chặn ngay ở bước xác thực ("The selected khách hàng is invalid.") và KHÔNG BAO GIỜ tới được
 * `mutateFormDataBeforeCreate()`. Một test đi qua form vì vậy sẽ xanh dù cổng dưới đây bị xoá sạch
 * — đúng loại test không thể đỏ mà bản xem xét phê bình cả nhánh này. Test này bỏ qua tầng thứ
 * nhất để khẳng định tầng thứ hai thật sự tồn tại và thật sự từ chối.
 */
it('refuses a forged party client id at the page guard, for every party in the repeater', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();
    $stranger = Client::factory()->create(['name' => 'KHACHHANGNGOAITAMNHIN']);

    $this->actingAs($lawyer, 'web');

    expect(VisibleClientOptions::forCurrentUser())
        ->toHaveKey($client->id)
        ->and(VisibleClientOptions::forCurrentUser())->not->toHaveKey($stranger->id);

    $guard = createMatterGuard($this->livewire(CreateMatter::class)->instance());

    $payload = fn (array $party): array => createMatterFormData($client, $lawyer, $type, [
        'other_parties' => [$party],
    ]);

    // Bên thứ hai của danh sách cũng bị soi, không chỉ bên đầu tiên.
    $twoParties = createMatterFormData($client, $lawyer, $type, [
        'other_parties' => [
            ['role' => PartyRole::Defendant->value, 'name' => 'Bên hợp lệ'],
            ['role' => PartyRole::Related->value, 'name' => 'Bên giả mạo', 'is_our_client' => true, 'client_id' => $stranger->id],
        ],
    ]);

    expect(fn () => $guard($payload([
        'role' => PartyRole::Defendant->value,
        'name' => 'Tên do người gửi tự đặt',
        'is_our_client' => true,
        'client_id' => $stranger->id,
    ])))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $guard($twoParties))->toThrow(NotFoundHttpException::class);

    // …và cổng không chặn nhầm những gì hợp lệ: khách hàng nhìn thấy được, và một bên đối lập
    // bình thường (không bật công tắc "là khách hàng của văn phòng") vẫn đi qua.
    expect($guard($payload([
        'role' => PartyRole::Defendant->value,
        'name' => 'Bị đơn bình thường',
        'is_our_client' => false,
        'client_id' => $stranger->id,
    ])))->toBeArray()
        ->and($guard($payload([
            'role' => PartyRole::Defendant->value,
            'name' => 'Đồng nguyên đơn là khách hàng của văn phòng',
            'is_our_client' => true,
            'client_id' => $client->id,
        ])))->toBeArray();
});

/**
 * SPEC §10.10: "không có quyền" và "không tồn tại" phải trả về CÙNG một mã, 404 — một 403 ở đây tự
 * nó xác nhận rằng bản ghi mang id vừa gửi là có thật. Khẳng định trên chính hàm dùng chung, vì
 * đây là nơi cả hai màn hình lấy câu trả lời.
 */
it('answers 404, not 403, for a client id outside the actors scope', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $visible = clientVisibleTo($lawyer);
    $stranger = Client::factory()->create();

    $this->actingAs($lawyer, 'web');

    $notFound = fn (mixed $id): Closure => fn () => VisibleClientOptions::assertVisibleToCurrentUser($id);

    expect($notFound($stranger->id))->toThrow(NotFoundHttpException::class)
        // Một id không tồn tại phải cho đúng câu trả lời như một id ngoài tầm nhìn.
        ->and($notFound(999_999))->toThrow(NotFoundHttpException::class)
        ->and($notFound(null))->toThrow(NotFoundHttpException::class);

    // Không chặn nhầm: khách hàng thật sự nhìn thấy được thì đi qua im lặng.
    VisibleClientOptions::assertVisibleToCurrentUser($visible->id);
    VisibleClientOptions::assertVisibleToCurrentUser((string) $visible->id);

    expect(true)->toBeTrue();
});

/** Cùng cổng đó cho `lead_lawyer_id`: cũng 404, cũng không phân biệt "ngoài danh sách" với "không có". */
it('answers 404 for a lead lawyer outside the offered list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $guard = createMatterGuard($this->livewire(CreateMatter::class)->instance());

    // Kế toán không có matter.transitionStage nên không bao giờ nằm trong leadLawyerOptions().
    expect(fn () => $guard(createMatterFormData($client, $accountant, $type)))
        ->toThrow(NotFoundHttpException::class);
});

/**
 * Minor 3/4. Bảng kết quả kiểm tra là một ẢNH CHỤP: nó mô tả đúng những bên đã có lúc bấm lưu. Nếu
 * người dùng sửa một bên rồi bấm lưu lại, dấu tích "đã xem xét" đang nói về một bảng KHÁC — và vì
 * xác nhận chỉ khớp theo MỨC, một thay đổi giữ nguyên mức vàng sẽ được nhận nhầm là đã xem xét.
 */
it('drops a yellow acknowledgement when a party changes between two submits', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    foreach (['Lê Thị Hoa', 'Trần Văn Bình'] as $name) {
        MatterParty::factory()->for(Matter::factory()->create())->create([
            'role' => PartyRole::Plaintiff,
            'is_our_client' => true,
            'name' => $name,
        ]);
    }

    $this->actingAs($lawyer, 'web');
    $matterCountBefore = Matter::count();

    $party = fn (string $name): array => [[
        'role' => PartyRole::Defendant->value,
        'name' => $name,
        'id_number' => '033344455566',
        'phone' => '0977888999',
    ]];

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, ['other_parties' => $party('Lê Thị Hoa')]));

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);
    expect($component->instance()->pendingConflictLevel)->toBe('yellow');

    // Người dùng tích xác nhận cho BẢNG ĐANG HIỆN, rồi đổi bên sang một người khác — vẫn vàng,
    // nhưng là một bảng khác hẳn.
    $component->fillForm(['acknowledge_conflict' => true]);
    $component->fillForm(['other_parties' => $party('Trần Văn Bình')]);

    expect($component->instance()->conflictResult)->toBeNull()
        ->and($component->instance()->pendingConflictLevel)->toBeNull()
        ->and($component->instance()->data['acknowledge_conflict'])->toBeFalse();

    // Dấu tích cũ không được mang sang: lượt lưu này phải bị từ chối lại.
    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);

    expect(Matter::count())->toBe($matterCountBefore);
});

/** Minor 6: một lần lưu sạch chỉ được hiện ĐÚNG MỘT thông báo — không kèm thông báo mặc định của Filament. */
it('sends exactly one notification on a clean save', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bị đơn không trùng ai',
                'id_number' => '098765432100',
                'phone' => '0911222333',
            ]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $notifications = createFormNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->getTitle())->toBe(__('matters.conflict.saved_clear'))
        ->and($notifications->first()->getColor())->toBe('success');
});

/**
 * Minor 7: một vụ việc tạo ra đã bật sẵn công tắc "công bố cho khách" phải để lại chính dòng nhật
 * ký mà `SetMatterPortalPublication` để lại — nếu không, lịch sử công bố của vụ việc đó bắt đầu
 * bằng một khoảng trắng và M6 không có gì để đối chiếu ở ngay điểm khởi đầu.
 */
it('records a portal publication audit row when the matter is created already published', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, ['is_published_to_portal' => true]))
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà')->first();
    $published = Activity::query()->where('event', 'matter_portal_publication_set')->latest('id')->first();

    expect($matter->is_published_to_portal)->toBeTrue()
        ->and($published)->not->toBeNull()
        ->and((int) $published->subject_id)->toBe($matter->id)
        ->and($published->properties->get('publish'))->toBeTrue()
        ->and($published->causer?->is($lawyer))->toBeTrue();
});

/** Một vụ việc KHÔNG công bố thì không được sinh ra dòng nhật ký công bố nào. */
it('records no portal publication audit row when the matter is created unpublished', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Activity::query()->where('event', 'matter_portal_publication_set')->exists())->toBeFalse();
});

/**
 * I-2 (Important, fix round 4), nửa MÀN HÌNH cho form mở vụ việc — bản sinh đôi của test ở
 * `ViewMatterTest`. Luật nằm ở `BuildsMatterParties`; ô này chỉ nói ra luật đó bằng một lỗi gắn
 * đúng dòng bên trong repeater, để người dùng sửa được thay vì gặp một ngoại lệ nghiệp vụ.
 */
it('will not open a matter with an other-party marked as our client but no client record', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = clientVisibleTo($lawyer);
    $type = createFormMatterType();
    $matterCountBefore = Matter::count();

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $lawyer, $type, [
            'other_parties' => [[
                'role' => PartyRole::Related->value,
                'name' => 'Tên gõ tay',
                'is_our_client' => true,
                'client_id' => null,
                'id_number' => '079012345678',
            ]],
        ]))
        ->call('create')
        ->assertHasFormErrors(['other_parties.0.client_id']);

    expect(Matter::count())->toBe($matterCountBefore);
});

/**
 * Minor (fix round 4): `forgetConflictResult()` xoá mức đang chờ và dấu tích, nhưng KHÔNG xoá
 * `data['override_reason']` — nên một lý do viết cho một bảng đỏ này còn nguyên khi bảng đỏ KẾ
 * TIẾP hiện ra, và lượt gửi sau đó ghi đè bằng một câu chưa ai viết cho xung đột đó. Cùng hạng lỗi
 * với C-1, chỉ nhỏ hơn: quyết định vẫn là quyết định cho một xung đột khác.
 */
it('clears a written override reason when the conflict result it was written for is forgotten', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($manager, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
        ]));

    $component->call('create')->assertHasFormErrors(['override_reason']);

    $component->fillForm(['override_reason' => 'Lý do viết cho BẢNG ĐỎ THỨ NHẤT.']);
    $component->instance()->forgetConflictResult();

    expect($component->instance()->data['override_reason'])->toBeNull();
});

/**
 * I-A (Important, review gộp nhánh M3) — bản sinh đôi của test cùng tên ở `ViewMatterTest`. Cổng
 * của `override_reason` hỏi "đã có kết quả kiểm tra nào chưa?" chứ không hỏi "đã có kết quả ĐỎ nào
 * chưa?", mà ô này `visible()` trên MỌI kết quả đã lưu, VÀNG kể cả. Một lý do viết trong vòng vàng
 * vì thế sống sót sang một lần kiểm tra ĐỎ (một lần thêm bên song song, hay một lần sửa hồ sơ
 * `Client` kích hoạt `SyncClientPartyIdentities` ghi lại `id_number_hash`) và được nhận là một
 * quyết định ghi đè có chủ ý — vào nhật ký append-only vĩnh viễn, về một bảng đỏ chưa ai từng thấy.
 *
 * Leo mức dựng THẬT: khớp theo tên trần là vàng (§11), rồi bên kia được bổ sung đúng số căn cước
 * giữa hai lượt gửi.
 */
it('will not honour an override reason written during a yellow round when the conflict escalates to red', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();

    // Khách hàng của văn phòng ở vụ khác, TRÙNG TÊN bên sắp nhập nhưng chưa có định danh nào.
    $otherMatter = Matter::factory()->create();
    $twin = MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Nguyễn Văn Hùng',
    ]);

    $this->actingAs($manager, 'web');
    $matterCountBefore = Matter::count();

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng',
                'id_number' => '079012345678',
            ]],
        ]));

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);

    expect($component->instance()->conflictResult['level'])->toBe('yellow');

    // Giữa hai lượt gửi: hồ sơ bên kia được bổ sung đúng số căn cước đang nhập.
    $twin->identify('079012345678', null)->save();

    $component->fillForm(['override_reason' => 'Lý do viết cho một vòng VÀNG.'])
        ->call('create')
        ->assertHasFormErrors(['override_reason']);

    expect(Matter::count())->toBe($matterCountBefore)
        ->and(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

/**
 * I-B, nửa của màn hình này: bảng trong form đã hiện tên bên trùng (`conflictResultViewData()`),
 * nhưng THÔNG BÁO sau khi lưu — thứ duy nhất còn lại khi form biến mất — thì không, dù docblock
 * `conflictSummary()` tự nhận là có. SPEC §6.10 "Đính chính 2026-09-16": không có tên thì người
 * dùng không kiểm chứng hay phản bác được kết quả.
 */
it('names the matched party in the notification after a red conflict is overridden', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($manager, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bị đơn trùng căn cước',
                'id_number' => '079012345678',
            ]],
        ]));

    $component->call('create')->assertHasFormErrors(['override_reason']);

    $component->fillForm(['override_reason' => 'Đã xác minh, không phải cùng một người.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $overridden = createFormNotifications()
        ->first(fn (Notification $notification): bool => $notification->getTitle() === __('matters.conflict.saved_overridden'));

    expect($overridden)->not->toBeNull()
        // Tên của bên trùng ở hồ sơ kia, không phải tên bên vừa nhập.
        ->and($overridden->getBody())->toContain('Nguyễn Văn Hùng');
});

// =========================================================================================
// Final review A-M7: hồ sơ khách hàng mới (khối "Tạo khách mới") chỉ được tạo SAU khi kiểm tra
// xung đột cho qua — một lần bị chặn đỏ không để lại khách hàng mồ côi, và lượt gửi lại dùng đúng
// dữ liệu người dùng vừa sửa trên form.
// =========================================================================================

it('creates no client at all when a new client is blocked by a red conflict', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    // Bị đơn của vụ mới chính là một khách hàng hiện tại của văn phòng → đỏ.
    existingFirmClientParty('079012300001', 'Khách hiện tại của văn phòng');

    $this->actingAs($lawyer, 'web');

    $clientsBefore = Client::count();

    $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc bị chặn đỏ',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Khách hiện tại (bị đơn)',
                'id_number' => '079012300001',
            ]],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Khách mới bị chặn',
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['override_reason']);

    expect(Client::count())->toBe($clientsBefore)
        ->and(Matter::query()->where('title', 'Vụ việc bị chặn đỏ')->exists())->toBeFalse();
});

it('uses the corrected new-client details on the second submit after a yellow block', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = createFormMatterType();

    $twinMatter = Matter::factory()->create();
    MatterParty::factory()->for($twinMatter)->create(['name' => 'Trùng tên lần hai', 'is_our_client' => false]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $type->id,
            'title' => 'Vụ việc sửa dữ liệu khách giữa hai lượt',
            'lead_lawyer_id' => $lawyer->id,
            'other_parties' => [],
            'new_client' => [
                'type' => ClientType::Individual->value,
                'name' => 'Trùng tên lần hai',
                'address' => 'Địa chỉ gõ sai',
            ],
        ]);

    $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);

    // Người dùng sửa dữ liệu khách mới — nếu lần sửa xoá kết quả kiểm tra (xem
    // `forgetConflictResult()`), lượt gửi kế tiếp chạy lại kiểm tra; tích xác nhận rồi gửi.
    $component->fillForm([
        'new_client' => [
            'type' => ClientType::Individual->value,
            'name' => 'Trùng tên lần hai',
            'address' => 'Địa chỉ đã sửa',
        ],
    ]);

    if ($component->instance()->conflictResult === null) {
        $component->call('create')->assertHasFormErrors(['acknowledge_conflict']);
    }

    expect(Client::query()->where('name', 'Trùng tên lần hai')->exists())->toBeFalse();

    $component->fillForm(['acknowledge_conflict' => true])->call('create')->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Trùng tên lần hai')->sole();

    expect($client->address)->toBe('Địa chỉ đã sửa')
        ->and(Matter::query()->where('title', 'Vụ việc sửa dữ liệu khách giữa hai lượt')->first()?->client_id)->toBe($client->id);
});
