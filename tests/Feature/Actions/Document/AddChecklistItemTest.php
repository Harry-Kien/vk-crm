<?php

use App\Actions\Document\AddChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * `AddChecklistItem` ở tầng Action, gọi trực tiếp — khác với `tests/Feature/Filament/
 * AddChecklistItemTest.php` (đi qua Livewire, đo màn hình). Tệp này tồn tại vì cổng
 * `Gate::forUser($actor)->authorize(...)` bên TRONG `handle()` không thể tự đỏ qua một test màn
 * hình: nút "Thêm đầu mục" đã ẩn CÙNG một điều kiện đó trước khi Livewire kịp gọi tới Action (xem
 * docblock `ChecklistRelationManager::addItemAction()`), nên một test màn hình gọi ép buộc chỉ
 * chạm được `PHPUnit\Framework\ExpectationFailedException` của chính bộ máy test Filament, không
 * bao giờ chạm tới dòng `Gate::authorize()` bên trong Action. Đây là chỗ DUY NHẤT dòng đó tự đỏ
 * được khi bị xoá.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create();
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function addChecklistItem(Matter $matter, User $actor, string $name, ?string $description = null, bool $isRequired = false): MatterChecklistItem
{
    return app(AddChecklistItem::class)->handle($matter, $actor, $name, $description, $isRequired);
}

it('thêm đầu mục với đúng dữ liệu, nối vào cuối danh mục, và ghi audit', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['sort_order' => 3]);

    $item = addChecklistItem($this->matter, $this->lawyer, 'Giấy uỷ quyền', 'Bản gốc.', true);

    expect($item->matter_id)->toBe($this->matter->id)
        ->and($item->name)->toBe('Giấy uỷ quyền')
        ->and($item->description)->toBe('Bản gốc.')
        ->and($item->is_required)->toBeTrue()
        ->and($item->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->sort_order)->toBe(4)
        ->and(Activity::query()->where('event', 'checklist_item_added')->where('subject_id', $item->id)->count())->toBe(1);
});

/** Trợ lý CÓ `matter.update` (Role::Assistant->permissions()) nên thêm được — đúng task brief. */
it('cho trợ lý trong đội thêm đầu mục, vì đây là việc xin giấy tờ chứ không phải việc công bố', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $item = addChecklistItem($this->matter, $assistant, 'Giấy uỷ quyền');

    expect($item->exists)->toBeTrue();
});

/**
 * Cổng THẬT của Action, độc lập với màn hình — xem docblock đầu tệp. Nhân chứng được cấp quyền
 * thẳng tay (`matter.view`, không `matter.update`) vì mọi vai trò có `matter.view` trong bộ dữ
 * liệu mẫu hôm nay đều có sẵn `matter.update` (SPEC §5).
 */
it('từ chối một actor không có matter.update trên vụ việc này', function () {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(Permission::MatterView->value);
    $this->matter->addTeamMember($viewerOnly, MatterRole::Observer);

    expect(fn () => addChecklistItem($this->matter, $viewerOnly, 'Giấy tờ ép buộc'))
        ->toThrow(AuthorizationException::class);

    expect(MatterChecklistItem::query()->where('matter_id', $this->matter->id)->count())->toBe(0);
});

/**
 * Cùng luật `ApplyChecklistTemplate` dùng để không tạo trùng khi áp lại một mẫu: tên đầu mục là
 * duy nhất trong một vụ việc, kể cả với đầu mục đã bị gỡ (xoá mềm) — xem docblock lớp Action.
 */
it('từ chối tên đầu mục trùng với một đầu mục còn sống', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Giấy khai sinh']);

    expect(fn () => addChecklistItem($this->matter, $this->lawyer, 'Giấy khai sinh'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())
            ->toHaveKey('name'));

    expect(MatterChecklistItem::query()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

it('từ chối tên đầu mục trùng với một đầu mục đã bị gỡ (xoá mềm)', function () {
    $trashed = MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Giấy chứng nhận kết hôn']);
    $trashed->delete();

    expect(fn () => addChecklistItem($this->matter, $this->lawyer, 'Giấy chứng nhận kết hôn'))
        ->toThrow(ValidationException::class);

    expect(MatterChecklistItem::query()->withTrashed()->where('matter_id', $this->matter->id)->count())->toBe(1);
});

/** Cặp dương ngay cạnh: một cái tên KHÔNG trùng vẫn thêm được bình thường trên cùng vụ việc. */
it('vẫn thêm được một tên khác trên cùng vụ việc đã có đầu mục', function () {
    MatterChecklistItem::factory()->for($this->matter)->create(['name' => 'Giấy khai sinh']);

    $item = addChecklistItem($this->matter, $this->lawyer, 'Giấy uỷ quyền');

    expect($item->exists)->toBeTrue()
        ->and(MatterChecklistItem::query()->where('matter_id', $this->matter->id)->count())->toBe(2);
});

/**
 * Final review C-M7: tên đầu mục được cắt khoảng trắng hai đầu trước khi so trùng và lưu — nếu
 * không, "Giấy tờ A " và "Giấy tờ A" là hai đầu mục trông giống hệt nhau trên màn hình khách.
 */
it('trims the item name before checking for duplicates and saving', function () {
    $item = addChecklistItem($this->matter, $this->lawyer, '  Bản sao sổ hộ khẩu  ');

    expect($item->name)->toBe('Bản sao sổ hộ khẩu');

    expect(fn () => addChecklistItem($this->matter, $this->lawyer, 'Bản sao sổ hộ khẩu   '))
        ->toThrow(ValidationException::class);

    expect(MatterChecklistItem::query()->where('matter_id', $this->matter->id)->where('name', 'like', '%sổ hộ khẩu%')->count())->toBe(1);
});

it('refuses a name made only of whitespace', function () {
    expect(fn () => addChecklistItem($this->matter, $this->lawyer, "   \u{00A0} "))
        ->toThrow(ValidationException::class);
});
