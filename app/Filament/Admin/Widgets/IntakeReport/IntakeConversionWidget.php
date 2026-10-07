<?php

namespace App\Filament\Admin\Widgets\IntakeReport;

use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Filament\Admin\Widgets\IntakeReport\Concerns\ReadsIntakeReport;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Tỉ lệ chuyển thành vụ việc (M10 Task 6) — cột theo nguồn, một chuỗi, một màu `#4a73bd`, trục 0–100 %.
 *
 * **Tỉ lệ = số bản ghi `won` / số liên hệ**, cả hai đếm trên {@see ReadsIntakeReport::contactsInReport()}
 * (nhận trong kỳ, thấy được, trừ bản trùng đã gộp). `won` chỉ do `ConvertIntakeToMatter` đặt (R3), nên
 * đó là định nghĩa duy nhất của "đã thành vụ việc". **Bản ghi còn đang xử lý vẫn ở mẫu số** — mô tả
 * widget nói rõ: một kỳ vừa qua trông thấp hơn khi việc chưa ngã ngũ; không có mẫu số thứ hai "chỉ
 * tính việc đã xong" để hai con số không bị đọc lẫn.
 *
 * Nguồn không có liên hệ nào trong kỳ: cột để trống (`null`, Chart.js không vẽ) và bảng số ghi "Chưa
 * có liên hệ" — không vẽ một cột 0 %, vì 0 % nghĩa là "có người liên hệ mà không ai thành khách", một
 * điều KHÁC hẳn. Tỉ lệ toàn bộ in ở mô tả và ở dòng cuối bảng số. Phần trăm một chữ số thập phân,
 * dấu phẩy thập phân (`27,3 %`); giá trị trên cột là số thô cùng độ tròn (`27.3`).
 *
 * Khuôn M9: view dùng chung `chart-with-table`, không tooltip callback, không `RawJs` (phán quyết CSP
 * của M8 R4); `$isDiscovered = false`.
 */
class IntakeConversionWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;
    use ReadsIntakeReport;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array{sources: array<string, array{won: int, total: int}>, won: int, total: int}|null */
    private ?array $countsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('intake_report.conversion.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        $counts = $this->counts();

        return __('intake_report.conversion.description', [
            'range' => $this->reportFilters()->rangeLabel(),
            'overall' => $this->formatRow($counts['won'], $counts['total']),
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        $counts = $this->counts();

        $rows = [];
        foreach (IntakeSource::cases() as $source) {
            $row = $counts['sources'][$source->value];
            $rows[] = ['label' => $source->label(), 'value' => $this->formatRow($row['won'], $row['total'])];
        }

        $rows[] = ['label' => __('intake_report.conversion.overall'), 'value' => $this->formatRow($counts['won'], $counts['total'])];

        return $rows;
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'labels' => array_map(fn (IntakeSource $source): string => $source->label(), IntakeSource::cases()),
            'datasets' => [
                [
                    'label' => __('intake_report.conversion.series'),
                    'data' => array_values(array_map(
                        fn (array $row): ?float => $row['total'] === 0 ? null : round($this->rate($row['won'], $row['total']), 1),
                        $counts['sources'],
                    )),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['min' => 0, 'max' => 100]],
        ];
    }

    /** "1/5 (20,0 %)", hoặc "Chưa có liên hệ" khi mẫu số bằng 0. */
    private function formatRow(int $won, int $total): string
    {
        if ($total === 0) {
            return __('intake_report.conversion.no_contacts');
        }

        return __('intake_report.conversion.row', [
            'won' => $won,
            'total' => $total,
            'rate' => __('intake_report.conversion.rate', [
                'rate' => number_format($this->rate($won, $total), 1, ',', '.'),
            ]),
        ]);
    }

    private function rate(int $won, int $total): float
    {
        return $won * 100 / $total;
    }

    /** @return array{sources: array<string, array{won: int, total: int}>, won: int, total: int} */
    private function counts(): array
    {
        return $this->countsCache ??= $this->computeCounts();
    }

    /** @return array{sources: array<string, array{won: int, total: int}>, won: int, total: int} */
    private function computeCounts(): array
    {
        $found = $this->contactsInReport()
            ->toBase()
            ->groupBy('source')
            ->selectRaw('source, count(*) as total, sum(case when status = ? then 1 else 0 end) as won', [IntakeStatus::Won->value])
            ->get()
            ->keyBy('source');

        $sources = [];
        foreach (IntakeSource::cases() as $source) {
            $row = $found->get($source->value);
            $sources[$source->value] = [
                'won' => (int) ($row->won ?? 0),
                'total' => (int) ($row->total ?? 0),
            ];
        }

        return [
            'sources' => $sources,
            'won' => array_sum(array_column($sources, 'won')),
            'total' => array_sum(array_column($sources, 'total')),
        ];
    }
}
