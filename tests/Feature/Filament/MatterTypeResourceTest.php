<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\MatterTypes\MatterTypeResource;
use App\Filament\Admin\Resources\MatterTypes\Pages\CreateMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\EditMatterType;
use App\Filament\Admin\Resources\MatterTypes\Pages\ListMatterTypes;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\ChecklistTemplatesRelationManager;
use App\Filament\Admin\Resources\MatterTypes\RelationManagers\StagesRelationManager;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications as NotificationsLivewireComponent;

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

// =========================================================================================
// Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy")
// =========================================================================================

/**
 * Đo qua đúng `DeleteAction` mà admin bấm thật: `authorizationNotification()` giữ nút hiển thị
 * (`MatterTypeStagePolicy::delete()` từ chối kèm `Response::deny()`) và đổi lần bấm bị từ chối
 * thành một thông báo nêu đúng số hồ sơ, thay vì xoá trót lọt hoặc một nút chết không lời giải
 * thích (cùng kỹ thuật `EditClient`/`ClientResourceTest`).
 */
it('refuses to delete a stage a matter is currently standing at, and tells the admin how many', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();
    $client = Client::factory()->create();
    Matter::factory()->for($client)->for($type)->atStage('intake')->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->assertTableActionVisible('delete', record: $type->stage('intake'))
        ->callTableAction('delete', record: $type->stage('intake'))
        ->assertNotified(__('matter_types.stages.delete_blocked_in_use', ['count' => 1]));

    expect($type->stage('intake')->fresh()->trashed())->toBeFalse();
});

/** Vế dương: một giai đoạn KHÔNG hồ sơ nào đứng ở đó, và không giai đoạn nào khác trỏ tới, vẫn xoá được bình thường. */
/**
 * Không dùng `withStages()` (StagePresets::civil()): bộ đó không có giai đoạn nào KHÔNG bị một
 * giai đoạn khác trỏ tới trong `allowed_next` (xem ghi chú ở
 * `MatterTypeStagePolicyTest::threeStagesForDeleteGuardTests()`), nên không dựng được một giai
 * đoạn "không ai dùng" thật sự bằng bộ đó. Một giai đoạn đơn, `allowed_next` rỗng, thì có.
 */
it('lets the admin delete a stage that nothing uses', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->create();
    $unused = $type->stages()->create(['key' => 'c', 'label' => 'C', 'client_label' => 'C', 'sort_order' => 1, 'allowed_next' => []]);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])->callTableAction('delete', record: $unused);

    expect($unused->fresh()->trashed())->toBeTrue();
});

/**
 * `Notification::assertNotified(string $title)` so KHỚP TIÊU ĐỀ (không phải nội dung) —
 * `DeleteBulkAction` đặt tiêu đề là một câu đếm số dòng đã xoá ("1 xoá, 1 không xoá được"), còn lý
 * do (`Response::deny()` message) nằm ở PHẦN THÂN. Đọc thẳng danh sách thông báo đã gửi (cùng lớp
 * `Filament\Notifications\Livewire\Notifications` mà `assertNotified()` dùng bên trong) để so
 * đúng phần thân — khác với `DeleteAction` đơn lẻ (`authorizationNotification()`), nơi lý do đi
 * thẳng vào tiêu đề và `assertNotified($message)` so khớp được ngay.
 */
function assertBulkFailureNotificationBodyContains(string $expected): void
{
    $component = new NotificationsLivewireComponent;
    $component->mount();

    $bodies = $component->notifications->map(fn ($notification) => (string) $notification->getBody());

    expect($bodies->filter(fn (string $body): bool => str_contains($body, $expected)))->not->toBeEmpty();
}

/**
 * Lớp phòng thủ THỨ HAI, độc lập với việc ẩn nút (C1-class bulk-action hole, controller decision):
 * `authorizeIndividualRecords('delete')` bắt MỖI bản ghi đã chọn đi qua đúng
 * `MatterTypeStagePolicy::delete()` trước khi bị xoá. Không có nó, `deleteAny()` (cổng thô) chỉ
 * quyết định nút có bấm được không, và Filament xoá MỌI dòng đã chọn mà không hỏi lại `delete()`
 * cho từng dòng.
 */
it('bulk-deletes only the stage nothing uses, and shows the reason for the one still in use', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->create();
    $inUse = $type->stages()->create(['key' => 'a', 'label' => 'A', 'client_label' => 'A', 'sort_order' => 1, 'allowed_next' => []]);
    $unused = $type->stages()->create(['key' => 'b', 'label' => 'B', 'client_label' => 'B', 'sort_order' => 2, 'allowed_next' => []]);
    $client = Client::factory()->create();
    Matter::factory()->for($client)->for($type)->atStage('a')->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->callTableBulkAction('delete', [$inUse, $unused]);

    assertBulkFailureNotificationBodyContains(__('matter_types.stages.delete_blocked_in_use', ['count' => 1]));

    expect($inUse->fresh()->trashed())->toBeFalse()
        ->and($unused->fresh()->trashed())->toBeTrue();
});

/** Cùng lỗ hổng, cho `MatterTypesTable` (controller decision: "Do the same for matter types"). */
it('bulk-deletes only the matter type nothing uses, and shows the reason for the one still in use', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $inUse = MatterType::factory()->withStages()->create();
    Matter::factory()->for($client)->for($inUse)->create();
    $unused = MatterType::factory()->withStages()->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(ListMatterTypes::class)
        ->callTableBulkAction('delete', [$inUse, $unused]);

    assertBulkFailureNotificationBodyContains(__('matter_types.delete_blocked_in_use', ['count' => 1]));

    expect($inUse->fresh()->trashed())->toBeFalse()
        ->and($unused->fresh()->trashed())->toBeTrue();
});

/**
 * "Đổi key đang dùng: bị từ chối" (task brief). `TransitionMatterStage`/`MatterType::stage()`
 * đọc `matters.stage`/`stage_logs.from_stage`/`to_stage` là các CHUỖI `key` — đổi `key` của một
 * giai đoạn mà một trong hai bảng đó đang dùng làm hồ sơ ĐÓNG BĂNG (không còn cấu hình để tra),
 * y hệt hậu quả của việc xoá mềm mà test phía trên đã đóng. Chặn ở đúng ô `key` (không phải một
 * notification trôi nổi), qua Livewire thật — "màn hình phải từ chối trên request sửa, không chỉ
 * ẩn nút" (controller decision).
 */
it('refuses to change the key of a stage a matter is currently standing at', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();
    $client = Client::factory()->create();
    Matter::factory()->for($client)->for($type)->atStage('intake')->create();

    $stage = $type->stage('intake');

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->mountTableAction('edit', $stage)
        ->setTableActionData([
            'key' => 'tiep_nhan',
            'label' => $stage->label,
            'client_label' => $stage->client_label,
            'sort_order' => $stage->sort_order,
            'default_next_update_days' => $stage->default_next_update_days,
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['key']);

    expect($stage->fresh()->key)->toBe('intake');
});

/** Cùng luật, qua dòng tiến độ (`stage_logs`) thay vì `matters.stage` trực tiếp. */
it('refuses to change the key of a stage a stage log points at', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->for($type)->atStage('collecting_documents')->create();

    StageLog::factory()->for($matter)->transition('intake', 'collecting_documents')->create();

    $stage = $type->stage('intake');

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->mountTableAction('edit', $stage)
        ->setTableActionData([
            'key' => 'tiep_nhan',
            'label' => $stage->label,
            'client_label' => $stage->client_label,
            'sort_order' => $stage->sort_order,
            'default_next_update_days' => $stage->default_next_update_days,
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['key']);

    expect($stage->fresh()->key)->toBe('intake');
});

/** Vế dương: đổi `key` của một giai đoạn KHÔNG ai dùng vẫn bình thường. */
/**
 * KHÔNG dùng `withStages()` (StagePresets::civil()) ở đây: bộ đó không có giai đoạn nào KHÔNG bị
 * một giai đoạn khác trỏ tới trong `allowed_next` (kể cả `'on_hold'`, bị `intake` VÀ
 * `collecting_documents` cùng trỏ tới) — xem ghi chú ở
 * `MatterTypeTest::"lets a stage change its key through the bare Eloquent relation..."`. Bản test
 * trước fix round 1 dùng `'on_hold'` và xanh sai lý do: `keyInUse()` khi đó chưa kiểm
 * `allowed_next`.
 */
it('lets the admin change the key of a stage nothing uses', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->create();
    $stage = $type->stages()->create([
        'key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1,
        'allowed_next' => [], 'default_next_update_days' => 14,
    ]);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->mountTableAction('edit', $stage)
        ->setTableActionData([
            'key' => 'tam_dung',
            'label' => $stage->label,
            'client_label' => $stage->client_label,
            'sort_order' => $stage->sort_order,
            'default_next_update_days' => $stage->default_next_update_days,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($stage->fresh()->key)->toBe('tam_dung');
});

/**
 * Task 19, vòng sửa 1 (Critical): đổi `key` của một giai đoạn còn nằm trong `allowed_next` của
 * một giai đoạn KHÁC bị chặn NGAY TRÊN màn hình sửa, kèm một câu tiếng Việt NÊU TÊN giai đoạn
 * đang trỏ tới — không phải câu chung chung "đang có hồ sơ hoặc dòng tiến độ dùng". Trước bản vá
 * này đường này lọt qua trót lọt (xem `MatterTypeTest` cho phép đo qua Eloquent trần).
 */
it('refuses to change the key of a stage still listed in another stage\'s allowed_next', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->create();
    $a = $type->stages()->create([
        'key' => 'a', 'label' => 'Giai đoạn A', 'client_label' => 'A', 'sort_order' => 1,
        'allowed_next' => ['b'], 'default_next_update_days' => 14,
    ]);
    $b = $type->stages()->create([
        'key' => 'b', 'label' => 'Giai đoạn B', 'client_label' => 'B', 'sort_order' => 2,
        'allowed_next' => [], 'default_next_update_days' => 14,
    ]);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $component = $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->mountTableAction('edit', $b)
        ->setTableActionData([
            'key' => 'c',
            'label' => $b->label,
            'client_label' => $b->client_label,
            'sort_order' => $b->sort_order,
            'default_next_update_days' => $b->default_next_update_days,
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['key']);

    // `assertHasTableActionErrors(['key' => $message])` không dùng được ở đây: Livewire cắt
    // chuỗi tại dấu ':' đầu tiên rồi coi phần trước là TÊN LUẬT (đúng cú pháp "min:3" của Laravel)
    // — thông điệp thật của mình có dấu ':' ("Không đổi được định danh: ..."), nên nó bị cắt cụt
    // và so sai. Đọc thẳng error bag ở đúng state path mà thông báo lỗi phía trên xác nhận
    // (`mountedActions.0.data.<field>`) để so nguyên văn.
    expect($component->errors()->first('mountedActions.0.data.key'))
        ->toBe(__('matter_types.stage_fields.key_locked_allowed_next', ['labels' => $a->label]));

    expect($b->fresh()->key)->toBe('b');
});

/** Cặp dương đã có ở trên (create), nhưng cần khẳng định riêng: LƯU LẠI không đổi `key` không trip guard dù hồ sơ đang dùng nó. */
it('lets the admin save a stage a matter is standing at, as long as the key itself does not change', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();
    $client = Client::factory()->create();
    Matter::factory()->for($client)->for($type)->atStage('intake')->create();

    $stage = $type->stage('intake');

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->mountTableAction('edit', $stage)
        ->setTableActionData([
            'key' => 'intake',
            'label' => 'Tiếp nhận (đổi nhãn)',
            'client_label' => $stage->client_label,
            'sort_order' => $stage->sort_order,
            'default_next_update_days' => $stage->default_next_update_days,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($stage->fresh()->label)->toBe('Tiếp nhận (đổi nhãn)');
});

/**
 * Cột DB là `unsignedInteger` (migration `2026_09_14_000004`); một giá trị âm qua thẳng form là
 * lỗi 500 trên MariaDB strict mà SQLite của bộ test không thấy (intake/intake-08).
 */
it('rejects a negative sort order when creating a stage', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create();

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(StagesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'negative_sort',
            'label' => 'Thứ tự âm',
            'client_label' => 'Thứ tự âm',
            'sort_order' => -1,
            'default_next_update_days' => 14,
        ])
        ->assertHasTableActionErrors(['sort_order']);

    expect($type->stages()->where('key', 'negative_sort')->exists())->toBeFalse();
});

/** Cùng luật, cho đầu mục danh mục hồ sơ mẫu (`ChecklistTemplatesRelationManager`, cột giống hệt). */
it('rejects a negative sort order on a checklist template item', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $type = MatterType::factory()->withStages()->create(['code' => 'HS', 'name' => 'Hình sự']);

    $this->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    $this->livewire(ChecklistTemplatesRelationManager::class, [
        'ownerRecord' => $type,
        'pageClass' => EditMatterType::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Danh mục thứ tự âm',
            'is_active' => true,
            'items' => [
                ['name' => 'Đầu mục thứ tự âm', 'description' => null, 'is_required' => false, 'sort_order' => -1],
            ],
        ])
        ->assertHasTableActionErrors(['items.0.sort_order']);

    expect(ChecklistTemplate::query()->where('name', 'Danh mục thứ tự âm')->exists())->toBeFalse();
});
