<?php

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\MatterType;
use Database\Seeders\ChecklistTemplateSeeder;
use Database\Seeders\MatterTypeSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * Final review X10 (C-I5): hai seeder dữ liệu tham chiếu chạy lại trên máy chủ thật (CAI-DAT.md
 * bảo chạy `db:seed --force` sau mỗi lần cập nhật) không được ghi đè cấu hình quản trị viên đã
 * chỉnh. Trước bản sửa này cả hai dùng `updateOrCreate`: tên loại, "Đang dùng", mô tả giai đoạn,
 * đầu mục danh mục đã xoá, mô tả đầu mục đã sửa — tất cả quay về mặc định sau mỗi lần seed.
 *
 * Luật mới: CHỈ THÊM. Một loại vụ việc (kèm giai đoạn) được tạo khi mã của nó CHƯA tồn tại (kể cả
 * đã xoá mềm); một danh mục mẫu (kèm đầu mục) được tạo khi tên đó CHƯA tồn tại cho loại đó. Dòng
 * đã có không bao giờ bị chạm.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(MatterTypeSeeder::class);
    $this->seed(ChecklistTemplateSeeder::class);
});

it('creates every reference type, stage and default checklist on an empty database', function () {
    expect(MatterType::query()->count())->toBe(count(MatterTypeSeeder::types()))
        ->and(MatterType::query()->where('code', 'DD')->first()->stages()->count())->toBeGreaterThan(0)
        ->and(ChecklistTemplate::query()->count())->toBe(3)
        ->and(ChecklistTemplate::query()->where('name', 'Danh mục hồ sơ tranh chấp đất đai')->first()->items()->count())
        ->toBe(count(ChecklistTemplateSeeder::landDisputeItems()));
});

it('never restores what an admin edited, deactivated or deleted when the reference seeders run again', function () {
    $type = MatterType::query()->where('code', 'DD')->firstOrFail();
    $type->update(['name' => 'Đất đai (tên văn phòng tự đặt)', 'is_active' => false, 'sort_order' => 42]);

    $intake = $type->stage('intake');
    $intake->update(['label' => 'Nhận hồ sơ', 'client_description' => 'Mô tả văn phòng tự viết lại cho khách hàng đọc.']);

    $deletedStage = $type->stages()->where('key', '!=', 'intake')->orderByDesc('sort_order')->first();
    $deletedStage->delete();

    $template = ChecklistTemplate::query()->where('name', 'Danh mục hồ sơ tranh chấp đất đai')->firstOrFail();
    $template->update(['is_active' => false]);
    $firstItem = $template->items()->orderBy('sort_order')->first();
    $firstItem->update(['description' => 'Mô tả đã sửa', 'is_required' => false]);
    $lastItem = $template->items()->orderByDesc('sort_order')->first();
    $lastItem->delete();

    $removedType = MatterType::query()->where('code', 'LD')->firstOrFail();
    $removedType->delete();

    $this->seed(ReferenceDataSeeder::class);

    $type->refresh();
    expect($type->name)->toBe('Đất đai (tên văn phòng tự đặt)')
        ->and($type->is_active)->toBeFalse()
        ->and($type->sort_order)->toBe(42)
        ->and($type->stage('intake')->label)->toBe('Nhận hồ sơ')
        ->and($type->stage('intake')->client_description)->toBe('Mô tả văn phòng tự viết lại cho khách hàng đọc.')
        ->and($type->stages()->where('key', $deletedStage->key)->exists())->toBeFalse();

    $template->refresh();
    expect($template->is_active)->toBeFalse()
        ->and(ChecklistTemplate::withTrashed()->where('matter_type_id', $type->id)->count())->toBe(1)
        ->and($firstItem->fresh()->description)->toBe('Mô tả đã sửa')
        ->and($firstItem->fresh()->is_required)->toBeFalse()
        ->and(ChecklistTemplateItem::query()->where('checklist_template_id', $template->id)->where('name', $lastItem->name)->exists())->toBeFalse();

    expect(MatterType::query()->where('code', 'LD')->exists())->toBeFalse()
        ->and(MatterType::withTrashed()->where('code', 'LD')->count())->toBe(1);
});

it('adds a reference type that does not exist yet, with its stages, without touching the others', function () {
    MatterType::query()->where('code', 'HN')->firstOrFail()->forceDelete();
    MatterType::query()->where('code', 'DD')->firstOrFail()->update(['name' => 'Tên tự đặt']);

    $this->seed(MatterTypeSeeder::class);

    expect(MatterType::query()->where('code', 'HN')->first()?->stages()->count())->toBeGreaterThan(0)
        ->and(MatterType::query()->where('code', 'DD')->first()->name)->toBe('Tên tự đặt');
});

it('keeps RolesAndPermissionsSeeder re-runnable', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::query()->where('name', 'admin')->count())->toBe(1);
});
