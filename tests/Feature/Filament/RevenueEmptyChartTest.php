<?php

use App\Enums\ChartKind;
use App\Enums\Role;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByPersonWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\ChartPreference;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Charts\MoneyChart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Ảnh chủ văn phòng gửi 2026-10-21 (trang Doanh thu lọc "Quý này / Đỗ Quốc Bảo / Đất đai và bất động sản"):
 *  1. mọi số bằng 0 mà biểu đồ vẫn vẽ trục rỗng, nhãn trục lặp "0, 0, 0, 1, 1, 1" — giờ trong khung là một
 *     câu rõ ràng, không trục, không nút "Xem bảng số"; và trục giá trị không bao giờ có vạch lẻ;
 *  2. "Tỉ lệ thu tiền" ở dạng "Đường" vẽ một đường phẳng vô nghĩa — ba lát là các phần của một tổng, nên
 *     widget này chỉ mời "Tròn" và "Cột".
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-21 10:00:00');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin, 'web');
});

/** Sáu biểu đồ của trang, mỗi biểu đồ với câu rỗng của nó. */
dataset('biểu đồ trang doanh thu', [
    'tỉ lệ thu tiền' => [ReceivablesDonutWidget::class, 'widgets.revenue_dashboard.empty.money'],
    'doanh thu theo thời gian' => [RevenueOverTimeWidget::class, 'widgets.revenue_dashboard.empty.money'],
    'doanh thu theo người' => [RevenueByPersonWidget::class, 'widgets.revenue_dashboard.empty.money'],
    'tiền thu theo giai đoạn' => [RevenueByStageWidget::class, 'widgets.revenue_dashboard.empty.money'],
    'cơ cấu theo lĩnh vực' => [MatterMixByPracticeAreaWidget::class, 'widgets.revenue_dashboard.empty.money'],
    'tải theo luật sư' => [LoadPerLawyerWidget::class, 'widgets.revenue_dashboard.empty.load'],
]);

function recSeedMoney(User $lawyer): void
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 60_000_000, 'signed_at' => '2026-10-02']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 60_000_000, 'due_date' => '2026-12-31']);
    Payment::factory()->for($instalment)->create(['amount' => 20_000_000, 'paid_on' => '2026-10-05', 'attributed_lawyer_id' => $lawyer->id]);
}

it('says in the frame that there is nothing to show when every value is zero, instead of drawing an empty axis', function (string $widget, string $sentence) {
    $component = Livewire::test($widget, ['pageFilters' => []]);
    $html = $component->html();

    expect($component->instance()->isEmpty())->toBeTrue()
        ->and($html)->toContain(e(__($sentence)))
        ->not->toContain(e(__('widgets.revenue_dashboard.number_table_toggle')));
})->with('biểu đồ trang doanh thu');

it('draws the chart and offers the number table as soon as one value is not zero', function (string $widget, string $sentence) {
    recSeedMoney($this->admin);

    $component = Livewire::test($widget, ['pageFilters' => []]);

    expect($component->instance()->isEmpty())->toBeFalse()
        ->and($component->html())->toContain(e(__('widgets.revenue_dashboard.number_table_toggle')))
        ->not->toContain(e(__($sentence)));
})->with('biểu đồ trang doanh thu');

it('says "no contract" rather than "no money" when the practice-area mix counts matters', function () {
    $html = Livewire::test(MatterMixByPracticeAreaWidget::class, ['pageFilters' => ['by_count' => true]])->html();

    expect($html)->toContain(e(__('widgets.revenue_dashboard.empty.count')));
});

it('starts every value axis at zero with whole steps, in every form the viewer may pick, so no label repeats', function (string $widget) {
    recSeedMoney($this->admin);

    $component = Livewire::test($widget, ['pageFilters' => []]);

    foreach (array_keys($component->instance()->chartKindOptions()) as $kind) {
        $component->set('chartKind', $kind);
        $options = $component->instance()->chartOptionsForKind();

        if ($kind === ChartKind::Pie->value) {
            expect($options)->not->toHaveKey('scales');

            continue;
        }

        $axis = ($options['indexAxis'] ?? 'x') === 'y' ? 'x' : 'y';

        expect($options['scales'][$axis]['beginAtZero'] ?? null)->toBeTrue("{$widget} ở dạng {$kind}")
            ->and($options['scales'][$axis]['ticks']['precision'] ?? null)->toBe(0, "{$widget} ở dạng {$kind}");
    }
})->with([
    ReceivablesDonutWidget::class,
    RevenueOverTimeWidget::class,
    RevenueByPersonWidget::class,
    RevenueByStageWidget::class,
    MatterMixByPracticeAreaWidget::class,
    LoadPerLawyerWidget::class,
]);

it('drops a money axis label that would repeat the one before it, in the formatter script', function () {
    $script = file_get_contents(public_path(MoneyChart::SCRIPT));

    expect($script)->toContain('function (tick, index, ticks)')
        ->toContain('compact(ticks[index - 1].value) === label');
});

it('offers only pie and bar on the collection-rate chart, and turns a remembered line back into the pie', function () {
    ChartPreference::query()->forceCreate([
        'user_id' => $this->admin->id,
        'widget' => class_basename(ReceivablesDonutWidget::class),
        'chart_kind' => 'line',
    ]);

    $component = Livewire::test(ReceivablesDonutWidget::class, ['pageFilters' => []]);

    expect($component->instance()->chartKindOptions())->toBe([
        'pie' => ChartKind::Pie->label(),
        'bar' => ChartKind::Bar->label(),
    ])
        ->and($component->get('chartKind'))->toBe('pie')
        ->and($component->instance()->activeChartKind())->toBe(ChartKind::Pie);
});
