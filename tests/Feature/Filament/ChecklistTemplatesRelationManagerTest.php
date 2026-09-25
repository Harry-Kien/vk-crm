<?php

use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\ChecklistTemplatesRelationManager;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Tab "Danh mục hồ sơ mẫu" trên loại vụ việc (M6.5 Task 15, SPEC §1/§7.4).
 *
 * Trước task này không màn hình nào quản lý được `ChecklistTemplate`: ba loại vụ việc không có
 * mẫu (Hình sự, Doanh nghiệp, Lao động trên dữ liệu dev) mở ra với 0 đầu mục vĩnh viễn, và mẫu
 * chỉ sinh ra được bằng seeder. Test ở đây đo đúng bốn việc task brief đòi: admin tạo được mẫu
 * qua giao diện và một vụ mở SAU đó nhận đủ đầu mục; vụ đã mở TRƯỚC đó không đổi; và luật sư
 * không có `settings.manage` không vào được trang này.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function checklistTemplatesTab(MatterType $type)
{
    return test()->livewire(ChecklistTemplatesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ]);
}

/** Khách hàng mà `$lawyer` thấy được trong ô chọn của form mở vụ (cùng helper CreateMatterTest). */
function checklistTemplateTestClientVisibleTo(User $lawyer): Client
{
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);

    return $client;
}

/** @return array<string, mixed> dữ liệu form tối thiểu để mở một vụ việc qua CreateMatter thật. */
function checklistTemplateTestMatterFormData(Client $client, User $leadLawyer, MatterType $type, string $title): array
{
    return [
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff->value,
        'matter_type_id' => $type->id,
        'title' => $title,
        'lead_lawyer_id' => $leadLawyer->id,
        'summary_for_client' => 'Tóm tắt gửi khách hàng.',
        'other_parties' => [],
    ];
}

/**
 * SPEC §14 mục 4: đây là tiêu chí nghiệm thu mà finding `intake-02`/`checklist-02` nói KHÔNG đạt
 * được trên giao diện — ba loại vụ việc mở ra với 0 đầu mục và không ai sửa được. Test này đi
 * đúng đường giao diện thật, cả hai đầu: admin tạo mẫu qua Livewire, rồi một luật sư mở vụ qua
 * `CreateMatter` (không gọi thẳng `OpenMatter`/`ApplyChecklistTemplate`).
 */
it('lets an admin create a checklist template through the relation manager, and a matter opened afterward gets its items', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create(['code' => 'HS', 'name' => 'Hình sự']);

    expect($type->checklistTemplates()->count())->toBe(0);

    $this->actingAs($admin, 'web');

    checklistTemplatesTab($type)
        ->callTableAction('create', data: [
            'name' => 'Danh mục Hình sự',
            'is_active' => true,
            'items' => [
                ['name' => 'CMND/CCCD của bị can', 'description' => 'Bản sao có chứng thực.', 'is_required' => true, 'sort_order' => 1],
                ['name' => 'Đơn tố giác', 'description' => null, 'is_required' => false, 'sort_order' => 2],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $template = ChecklistTemplate::query()->where('matter_type_id', $type->id)->sole();

    expect($template->name)->toBe('Danh mục Hình sự')
        ->and($template->is_active)->toBeTrue()
        ->and($template->items()->count())->toBe(2)
        ->and($template->items()->pluck('name')->all())->toBe(['CMND/CCCD của bị can', 'Đơn tố giác']);

    // Bây giờ một luật sư mở một vụ Hình sự MỚI, đi đúng đường CreateMatter thật.
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = checklistTemplateTestClientVisibleTo($lawyer);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(checklistTemplateTestMatterFormData($client, $lawyer, $type, 'Vụ án hình sự thử nghiệm'))
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ án hình sự thử nghiệm')->sole();

    expect($matter->checklistItems()->count())->toBe(2)
        ->and($matter->checklistItems()->pluck('name')->sort()->values()->all())
        ->toBe(['CMND/CCCD của bị can', 'Đơn tố giác']);
});

/**
 * "Sửa mẫu không đổi danh mục của các vụ đã mở" (task brief) — ở đây thử hình dạng còn nghiêm
 * ngặt hơn: một vụ việc mở TRƯỚC KHI mẫu tồn tại. `ApplyChecklistTemplate` sao chép giá trị đúng
 * MỘT LẦN lúc mở vụ, không giữ khoá ngoại trỏ ngược lại mẫu — nên việc mẫu ra đời sau không có gì
 * để "chảy ngược" vào một vụ đã mở.
 */
it('does not change the checklist of a matter opened before the template existed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create(['code' => 'DN', 'name' => 'Doanh nghiệp']);
    $client = checklistTemplateTestClientVisibleTo($lawyer);

    $this->actingAs($lawyer, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm(checklistTemplateTestMatterFormData($client, $lawyer, $type, 'Vụ tư vấn doanh nghiệp trước khi có mẫu'))
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Vụ tư vấn doanh nghiệp trước khi có mẫu')->sole();

    // Đúng như finding checklist-02 ghi lại: loại chưa có mẫu thì vụ mở ra với 0 đầu mục.
    expect($matter->checklistItems()->count())->toBe(0);

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    checklistTemplatesTab($type)
        ->callTableAction('create', data: [
            'name' => 'Danh mục Doanh nghiệp',
            'is_active' => true,
            'items' => [
                ['name' => 'Giấy phép kinh doanh', 'description' => null, 'is_required' => true, 'sort_order' => 1],
            ],
        ])
        ->assertHasNoTableActionErrors();

    expect($matter->checklistItems()->count())->toBe(0);
});

/**
 * Cổng của trang sửa mẫu, cùng luật `MatterTypeResourceTest::"hides the matter type write pages
 * from a lawyer"` đã đo cho `StagesRelationManager`: `EditMatterType` (nơi relation manager này
 * sống) đòi `MatterTypePolicy::update()`, tức `settings.manage`. Task brief: "Luật sư không có
 * settings.manage không vào được trang sửa mẫu."
 */
it('keeps a lawyer without settings.manage out of the template edit page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->create();

    $this->actingAs($lawyer, 'web')
        ->get(MatterTypeResource::getUrl('edit', ['record' => $type], panel: 'admin'))
        ->assertNotFound();
});

/**
 * Tầng thứ hai, độc lập với trang: gọi thẳng relation manager (bỏ qua `authorizeAccess()` của
 * `EditMatterType`) và khẳng định nút "Tạo" bị ẩn. `ChecklistTemplatePolicy::create()` đã viết từ
 * trước task này nhưng CHƯA nối vào đâu — đây là bài kiểm tra đầu tiên cho việc nối dây đó thật
 * sự hoạt động, cùng thành ngữ `MatterTypeResourceTest::"hides the create stage action..."`.
 */
it('hides the create-template action from a lawyer even when addressing the relation manager directly', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->create();

    $this->actingAs($lawyer, 'web');

    checklistTemplatesTab($type)->assertTableActionHidden('create');
});
