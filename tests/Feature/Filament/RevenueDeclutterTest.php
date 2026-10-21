<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Widgets\Revenue\ClosedWithBalanceWidget;
use App\Filament\Admin\Widgets\Revenue\LoadPerLawyerWidget;
use App\Filament\Admin\Widgets\Revenue\MatterMixByPracticeAreaWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByPersonWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\RevenueColours;
use App\Support\Charts\MoneyChart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Trang Doanh thu bớt chữ (làn rv, 2026-10-15 — "nhiều chữ nhìn rối quá"): tiêu đề ngắn, MỘT dòng phụ,
 * cách tính sau nút ⓘ; trục tiền dạng "60 tr", tooltip đủ "60.000.000 ₫"; bộ lọc một hàng; trang hết bề
 * ngang. Màn hình đọc qua Livewire/HTTP.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

/** Sáu biểu đồ của trang (bảng "Hồ sơ đã đóng còn nợ" riêng bên dưới) — "Doanh thu theo người" từ 2026-10-21. */
function rvChartWidgets(): array
{
    return [
        ReceivablesDonutWidget::class,
        RevenueOverTimeWidget::class,
        RevenueByPersonWidget::class,
        RevenueByStageWidget::class,
        MatterMixByPracticeAreaWidget::class,
        LoadPerLawyerWidget::class,
    ];
}

/** Có dữ liệu để biểu đồ không rơi vào trạng thái rỗng (khung canvas và bảng số chỉ vẽ khi có số). */
function rvSeedOneContract(User $lawyer): void
{
    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]))->active()
        ->create(['total_amount' => 60_000_000, 'signed_at' => '2026-10-02']);
    Instalment::factory()->for($contract)->create(['amount' => 60_000_000, 'due_date' => '2026-12-31']);
}

it('gives every chart a short heading and a single short sub-line, never the old paragraph', function (string $widget) {
    $this->actingAs($this->admin, 'web');

    $instance = Livewire::test($widget, ['pageFilters' => []])->instance();
    $heading = strip_tags((string) $instance->getHeading());
    $description = (string) $instance->getDescription();

    expect(count(preg_split('/\s+/u', trim($heading))))->toBeLessThanOrEqual(5)
        ->and(mb_strlen($description))->toBeLessThanOrEqual(50)
        ->and($description)->not->toContain('. ')
        ->and(mb_strlen($instance->getExplanation()))->toBeGreaterThan(mb_strlen($description) * 2);
})->with(rvChartWidgets());

it('keeps the full explanation of every chart behind the info button next to its heading, and not under the heading', function (string $widget) {
    rvSeedOneContract($this->admin);
    $this->actingAs($this->admin, 'web');

    $component = Livewire::test($widget, ['pageFilters' => []]);
    $explanation = $component->instance()->getExplanation();
    $html = $component->html();

    // Nút ⓘ nằm TRONG tiêu đề (h2 của khung), khung chữ của nó mang đúng lời giải thích.
    $header = substr($html, strpos($html, 'fi-section-header-heading'));
    $header = substr($header, 0, strpos($header, '</h2>'));

    expect($header)->toContain('data-info-tip="')
        ->toContain(e($explanation));

    // Dòng phụ dưới tiêu đề chỉ là dòng ngắn, không phải lời giải thích.
    preg_match('/<p class="fi-section-header-description">\s*(.*?)\s*<\/p>/s', $html, $sub);

    expect(trim(html_entity_decode($sub[1] ?? '')))->toBe((string) $component->instance()->getDescription());
})->with(rvChartWidgets());

it('moves the explanation of the closed-with-balance table behind an info button too', function () {
    $this->actingAs($this->admin, 'web');

    $component = Livewire::test(ClosedWithBalanceWidget::class, ['pageFilters' => []]);
    $explanation = $component->instance()->getExplanation();

    expect((string) $component->instance()->getTable()->getDescription())->toBe(__('widgets.revenue_dashboard.closed_with_balance.description'))
        ->and($component->html())->toContain('data-info-tip="')->toContain(e($explanation))
        ->and(__('widgets.revenue_dashboard.closed_with_balance.heading'))->toBe('Hồ sơ đã đóng còn nợ');
});

it('names the over-time chart after how it is split — by month, by quarter, by year', function () {
    $this->actingAs($this->admin, 'web');

    $component = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => []]);

    expect((string) $component->instance()->getHeading())->toBe('Doanh thu theo tháng');

    $component->set('filter', 'quarter');
    expect((string) $component->instance()->getHeading())->toBe('Doanh thu theo quý');

    $component->set('filter', 'year');
    expect((string) $component->instance()->getHeading())->toBe('Doanh thu theo năm');
});

it('marks every money chart for the vietnamese money axis, whatever form the viewer picked, and leaves the count charts alone', function () {
    $this->actingAs($this->admin, 'web');

    foreach ([ReceivablesDonutWidget::class, RevenueOverTimeWidget::class, RevenueByStageWidget::class, MatterMixByPracticeAreaWidget::class] as $widget) {
        $component = Livewire::test($widget, ['pageFilters' => []]);

        foreach (array_keys($component->instance()->chartKindOptions()) as $kind) {
            $component->set('chartKind', $kind);

            expect($component->instance()->chartOptionsForKind()['plugins'][MoneyChart::PLUGIN] ?? null)
                ->toBe(['enabled' => true], "{$widget} ở dạng {$kind}");
        }
    }

    // Đếm số vụ: trục là số trần.
    expect(Livewire::test(MatterMixByPracticeAreaWidget::class, ['pageFilters' => ['by_count' => true]])->instance()->chartOptionsForKind()['plugins'])
        ->not->toHaveKey(MoneyChart::PLUGIN)
        ->and(Livewire::test(LoadPerLawyerWidget::class, ['pageFilters' => []])->instance()->chartOptionsForKind()['plugins'])
        ->not->toHaveKey(MoneyChart::PLUGIN);
});

it('loads the money formatter script from the app itself in the head of every staff page, before the charts', function () {
    $response = $this->actingAs($this->admin, 'web')->get(RevenueDashboard::getUrl(panel: 'admin'))->assertOk();

    $html = $response->getContent();
    $head = substr($html, 0, strpos($html, '</head>'));

    expect($head)->toContain('<script src="'.MoneyChart::scriptUrl().'"></script>')
        ->and(MoneyChart::scriptUrl())->toMatch('#/charts/money\.js\?v=[0-9a-f]{12}$#');

    $script = file_get_contents(public_path(MoneyChart::SCRIPT));

    expect($script)->toContain("id: 'vkMoney'")
        ->toContain('window.filamentChartJsPlugins')
        ->toContain("'nghìn'")->toContain("'tr'")->toContain("'tỷ'")
        ->toContain("' ₫'");
});

it('labels the donut slices with names only and paints them with the page colours', function () {
    rvSeedOneContract($this->admin);
    $this->actingAs($this->admin, 'web');

    $instance = Livewire::test(ReceivablesDonutWidget::class, ['pageFilters' => []])->instance();
    $data = (new ReflectionMethod($instance, 'getData'))->invoke($instance);

    expect($data['labels'])->toBe(['Đã thu', 'Còn phải thu, chưa tới hạn', 'Quá hạn'])
        ->and($data['datasets'][0]['backgroundColor'])->toBe([RevenueColours::COLLECTED, RevenueColours::OUTSTANDING, RevenueColours::OVERDUE]);
});

it('draws the chart form and the split selectors small in the corner of the heading', function () {
    rvSeedOneContract($this->admin);
    $this->actingAs($this->admin, 'web');

    $html = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => []])->html();
    $after = substr($html, strpos($html, 'fi-section-header-after-ctn'));

    expect(substr_count($after, 'font-size: 0.8125rem; padding-block: 0.25rem;'))->toBe(2)
        ->and($after)->toContain('wire:model.live="chartKind"')
        ->toContain('wire:model.live="filter"');
});

// 2026-10-21: năm ô trên một hàng — thêm "Nhân viên kinh doanh" cạnh "Luật sư phụ trách".
it('lays the page out full width with the five filters on one row and the count switch explained behind an info button', function () {
    $this->actingAs($this->admin, 'web');

    $page = Livewire::test(RevenueDashboard::class);

    expect($page->instance()->getMaxContentWidth())->toBe(Width::Full)
        ->and($page->instance()->getFiltersForm()->getColumns('lg'))->toBe(5)
        ->and($page->instance()->getFiltersForm()->getColumns('xl'))->toBe(5)
        ->and($page->instance()->getFiltersForm()->getColumns('2xl'))->toBe(5)
        ->and($page->instance()->getFiltersForm()->getColumns('default'))->toBe(1);

    $html = $page->html();
    $help = e(__('widgets.revenue_dashboard.filters.by_count_help'));

    // Đoạn giải thích của công tắc nằm trong khung ⓘ, không còn là dòng chữ dưới công tắc.
    expect($html)->toContain($help)
        ->and(substr($html, 0, strpos($html, $help)))->toContain('data-info-tip="')
        ->and(substr_count($html, $help))->toBe(1);

    // Bốn ô chọn và công tắc đứng trước hai ô ngày của "Tuỳ chọn" (hàng đầu không xô lệch).
    $fields = array_map(fn ($component): string => $component->getName(), $page->instance()->getFiltersForm()->getFlatFields(withHidden: true));

    expect(array_values($fields))->toBe(['period', 'lawyer_id', 'sales_id', 'practice_area_id', 'by_count', 'date_from', 'date_to']);
});

/**
 * Chart.js chỉ đọc tuỳ chọn (cờ trục tiền của MoneyChart) lúc dựng biểu đồ; đổi bộ lọc trang chỉ đẩy dữ liệu
 * mới vào biểu đồ cũ. Bật "Đếm theo số vụ" mà khung không dựng lại thì trục và tooltip số vụ vẫn mang "₫".
 * Khoá của khung (`wire:key`) đổi theo thứ đang đo, nên Alpine dựng lại từ đầu, như khi đổi dạng biểu đồ.
 */
it('redraws the practice-area mix from scratch when the count switch flips, so a count never keeps the money axis', function () {
    rvSeedOneContract($this->admin);
    $this->actingAs($this->admin, 'web');

    $keyOf = function (array $pageFilters): string {
        $component = Livewire::test(MatterMixByPracticeAreaWidget::class, ['pageFilters' => $pageFilters]);
        $key = $component->instance()->chartFrameKey();

        expect($component->html())->toContain('wire:key="'.$key.'"');

        return substr($key, strlen($component->instance()->getId()));
    };

    expect($keyOf([]))->not->toBe($keyOf(['by_count' => true]));
});
