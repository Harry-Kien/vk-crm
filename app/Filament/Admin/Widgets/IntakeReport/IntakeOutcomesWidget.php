<?php

namespace App\Filament\Admin\Widgets\IntakeReport;

use App\Enums\IntakeStatus;
use App\Filament\Admin\Widgets\IntakeReport\Concerns\ReadsIntakeReport;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Lý do không thành vụ việc, theo nhóm (M10 Task 6, R8) — cột ngang, một chuỗi, một màu `#4a73bd`.
 *
 * **Bốn nhóm, dựng đúng từ những gì mô hình dữ liệu có** — `decline_reason` là chữ tự do, chỉ
 * `decline_reason_is_conflict` (bool) và trạng thái là phân loại được:
 *  1. Văn phòng từ chối — xung đột lợi ích (`declined` + `decline_reason_is_conflict`);
 *  2. Văn phòng từ chối — lý do khác (`declined`, cờ tắt);
 *  3. Khách không theo tiếp (`lost`);
 *  4. Đã gộp vào bản ghi khác (`merged` — bản trùng; ba widget kia không đếm nó, ở đây nó là một
 *     nhóm riêng để tổng các bản ghi nhận trong kỳ đối chiếu được).
 * Không tự thêm cột phân loại lý do; câu hỏi "có cần phân loại lý do khác chi tiết hơn không" ghi ở
 * Ghi chú M10.
 *
 * **R8 — nhóm "xung đột" là thông tin nhạy cảm**: chỉ người có `intake.viewAny` được biết một lần từ
 * chối là vì xung đột (`IntakeRequestPolicy::viewConflictReason`). Widget này đòi đúng quyền đó
 * ({@see ReadsIntakeReport::canView()}), nên mọi người thấy nó đều được thấy nhóm này. **Lý do chữ
 * KHÔNG BAO GIỜ hiện ở đây** — không cột chữ nào được đọc, chỉ đếm theo nhóm: lý do tự do có thể nêu
 * tên khách hàng bên kia.
 *
 * Tập đếm: {@see ReadsIntakeReport::intakesInReport()} (nhận trong kỳ, thấy được, KỂ CẢ bản đã gộp,
 * kể cả bản đã ẩn danh — trạng thái và cờ xung đột không phải dữ liệu cá nhân, R7b giữ chúng).
 *
 * Khuôn M9: view dùng chung `chart-with-table`, không tooltip callback, không `RawJs` (phán quyết CSP
 * của M8 R4); `$isDiscovered = false`.
 */
class IntakeOutcomesWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;
    use ReadsIntakeReport;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array{declined_conflict: int, declined_other: int, lost: int, merged: int}|null */
    private ?array $countsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('intake_report.outcomes.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('intake_report.outcomes.description', ['range' => $this->reportFilters()->rangeLabel()]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        $rows = [];
        foreach ($this->counts() as $group => $count) {
            $rows[] = ['label' => $this->groupLabel($group), 'value' => (string) $count];
        }

        $rows[] = ['label' => __('intake_report.outcomes.total'), 'value' => (string) array_sum($this->counts())];

        return $rows;
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'labels' => array_map(fn (string $group): string => $this->groupLabel($group), array_keys($counts)),
            'datasets' => [
                [
                    'label' => __('intake_report.outcomes.series'),
                    'data' => array_values($counts),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'declined_conflict' => __('intake_report.outcomes.groups.declined_conflict'),
            'declined_other' => __('intake_report.outcomes.groups.declined_other'),
            'lost' => IntakeStatus::Lost->label(),
            'merged' => IntakeStatus::Merged->label(),
        };
    }

    /** @return array{declined_conflict: int, declined_other: int, lost: int, merged: int} */
    private function counts(): array
    {
        return $this->countsCache ??= $this->computeCounts();
    }

    /** @return array{declined_conflict: int, declined_other: int, lost: int, merged: int} */
    private function computeCounts(): array
    {
        $rows = $this->intakesInReport()
            ->whereIn('status', [IntakeStatus::Declined->value, IntakeStatus::Lost->value, IntakeStatus::Merged->value])
            ->toBase()
            ->groupBy('status', 'decline_reason_is_conflict')
            ->selectRaw('status, decline_reason_is_conflict as is_conflict, count(*) as total')
            ->get();

        $counts = ['declined_conflict' => 0, 'declined_other' => 0, 'lost' => 0, 'merged' => 0];

        foreach ($rows as $row) {
            $group = match ($row->status) {
                IntakeStatus::Declined->value => (bool) $row->is_conflict ? 'declined_conflict' : 'declined_other',
                IntakeStatus::Lost->value => 'lost',
                IntakeStatus::Merged->value => 'merged',
            };

            $counts[$group] += (int) $row->total;
        }

        return $counts;
    }
}
