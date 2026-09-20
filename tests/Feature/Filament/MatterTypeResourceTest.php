<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Filament\Admin\Resources\MatterTypes\Pages\CreateMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\StagesRelationManager;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an admin open every matter type page', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->create();

    $this->actingAs($admin, 'web')->get(MatterTypeResource::getUrl('index', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(MatterTypeResource::getUrl('create', panel: 'admin'))->assertOk();
    $this->actingAs($admin, 'web')->get(MatterTypeResource::getUrl('edit', ['record' => $type], panel: 'admin'))->assertOk();
});

/**
 * MatterTypePolicy để viewAny/view mở cho mọi vai trò (portal cần đọc nhãn giai đoạn), nhưng
 * create/update/delete chỉ dành cho settings.manage (admin). Filament áp policy tự động cho
 * từng trang resource; test này xác nhận luật sư — không có settings.manage — không mở được các
 * trang ghi trong khi trang danh sách (đọc) vẫn mở.
 */
it('hides the matter type write pages from a lawyer but still allows reading the list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->create();

    $this->actingAs($lawyer, 'web')->get(MatterTypeResource::getUrl('index', panel: 'admin'))->assertOk();
    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($lawyer, 'web')->get(MatterTypeResource::getUrl('create', panel: 'admin'))->assertNotFound();
    $this->actingAs($lawyer, 'web')->get(MatterTypeResource::getUrl('edit', ['record' => $type], panel: 'admin'))->assertNotFound();
});

/**
 * Không có policy riêng cho MatterTypeStage thì Filament (không bật chế độ nghiêm ngặt) mặc
 * định CHO PHÉP hành động trên model không có policy — nghĩa là chỉ trang EditMatterType chặn
 * được luật sư, còn bản thân hành động "tạo giai đoạn" thì không. MatterTypeStagePolicy (mirror
 * MatterTypePolicy) đóng lỗ hổng này ở đúng tầng của nó, độc lập với việc trang có mở hay không.
 */
it('hides the create stage action from a lawyer even when addressing the relation manager directly', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();

    $this->actingAs($lawyer, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])->assertTableActionHidden('create');
});

/**
 * M1 mang sang, khoản 1: `unique(matter_type_id, key)` ở DB tính cả dòng đã xoá mềm nên không
 * thể tạo lại `key` sau khi xoá một giai đoạn. Ràng buộc DB đã bỏ (migration
 * 2026_09_15_000001); tính duy nhất giờ được StagesRelationManager kiểm tra qua `scopedUnique`
 * chỉ trên các dòng còn sống của cùng loại vụ việc.
 */
it('rejects creating a stage whose key duplicates a live stage of the same type', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'intake',
            'label' => 'Trùng khoá',
            'client_label' => 'Trùng khoá',
            'sort_order' => 1,
            'default_next_update_days' => 14,
        ])
        ->assertHasTableActionErrors(['key']);

    expect($type->stages()->where('key', 'intake')->count())->toBe(1);
});

it('lets an admin recreate a stage key after the original row is soft-deleted', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();
    $type->stage('intake')->delete();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'intake',
            'label' => 'Tiếp nhận lại',
            'client_label' => 'Tiếp nhận lại',
            'sort_order' => 1,
            'default_next_update_days' => 14,
        ])
        ->assertHasNoTableActionErrors();

    expect($type->stages()->withTrashed()->where('key', 'intake')->count())->toBe(2)
        ->and($type->stages()->where('key', 'intake')->count())->toBe(1);
});

/**
 * Tầng form của cùng lỗ hổng đã vá ở model: `unique()` của Laravel đếm cả dòng đã xoá mềm, nên
 * trước đây người dùng nhận "đã tồn tại" cho một mã không còn dòng nào đang dùng — và nếu họ
 * bỏ qua form (seeder, factory) thì nhận thẳng một lỗi ràng buộc DB.
 */
it('lets an admin reuse a matter type code whose only holder was soft-deleted', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    MatterType::factory()->create(['code' => 'DS'])->delete();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateMatterType::class)
        ->fillForm(['code' => 'DS', 'name' => 'Dân sự', 'sort_order' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MatterType::query()->where('code', 'DS')->count())->toBe(1)
        ->and(MatterType::withTrashed()->where('code', 'DS')->count())->toBe(2);
});

/** Cặp âm: một mã CÒN DÙNG vẫn bị form từ chối, và từ chối trên đúng ô `code`. */
it('still refuses a matter type code that a live row is using', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    MatterType::factory()->create(['code' => 'DS']);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(CreateMatterType::class)
        ->fillForm(['code' => 'DS', 'name' => 'Dân sự lần hai', 'sort_order' => 0])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(MatterType::query()->where('code', 'DS')->count())->toBe(1);
});
