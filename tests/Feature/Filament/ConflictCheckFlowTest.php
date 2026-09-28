<?php

use App\Actions\OpenMatter;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * M6.5 Task 8 — R13(b)/`conflict-03` và R13(c)/`conflict-01`, đi qua MÀN HÌNH thật (Livewire), cả
 * hai mặt của quy tắc: form mở vụ (`CreateMatter`) VÀ tab "Các bên" (`PartiesRelationManager`) —
 * đúng "tương tự khi thêm bên thứ hai qua tab Các bên" mà brief đòi cho test (b). Các test đơn vị
 * thuần (không qua Livewire) và mọi mutation probe nằm ở `tests/Feature/Actions/
 * RunConflictCheckTest.php`; tệp này chỉ khẳng định lại đúng những quy tắc đó còn đứng vững khi đi
 * qua đường người dùng thật, không gọi thẳng Action (CLAUDE.md, quy ước TDD của brief M6.5).
 *
 * **Không tái dùng các hàm toàn cục của `CreateMatterTest.php`/`ViewMatterTest.php`
 * (`createMatterFormData()`, `sentNotification()`), dù chạy tuần tự thì vẫn gọi được — đo được
 * dưới `bin/dev test --parallel`.** ParaTest chạy MỖI tệp test trong một tiến trình PHP RIÊNG khi
 * chia việc theo tệp, nên một hàm toàn cục khai báo ở tệp KHÁC không được nạp vào tiến trình xử lý
 * tệp này — `Call to undefined function createMatterFormData()`. Tệp này tự mang bản riêng của cả
 * hai, tên riêng theo đúng quy ước "hàm toàn cục" đã ghi ở `CreateMatterTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** Loại vụ việc kèm giai đoạn — tên riêng, xem quy ước "hàm toàn cục" ở CreateMatterTest.php. */
function conflictFlowMatterType(): MatterType
{
    $type = MatterType::factory()->withStages()->create();
    ChecklistTemplate::factory()->withItems(1)->for($type, 'matterType')->create();

    return $type;
}

/** @return array<string, mixed> dữ liệu form tối thiểu để mở một vụ việc — bản riêng của tệp này. */
function conflictFlowMatterFormData(Client $client, User $leadLawyer, MatterType $type, array $overrides = []): array
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
 * Mọi Notification mà request vừa rồi đã gửi — bản riêng của tệp này, cùng hình dạng
 * `sentNotification()`/`createFormNotifications()` của `ViewMatterTest.php`/`CreateMatterTest.php`.
 *
 * @return Collection<int, Notification>
 */
function conflictFlowNotifications(): Collection
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications;
}

/**
 * R13(b)/`conflict-03` — brief test (b), nhánh "form mở vụ". Hai khách hàng của văn phòng, CHƯA
 * từng có vụ nào, ở hai vai đối lập của CÙNG một vụ việc mới: phải Đỏ, không được lưu im lặng.
 */
it('blocks a new matter in the create-matter screen when two of our own clients are opposing parties', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $mainClient = Client::factory()->create(['name' => 'Khách hàng X']);
    $opposingOwnClient = Client::factory()->create(['name' => 'Khách hàng W']);
    $type = conflictFlowMatterType();

    $this->livewire(CreateMatter::class)
        ->fillForm(conflictFlowMatterFormData($mainClient, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => $opposingOwnClient->name,
                'is_our_client' => true,
                'client_id' => $opposingOwnClient->id,
            ]],
        ]))
        ->call('create')
        ->assertHasFormErrors(['override_reason']);

    expect(Matter::query()->where('client_id', $mainClient->id)->exists())->toBeFalse();
});

/** Cùng R13(b), nhánh "tab Các bên" của một vụ việc ĐANG chạy. */
it('blocks adding a second party to the parties tab when it opposes an existing party who is also our own client', function () {
    // Manager (client.manage) chứ không phải luật sư thường: bên W mới hoàn toàn chưa từng có vụ
    // nào, nên VisibleClientOptions sẽ không cho một luật sư thường nhìn thấy id đó — một câu hỏi
    // KHÁC (SPEC §5, roles-04) mà test này không nhắm tới. Ở đây chỉ muốn khẳng định R13(b).
    $manager = User::factory()->withRole(Role::Manager)->create();
    $x = Client::factory()->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    MatterParty::factory()->for($matter)->ourClient($x, PartyRole::Plaintiff)->create();

    $w = Client::factory()->create();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => true,
        'client_id' => $w->id,
        'name' => $w->name,
    ])->assertHasTableActionErrors(['override_reason']);

    expect($matter->parties()->where('client_id', $w->id)->exists())->toBeFalse();
});

/**
 * R13(c)/`conflict-01` — brief test (c), xuyên suốt HAI Action thật qua HAI màn hình: trưởng
 * phòng ghi đè đỏ lúc mở vụ (`CreateMatter` → `OpenMatter`), sau đó (một request KHÁC, đúng hình
 * dạng "lần chạy sau" mà R13c mô tả) một nhân chứng hoàn toàn sạch được thêm qua tab "Các bên"
 * (`PartiesRelationManager` → `AddMatterParty`) — phải lưu được, không đòi ghi đè lại. Khớp cũ vẫn
 * phải "hiện" trong thông báo kết quả, chỉ không còn chặn.
 */
it('does not re-block an already-overridden pair when a clean witness is added later, and still shows it in the result', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $conflictingClient = Client::factory()->create(['id_number' => '071122334455', 'name' => 'Nguyễn Văn Xung Đột']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($conflictingClient, PartyRole::Plaintiff)->create();

    $client = Client::factory()->create();
    $type = conflictFlowMatterType();

    $component = $this->livewire(CreateMatter::class)
        ->fillForm(conflictFlowMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bị đơn trùng CCCD',
                'id_number' => '071122334455',
            ]],
        ]));

    $component->call('create')->assertHasFormErrors(['override_reason']);

    $component->fillForm(['override_reason' => 'Đã xác minh, không phải cùng một người.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('client_id', $client->id)->first();
    expect($matter)->not->toBeNull();

    $opened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($opened->properties->get('confirmed_pairs'))->not->toBeEmpty();

    // Lần chạy SAU trên cùng vụ việc: một nhân chứng hoàn toàn sạch. Cặp (bị đơn trùng CCCD ↔ hồ
    // sơ Nguyễn Văn Xung Đột) vẫn tồn tại trong $matter->parties() và vẫn bị RunConflictCheck xét
    // lại (cố ý — "fix round 3"), nhưng KHÔNG được phép chặn lại lần này (R13c).
    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => false,
        'name' => 'Nhân chứng hoàn toàn sạch',
        'id_number' => '099000000999',
        'phone' => '0933000999',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Nhân chứng hoàn toàn sạch')->exists())->toBeTrue();

    // Fix round 1, C3 (Critical, `conflict-01`): không phải "không tìm thấy xung đột" màu xanh —
    // thân thông báo vẫn liệt kê cặp bên đã ghi đè trước đó, nên tiêu đề/màu phải nói đúng điều
    // đó (`saved_clear_with_confirmed`, màu warning), không phải `conflict_check_title_clear`.
    $saved = conflictFlowNotifications()
        ->first(fn (Notification $notification): bool => $notification->getTitle() === __('matters.parties.saved_clear_with_confirmed', ['count' => 1]));

    expect($saved)->not->toBeNull()
        ->and($saved->getColor())->toBe('warning')
        // Khớp cũ (đã ghi đè trước đó) "vẫn hiện" trong kết quả — R13c bullet, và §11.
        ->and($saved->getBody())->toContain($conflictMatter->code)
        // Spec gap (fix round 1): "bên phía mình" (R13d) và nhãn "đã xem xét ở lần trước" (R13c)
        // giờ có trong thông báo, không chỉ trong bảng của CreateMatter.
        ->and($saved->getBody())->toContain(__('matters.conflict.already_confirmed'))
        ->and($saved->getBody())->toContain(__('matters.conflict.column_our_party'));
});

/**
 * Fix round 2, NB1 (Important) — một bị đơn bị gõ TRÙNG HAI LẦN trên form mở vụ, cả hai đều khớp
 * ĐÚNG một khách hàng của văn phòng ở vụ khác (Đỏ). Ở màn hình, hai dòng đó hiện thành MỘT dòng
 * DUY NHẤT (đúng thiết kế gộp hiển thị, xem test "deduplicates identical matches..." ở
 * `RunConflictCheckTest.php`) — nhưng đó là HAI cặp (dòng, dòng tìm thấy) THẬT khác nhau bên dưới.
 * Trước bản sửa này, `confirmed_pairs` chỉ ghi lại MỘT trong hai cặp đó (cặp còn lại "vô hình" vì
 * bị gộp mất trước khi ghi) — lần thêm bên KẾ TIẾP, HOÀN TOÀN không liên quan, làm
 * `RunConflictCheck` xét lại cả hai dòng bị đơn (fix round 3, xét lại MỌI bên đã có), thấy cặp thứ
 * hai là "Đỏ MỚI" (chưa từng được ghi) và CHẶN CỨNG người thêm — dù manager vừa ghi đè xong.
 */
it('does not permanently hard-block after a duplicate-row match is overridden once', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($manager, 'web');

    $conflictingClient = Client::factory()->create(['id_number' => '074455667788', 'name' => 'Trần Văn Trùng Dòng']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($conflictingClient, PartyRole::Plaintiff)->create();

    $client = Client::factory()->create();
    $type = conflictFlowMatterType();

    // Đúng kịch bản NB1: CÙNG một bị đơn gõ HAI LẦN (nhập nhầm), cả hai đều khớp Đỏ với hồ sơ của
    // Trần Văn Trùng Dòng — hai dòng THẬT khác nhau trên `other_parties`, giống hệt nhau ở mọi
    // trường hiển thị.
    $component = $this->livewire(CreateMatter::class)
        ->fillForm(conflictFlowMatterFormData($client, $lawyer, $type, [
            'other_parties' => [
                ['role' => PartyRole::Defendant->value, 'name' => 'Bị đơn gõ trùng', 'id_number' => '074455667788'],
                ['role' => PartyRole::Defendant->value, 'name' => 'Bị đơn gõ trùng', 'id_number' => '074455667788'],
            ],
        ]));

    $component->call('create')->assertHasFormErrors(['override_reason']);

    $component->fillForm(['override_reason' => 'Đã xác minh, không phải cùng một người.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('client_id', $client->id)->first();
    expect($matter)->not->toBeNull()
        ->and($matter->parties()->where('name', 'Bị đơn gõ trùng')->count())->toBe(2);

    // Luật sư phụ trách (KHÔNG ghi đè được) thêm một nhân chứng hoàn toàn không liên quan — không
    // được phép chặn lại, dù RunConflictCheck xét lại CẢ HAI dòng "Bị đơn gõ trùng" ở lần này.
    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => false,
        'name' => 'Nhân chứng hoàn toàn không liên quan (NB1)',
        'id_number' => '074455667799',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Nhân chứng hoàn toàn không liên quan (NB1)')->exists())->toBeTrue();
});

/**
 * I2 (fix round 1) — mutation-probe-worthy screen test cho chính lệnh ghi `confirmed_pairs` của
 * `AddMatterParty` (không phải của `OpenMatter`, như test ngay trên). Trưởng phòng ghi đè một mức
 * Đỏ NGAY TRÊN tab "Các bên" (không qua form mở vụ), rồi cùng luật sư phụ trách thêm một bên KHÔNG
 * liên quan qua CHÍNH tab đó — phải không bị chặn lại, chứng minh chính dòng `matter_party_added`
 * (không phải `matter_opened`) đã ghi đúng `confirmed_pairs`.
 */
it('does not re-block a pair overridden on the parties tab itself, proving AddMatterParty writes confirmed_pairs', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $conflictingClient = Client::factory()->create(['id_number' => '073344556677', 'name' => 'Phạm Văn Đối Lập']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($conflictingClient, PartyRole::Plaintiff)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($matter)->ourClient(Client::factory()->create(), PartyRole::Plaintiff)->create();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn trùng CCCD (tab Các bên)',
        'id_number' => '073344556677',
    ])->assertHasTableActionErrors(['override_reason'])
        ->setTableActionData([
            'role' => PartyRole::Defendant->value,
            'is_our_client' => false,
            'name' => 'Bị đơn trùng CCCD (tab Các bên)',
            'id_number' => '073344556677',
            'override_reason' => 'Đã xác minh, không phải cùng một người.',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $added = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();
    expect($added)->not->toBeNull()
        ->and($added->properties->get('confirmed_pairs'))->not->toBeEmpty();

    // Luật sư phụ trách (không ghi đè được) thêm một bên KHÔNG liên quan — không được chặn lại.
    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => false,
        'name' => 'Nhân chứng không liên quan (tab Các bên)',
        'id_number' => '073344556688',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Nhân chứng không liên quan (tab Các bên)')->exists())->toBeTrue();
});

/**
 * Fix round 3, minor — NB1 chưa có bằng chứng riêng cho `AddMatterParty` ở đúng hình dạng phụ mà
 * finding NB1 (round 2) nêu: MỘT bên MỚI (qua tab "Các bên", không phải form mở vụ) khớp HAI dòng
 * LỊCH SỬ giống hệt nhau ở một vụ việc khác (nhập trùng ở phía kia, không phải phía mình) — thay vì
 * hai bên mới khớp một dòng lịch sử (đã có test ngay trên). `RunConflictCheckTest.php` đã có bản
 * đơn vị cho `allNewMatches` (round 2); đây là bản MÀN HÌNH, qua đúng tab "Các bên".
 */
it('does not permanently hard-block on the parties tab when a new party matches two identical historical rows', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $conflictingClient = Client::factory()->create(['id_number' => '075566778899', 'name' => 'Lê Văn Trùng Sử']);
    $otherMatter = Matter::factory()->create();
    // Hai dòng LỊCH SỬ thật khác nhau, giống hệt nhau (nhập trùng ở phía BÊN KIA, không phải phía
    // mình) — khác test ngay trên (nơi hai dòng MỚI trùng nhau khớp MỘT dòng lịch sử).
    MatterParty::factory()->for($otherMatter)->ourClient($conflictingClient, PartyRole::Plaintiff)->create();
    MatterParty::factory()->for($otherMatter)->ourClient($conflictingClient, PartyRole::Plaintiff)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($matter)->ourClient(Client::factory()->create(), PartyRole::Defendant)->create();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => false,
        'name' => 'Bên mới khớp hai dòng cũ',
        'id_number' => '075566778899',
    ])->assertHasTableActionErrors(['override_reason'])
        ->setTableActionData([
            'role' => PartyRole::Plaintiff->value,
            'is_our_client' => false,
            'name' => 'Bên mới khớp hai dòng cũ',
            'id_number' => '075566778899',
            'override_reason' => 'Đã xác minh, không phải cùng một người.',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    // Luật sư phụ trách (không ghi đè được) thêm một bên KHÔNG liên quan — không được chặn lại, dù
    // RunConflictCheck xét lại CẢ HAI dòng lịch sử ở lần này.
    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => false,
        'name' => 'Nhân chứng không liên quan (NB1 phụ)',
        'id_number' => '075566778800',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Nhân chứng không liên quan (NB1 phụ)')->exists())->toBeTrue();
});

/**
 * R13(g)/`conflict-06` — bullet cuối của test (g) brief: "Dòng conflict_check_run lúc mở vụ có
 * subject là vụ vừa tạo". Đi qua Action trực tiếp là đủ ở đây (không phải hành vi riêng của màn
 * hình): `OpenMatter` là nơi duy nhất gắn lại `subject`, và `CreateMatter` chỉ gọi thẳng nó.
 */
it('links the conflict_check_run row of a matter opening to the matter it just created', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $type = conflictFlowMatterType();

    $opening = app(OpenMatter::class)->handle($lawyer, [
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff,
        'matter_type_id' => $type->id,
        'title' => 'Vụ việc kiểm tra subject',
        'lead_lawyer_id' => $lawyer->id,
    ], []);

    $checkRun = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();

    expect($checkRun)->not->toBeNull()
        ->and($checkRun->subject_type)->toBe($opening->matter->getMorphClass())
        ->and((int) $checkRun->subject_id)->toBe($opening->matter->getKey());
});

/**
 * Fix round 2, I1 — qua màn hình mở vụ thật: một bên đã GỠ (xoá mềm) khỏi vụ việc KHÁC không còn
 * chặn được việc mở một vụ mới, dù trùng đúng số CCCD. `RunConflictCheckTest.php` có bản đơn vị
 * (mutation probe ở đó); test này khẳng định lại đúng quy tắc còn đứng vững khi đi qua `CreateMatter`
 * thật, không gọi thẳng Action.
 */
it('does not block a new matter in the create-matter screen with a party removed from a different matter', function () {
    // Manager (client.manage), cùng quy ước với "blocks a new matter..." ở trên: VisibleClientOptions
    // không cho một luật sư thường thấy một khách hàng hoàn toàn mới, chưa từng có vụ nào — một câu
    // hỏi KHÁC (SPEC §5) mà test này không nhắm tới.
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $existingClient = Client::factory()->create(['id_number' => '071900000099']);
    $otherMatter = Matter::factory()->create();
    $removedParty = MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();
    $removedParty->delete();

    $newClient = Client::factory()->create();
    $type = conflictFlowMatterType();

    $this->livewire(CreateMatter::class)
        ->fillForm(conflictFlowMatterFormData($newClient, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Bên trùng số CCCD của một dòng đã gỡ',
                'is_our_client' => false,
                'id_number' => '071900000099',
            ]],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Matter::query()->where('client_id', $newClient->id)->exists())->toBeTrue();
});
