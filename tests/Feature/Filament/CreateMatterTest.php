<?php

use App\Enums\PartyRole;
use App\Enums\Role;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

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
 * sẵn một vụ việc cũ nối hai người. `Matter::factory()` KHÔNG tạo `matter_parties`, nên vụ việc
 * cũ này không làm nhiễu kết quả kiểm tra xung đột của các test bên dưới.
 */
function clientVisibleTo(User $lawyer): Client
{
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);

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

it('offers the create action to a lawyer but not to an accountant, who also cannot reach the create page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($lawyer, 'web');
    $this->livewire(ListMatters::class)->assertActionVisible('create');

    $this->actingAs($accountant, 'web');
    $this->livewire(ListMatters::class)->assertActionHidden('create');

    $this->get(MatterResource::getUrl('create', panel: 'admin'))->assertForbidden();
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

/** Ô "Lý do ghi đè" bị khoá với người không được ghi đè — màn hình không giả vờ ngược lại. */
it('disables the override reason field for a lawyer and enables it for a manager', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($lawyer, 'web');
    $this->livewire(CreateMatter::class)->assertFormFieldDisabled('override_reason');

    $this->actingAs($manager, 'web');
    $this->livewire(CreateMatter::class)->assertFormFieldEnabled('override_reason');
});

/** SPEC §11 bullet 2: cùng tình huống đỏ nhưng `manager` ghi đè có lý do → lưu được, lý do vào nhật ký. */
it('lets a manager override a red conflict with a reason and records the reason', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $type = createFormMatterType();
    existingFirmClientParty('079012345678', 'Nguyễn Văn Hùng');

    $this->actingAs($manager, 'web');
    $reason = 'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người.';

    $this->livewire(CreateMatter::class)
        ->fillForm(createMatterFormData($client, $manager, $type, [
            'other_parties' => [[
                'role' => PartyRole::Defendant->value,
                'name' => 'Nguyễn Văn Hùng (bị đơn)',
                'id_number' => '079012345678',
            ]],
            'override_reason' => $reason,
        ]))
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
