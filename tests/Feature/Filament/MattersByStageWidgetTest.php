<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Gọi getData() (protected) qua reflection: đây là hàm build dữ liệu biểu đồ thực tế. */
function mattersByStageWidgetData(MattersByStageWidget $widget): array
{
    $method = new ReflectionMethod($widget, 'getData');
    $method->setAccessible(true);

    return $method->invoke($widget);
}

/** SPEC §7.1 mục 6: chỉ đếm vụ đang mở (chưa đóng), giới hạn theo listableBy() như mọi nơi khác. */
it('counts open matters by stage only within what the lawyer can list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $stranger = User::factory()->withRole(Role::Lawyer)->create();

    $type = MatterType::factory()->withStages()->create();
    $stage = $type->stages->reject(fn ($s) => $s->is_terminal)->first();

    Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $stage->key,
    ]);
    Matter::factory()->create([
        'lead_lawyer_id' => $stranger->id,
        'matter_type_id' => $type->id,
        'stage' => $stage->key,
    ]);

    $this->actingAs($lawyer, 'web');

    $data = mattersByStageWidgetData(new MattersByStageWidget);

    expect($data['labels'])->toBe([
        __('widgets.matters_by_stage.stage_label', ['type' => $type->name, 'stage' => $stage->label]),
    ])->and($data['datasets'][0]['data'])->toBe([1]);
});

it('excludes a closed matter from the open-matters-by-stage count', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();
    $stage = $type->stages->reject(fn ($s) => $s->is_terminal)->first();

    Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $stage->key,
        'closed_at' => now()->subDay(),
    ]);

    $this->actingAs($lawyer, 'web');

    $data = mattersByStageWidgetData(new MattersByStageWidget);

    expect($data['labels'])->toBe([]);
});

it('shows the widget to an accountant, who only needs matter.viewAny for counts', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(MattersByStageWidget::canView())->toBeTrue();
});

/**
 * Việc mang sang từ rà soát M3 vòng 2: biểu đồ gộp theo NHÃN giai đoạn, mà nhãn không duy nhất —
 * `matter_type_stages.key` chỉ duy nhất trong phạm vi một loại vụ việc, và không gì cấm hai loại
 * đặt cùng một nhãn cho hai giai đoạn khác nhau ("Chuẩn bị hồ sơ" là cái tên ai cũng sẽ gõ). Hai
 * loại như vậy bị cộng chung vào một cột, nên con số hiện ra không phải con số của giai đoạn nào
 * cả — và không có gì trên màn hình nói rằng nó là tổng của hai thứ.
 */
it('does not merge two matter types that happen to share a stage label', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $civil = MatterType::factory()->create(['code' => 'DS', 'name' => 'Dân sự']);
    $civil->stages()->create([
        'key' => 'prep', 'label' => 'Chuẩn bị hồ sơ', 'client_label' => 'Chuẩn bị hồ sơ',
        'sort_order' => 1, 'allowed_next' => [],
    ]);

    $labour = MatterType::factory()->create(['code' => 'LD', 'name' => 'Lao động']);
    $labour->stages()->create([
        'key' => 'prep', 'label' => 'Chuẩn bị hồ sơ', 'client_label' => 'Chuẩn bị hồ sơ',
        'sort_order' => 1, 'allowed_next' => [],
    ]);

    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'matter_type_id' => $civil->id, 'stage' => 'prep']);
    Matter::factory()->count(2)->create(['lead_lawyer_id' => $lawyer->id, 'matter_type_id' => $labour->id, 'stage' => 'prep']);

    $this->actingAs($lawyer, 'web');

    $data = mattersByStageWidgetData(new MattersByStageWidget);

    // Hai cột riêng, mỗi cột nói rõ nó thuộc loại vụ việc nào.
    expect($data['datasets'][0]['data'])->toBe([1, 2])
        ->and($data['labels'])->toBe([
            __('widgets.matters_by_stage.stage_label', ['type' => 'Dân sự', 'stage' => 'Chuẩn bị hồ sơ']),
            __('widgets.matters_by_stage.stage_label', ['type' => 'Lao động', 'stage' => 'Chuẩn bị hồ sơ']),
        ]);
});

/** Cặp dương: một giai đoạn chỉ có ở một loại vẫn là đúng một cột, không bị tách nhỏ. */
it('keeps one bar per stage of one matter type', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create(['name' => 'Dân sự']);
    $stage = $type->stages->reject(fn ($s) => $s->is_terminal)->first();

    Matter::factory()->count(3)->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $stage->key,
    ]);

    $this->actingAs($lawyer, 'web');

    $data = mattersByStageWidgetData(new MattersByStageWidget);

    expect($data['datasets'][0]['data'])->toBe([3])
        ->and($data['labels'])->toBe([
            __('widgets.matters_by_stage.stage_label', ['type' => 'Dân sự', 'stage' => $stage->label]),
        ]);
});

/**
 * M9 Task 1: mười hai lĩnh vực đều có giai đoạn "Tiếp nhận" — đúng tình huống "trùng nhãn ở quy mô
 * thật" mà kế hoạch mô tả. Mỗi loại một vụ ở `intake` phải ra mười hai cột riêng, mỗi cột kèm tên loại.
 */
it('draws twelve separate bars for one intake matter in each of the twelve seeded types', function () {
    $this->seed(ReferenceDataSeeder::class);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $types = MatterType::query()->orderBy('sort_order')->get();

    foreach ($types as $type) {
        Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'matter_type_id' => $type->id, 'stage' => 'intake']);
    }

    $this->actingAs($lawyer, 'web');

    $data = mattersByStageWidgetData(new MattersByStageWidget);

    expect($types)->toHaveCount(12)
        ->and($data['datasets'][0]['data'])->toBe(array_fill(0, 12, 1))
        ->and(array_unique($data['labels']))->toHaveCount(12)
        ->and($data['labels'])->toContain(__('widgets.matters_by_stage.stage_label', ['type' => 'Thuế và tài chính', 'stage' => 'Tiếp nhận']))
        ->and($data['labels'])->toContain(__('widgets.matters_by_stage.stage_label', ['type' => 'Đất đai và bất động sản', 'stage' => 'Tiếp nhận']));
});
