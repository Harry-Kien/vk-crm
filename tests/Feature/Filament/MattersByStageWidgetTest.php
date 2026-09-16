<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\MattersByStageWidget;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
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

    expect($data['labels'])->toBe([$stage->label])
        ->and($data['datasets'][0]['data'])->toBe([1]);
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
