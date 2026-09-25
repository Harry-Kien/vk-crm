<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Portal\Pages\SubmitDocument;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Activitylog\Models\Activity;

/**
 * Nút "Thêm đầu mục" trên tab "Danh mục hồ sơ" của một vụ việc, và `App\Actions\Document\
 * AddChecklistItem` đứng sau nó (M6.5 Task 15, SPEC §4.10/§7.4).
 *
 * Trước task này không có đường nào trong `app/` ghi một dòng MỚI vào `matter_checklist_items`
 * ngoài `ApplyChecklistTemplate` lúc mở vụ — xem docblock `AddChecklistItem` cho hậu quả đầy đủ
 * (finding `intake-02`/`checklist-02`/`roles-06`/`spec-gap-04`, critical). Test chính của tệp này
 * đi HẾT một vòng: trợ lý thêm đầu mục trên panel admin, rồi khách thấy và nộp được vào đó trên
 * portal — đúng tiêu chí task brief đòi, không dừng ở "Action ghi đúng bảng".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function addItemTab(Matter $matter)
{
    Filament::setCurrentPanel('admin');

    return test()->livewire(ChecklistRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Byte thật của một PDF tối thiểu — cùng nội dung `SubmitDocumentTest::submitPagePdf()` dùng. */
function addItemTestPdf(string $name = 'giay-uy-quyen.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

/**
 * SPEC §14 mục 4, đúng câu task brief viết: "Trợ lý trong đội thêm đầu mục 'Giấy uỷ quyền' cho
 * một vụ: khách thấy đầu mục trên cổng và nộp được vào đó." Test đi qua CẢ HAI panel, đúng đường
 * người dùng thật ở mỗi đầu — không gọi thẳng `AddChecklistItem` hay `SubmitClientDocument`.
 */
it('lets an assistant on the team add a checklist item, and the client sees it on the portal and can submit into it', function () {
    Storage::fake('private');
    // Mỗi test một tiền tố medialibrary riêng — `RefreshDatabase` đặt id media về 1 ở mỗi test,
    // và `Storage::fake()` dùng một thư mục CỐ ĐỊNH theo tên đĩa (không tự tách theo test), nên
    // hai tệp test chạy `--parallel` có thể ghi đè thư mục của nhau (cùng lý do
    // `SubmitDocumentTest` đặt tiền tố này).
    config(['media-library.prefix' => 'test-'.Str::random(16)]);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['name' => 'Khách hàng Task 15']);
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);
    $matter = Matter::factory()->for($client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $lawyer->id,
    ]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    addItemTab($matter)
        ->assertTableActionVisible('addItem')
        ->callTableAction('addItem', data: [
            'name' => 'Giấy uỷ quyền',
            'description' => 'Bản gốc, có chữ ký của khách và người được uỷ quyền.',
            'is_required' => true,
        ])
        ->assertHasNoTableActionErrors();

    $item = MatterChecklistItem::query()->where('matter_id', $matter->id)->where('name', 'Giấy uỷ quyền')->sole();

    expect($item->is_required)->toBeTrue()
        ->and($item->description)->toBe('Bản gốc, có chữ ký của khách và người được uỷ quyền.')
        ->and($item->status)->toBe(ChecklistItemStatus::Missing)
        ->and(Activity::query()->where('event', 'checklist_item_added')->count())->toBe(1);

    // --- Khách thấy đầu mục trên cổng ---
    Filament::setCurrentPanel('portal');

    $submitPage = $this->actingAs($clientUser, 'client')
        ->livewire(SubmitDocument::class, ['record' => $matter->getKey()]);

    expect($submitPage->instance()->choosableItems()->pluck('name')->all())->toContain('Giấy uỷ quyền');

    // --- ...và nộp được vào đó ---
    $submitPage
        ->call('chooseItem', $item->getKey())
        ->set('data.file', addItemTestPdf())
        ->call('submit')
        ->assertHasNoErrors();

    $document = Document::query()->where('matter_checklist_item_id', $item->getKey())->sole();

    expect($document->status)->toBe(DocumentStatus::Published)
        ->and($item->refresh()->status)->toBe(ChecklistItemStatus::PendingReview);
});

/**
 * Cổng THẬT — hai lớp, cố ý tách rời (cùng thành ngữ `TeamRelationManagerTest`): nút bị ẨN cho
 * người không có `matter.update` trên vụ này, VÀ một lần gọi ép buộc vẫn bị chặn (Action tự hỏi
 * lại `Gate`, không tin màn hình đã lọc). Nhân chứng được cấp quyền thẳng tay — chỉ `matter.view`,
 * không `matter.update` — vì mọi vai trò có `matter.view` trong bộ dữ liệu mẫu hôm nay đều có
 * sẵn `matter.update` (SPEC §5); cùng lý do `ChecklistRelationManagerTest::"gates the three
 * review buttons..."` phải dựng nhân chứng theo cách này.
 */
it('hides the add-item button from someone without matter.update, and blocks a forced call', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $matter->addTeamMember($viewerOnly, MatterRole::Observer);

    $this->actingAs($viewerOnly, 'web');

    $tab = addItemTab($matter);
    $tab->assertTableActionHidden('addItem');

    expect(fn () => $tab->callTableAction('addItem', data: [
        'name' => 'Giấy tờ ép buộc',
        'description' => null,
        'is_required' => false,
    ]))->toThrow(ExpectationFailedException::class);

    expect(MatterChecklistItem::query()->where('matter_id', $matter->id)->count())->toBe(0);
});

/**
 * Cùng luật `ApplyChecklistTemplate` đã dùng để không tạo trùng khi áp lại một mẫu ("item đã có,
 * theo tên, được giữ nguyên") — tên đầu mục là duy nhất trong PHẠM VI MỘT VỤ VIỆC, kể cả với đầu
 * mục đã bị gỡ (xoá mềm). Khác `ApplyChecklistTemplate` (một lượt sao chép hàng loạt, lặng lẽ bỏ
 * qua dòng trùng), `AddChecklistItem` được gọi TAY từng cái một nên một cái tên trùng phải bị TỪ
 * CHỐI, không lặng lẽ bỏ qua — nếu không, người bấm nút sẽ tưởng mình vừa thêm một đầu mục mới.
 */
it('refuses a duplicate checklist item name, including one that matches a soft-deleted item', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $live = MatterChecklistItem::factory()->for($matter)->create(['name' => 'Giấy khai sinh']);
    $trashed = MatterChecklistItem::factory()->for($matter)->create(['name' => 'Giấy chứng nhận kết hôn']);
    $trashed->delete();

    $this->actingAs($lawyer, 'web');

    // Một component MỚI cho mỗi lần gọi: một lần `callTableAction` bị từ chối để lại action đang
    // "mounted" trên chính component đó, và gọi lại CÙNG tên action trên một component còn đang
    // mounted nó bị Filament hiểu nhầm thành mở một action LỒNG bên trong action đang mở, không
    // phải một lượt bấm nút mới — đúng lỗi `ActionNotResolvableException` khi bản đầu của test
    // này dùng chung một `$tab` cho cả ba lượt gọi.
    addItemTab($matter)->callTableAction('addItem', data: [
        'name' => 'Giấy khai sinh',
        'description' => null,
        'is_required' => false,
    ])->assertHasTableActionErrors(['name']);

    addItemTab($matter)->callTableAction('addItem', data: [
        'name' => 'Giấy chứng nhận kết hôn',
        'description' => null,
        'is_required' => false,
    ])->assertHasTableActionErrors(['name']);

    // Cặp dương ngay cạnh: một cái TÊN KHÁC vẫn thêm được bình thường — lời từ chối ở trên là vì
    // trùng tên, không phải vì nút đã hỏng.
    addItemTab($matter)->callTableAction('addItem', data: [
        'name' => 'Giấy uỷ quyền',
        'description' => null,
        'is_required' => false,
    ])->assertHasNoTableActionErrors();

    expect(MatterChecklistItem::query()->withTrashed()->where('matter_id', $matter->id)->count())->toBe(3)
        ->and($live->fresh())->not->toBeNull()
        ->and(MatterChecklistItem::query()->where('matter_id', $matter->id)->where('name', 'Giấy khai sinh')->count())->toBe(1);
});

/**
 * `AddChecklistItem` nối đầu mục mới vào CUỐI danh mục hiện có — người thêm tay không cần biết
 * số thứ tự các dòng khác đang đứng ở đâu.
 */
it('appends a manually added item after the existing ones in sort order', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    MatterChecklistItem::factory()->for($matter)->create(['name' => 'Mục 1', 'sort_order' => 1]);
    MatterChecklistItem::factory()->for($matter)->create(['name' => 'Mục 5', 'sort_order' => 5]);

    $this->actingAs($lawyer, 'web');

    addItemTab($matter)
        ->callTableAction('addItem', data: [
            'name' => 'Mục thêm tay',
            'description' => null,
            'is_required' => false,
        ])
        ->assertHasNoTableActionErrors();

    $new = MatterChecklistItem::query()->where('matter_id', $matter->id)->where('name', 'Mục thêm tay')->sole();

    expect($new->sort_order)->toBe(6);
});
