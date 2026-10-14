<?php

namespace App\Filament\Admin\Widgets\IntakeReport;

use App\Enums\ChartKind;
use App\Enums\IntakeSource;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\IntakeReport\Concerns\ReadsIntakeReport;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Số liên hệ theo nguồn (M10 Task 6) — cột, một chuỗi, một màu `#4a73bd`, không chú giải (khuôn M9
 * Task 9: "cột một chuỗi dùng đúng một màu").
 *
 * Đếm {@see ReadsIntakeReport::contactsInReport()}: bản ghi nhận trong kỳ, người xem thấy được, trừ
 * bản trùng đã gộp — mỗi dòng là một người liên hệ. **Đủ sáu nguồn, kể cả nguồn bằng 0** (thứ tự của
 * enum `IntakeSource`): một nguồn biến khỏi trục khi không ai liên hệ qua đó là giấu đúng điều người
 * xem cần thấy. Bảng số có thêm dòng tổng.
 *
 * Khuôn M9: view dùng chung `chart-with-table` (bảng số, ô lọc và nhãn canvas của vendor), không
 * tooltip callback, không `RawJs` — `getOptions()` là mảng PHP thuần (phán quyết CSP của M8 R4).
 * `$isDiscovered = false`: không lên trang chủ; chỉ `IntakeReport::getWidgets()` liệt kê nó.
 */
class IntakesBySourceWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;
    use ReadsIntakeReport;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array<string, int>|null Tránh đếm hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc. */
    private ?array $countsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('intake_report.by_source.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('intake_report.by_source.description', ['range' => $this->reportFilters()->rangeLabel()]);
    }

    /** Một chuỗi số theo từng mục rời nhau: đủ ba dạng, cột là dạng gốc. */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line, ChartKind::Pie];
    }

    public function numberTableRows(): array
    {
        $counts = $this->counts();

        $rows = [];
        foreach (IntakeSource::cases() as $source) {
            $rows[] = ['label' => $source->label(), 'value' => (string) $counts[$source->value]];
        }

        $rows[] = ['label' => __('intake_report.by_source.total'), 'value' => (string) array_sum($counts)];

        return $rows;
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'labels' => array_map(fn (IntakeSource $source): string => $source->label(), IntakeSource::cases()),
            'datasets' => [
                [
                    'label' => __('intake_report.by_source.series'),
                    'data' => array_values($counts),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    /** @return array<string, int> Giá trị nguồn => số liên hệ, đủ sáu nguồn theo thứ tự enum. */
    private function counts(): array
    {
        return $this->countsCache ??= $this->computeCounts();
    }

    /** @return array<string, int> */
    private function computeCounts(): array
    {
        $found = $this->contactsInReport()
            ->toBase()
            ->groupBy('source')
            ->selectRaw('source, count(*) as total')
            ->pluck('total', 'source');

        $counts = [];
        foreach (IntakeSource::cases() as $source) {
            $counts[$source->value] = (int) ($found[$source->value] ?? 0);
        }

        return $counts;
    }
}
