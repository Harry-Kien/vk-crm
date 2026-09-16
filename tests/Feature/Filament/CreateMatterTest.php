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
