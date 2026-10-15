<?php

namespace App\Filament\Admin\Widgets\IntakeReport;

use App\Enums\ChartKind;
use App\Enums\IntakeStatus;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\IntakeReport\Concerns\ReadsIntakeReport;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Lead theo người phụ trách (làn kd, 2026-10-14) — quản lý/quản trị xem từng nhân viên kinh doanh (và mọi
 * người khác có lead) nhận bao nhiêu lead và bao nhiêu đã thành vụ việc. Cột theo người, một chuỗi (tỉ lệ
 * thành vụ việc), một màu `#4a73bd`, trục 0–100 %; bảng số in "đã thành/tổng (tỉ lệ)".
 *
 * **Người phụ trách = người được giao (`assigned_to`); chưa giao ai thì người đã ghi (`created_by`).** Lead
 * nhân viên kinh doanh đã "Chuyển cho luật sư" vẫn tính cho nhân viên kinh doanh (`assigned_to` không đổi —
 * `HandIntakeToLawyer`): công tìm khách là của họ. Không ai cả (cả hai cột null — nhân sự đã bị xoá cứng)
 * thì vào dòng "Không rõ người phụ trách", cuối bảng. Tên nhân sự đã nghỉ việc (xoá mềm) vẫn hiện.
 *
 * **Cùng tập bản ghi và cổng với bốn widget M10** ({@see ReadsIntakeReport}): `intake.viewAny` (404 khi
 * mount thẳng), `contactsInReport()` — nhận trong kỳ, thấy được (`scopeVisibleTo`: bản đã thành vụ
 * `restricted` mà người xem không xem được thì không đếm), trừ bản trùng đã gộp; bộ lọc "người tiếp nhận"
 * của trang áp như mọi widget khác. `won` chỉ do `ConvertIntakeToMatter` đặt. Chỉ người có lead trong kỳ mới
 * có dòng. Không tên, SĐT hay câu chuyện nào của người liên hệ — chỉ tên NHÂN SỰ.
 *
 * Khuôn M9: view dùng chung `chart-with-table`, không tooltip callback, không `RawJs`; `$isDiscovered = false`
 * — chỉ `IntakeReport::getWidgets()` liệt kê nó.
 */
class IntakesByOwnerWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;
    use ReadsIntakeReport;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var list<array{label: string, won: int, total: int}>|null */
    private ?array $rowsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('leads.report.by_owner.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('leads.report.by_owner.description', ['range' => $this->reportFilters()->rangeLabel()]);
    }

    /** Tỉ lệ không phải các phần của một tổng: không có "tròn". */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line];
    }

    public function numberTableRows(): array
    {
        return array_map(fn (array $row): array => [
            'label' => $row['label'],
            'value' => __('leads.report.by_owner.row', [
                'won' => $row['won'],
                'total' => $row['total'],
                'rate' => __('leads.report.by_owner.rate', ['rate' => number_format($row['won'] * 100 / $row['total'], 1, ',', '.')]),
            ]),
        ], $this->rows());
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                [
                    'label' => __('leads.report.by_owner.series_won'),
                    'data' => array_map(fn (array $row): float => round($row['won'] * 100 / $row['total'], 1), $rows),
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

    /** @return list<array{label: string, won: int, total: int}> */
    private function rows(): array
    {
        return $this->rowsCache ??= $this->computeRows();
    }

    /** @return list<array{label: string, won: int, total: int}> */
    private function computeRows(): array
    {
        $found = $this->contactsInReport()
            ->toBase()
            ->selectRaw('coalesce(assigned_to, created_by) as owner_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as won', [IntakeStatus::Won->value])
            ->groupByRaw('coalesce(assigned_to, created_by)')
            ->get();

        $names = User::query()
            ->withTrashed()
            ->whereIn('id', $found->pluck('owner_id')->filter()->all())
            ->pluck('name', 'id');

        $rows = $found
            ->map(fn (object $row): array => [
                'label' => $row->owner_id === null
                    ? __('leads.report.by_owner.unknown')
                    : (string) ($names[(int) $row->owner_id] ?? __('leads.report.by_owner.unknown')),
                'known' => $row->owner_id !== null && isset($names[(int) $row->owner_id]),
                'won' => (int) $row->won,
                'total' => (int) $row->total,
            ])
            ->sortBy([['known', 'desc'], ['label', 'asc']])
            ->map(fn (array $row): array => ['label' => $row['label'], 'won' => $row['won'], 'total' => $row['total']])
            ->values()
            ->all();

        return $rows;
    }
}
