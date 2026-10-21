<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\ChartKind;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasRevenueEmptyState;
use App\Filament\Admin\Widgets\Revenue\Concerns\RequiresBillingView;
use App\Models\User;
use App\Support\Billing\CollectedRevenue;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use App\Support\Charts\MoneyChart;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Doanh thu theo thời gian — cột, gộp theo tháng/quý/năm bằng `ChartWidget::$filter` (ô lọc RIÊNG
 * của chính widget này, KHÁC bộ lọc kỳ của cả trang: bộ lọc trang chọn CỬA SỔ dữ liệu — "tiền về
 * TRONG kỳ nào"; `$filter` ở đây chọn cách CHIA cửa sổ đó thành các cột).
 *
 * **Thời gian lọc `payments.paid_on`** — "tiền về trong kỳ" — KHÔNG phải `contracts.signed_at`.
 *
 * **Luật sư lọc theo `payments.attributed_lawyer_id`** — "luật sư phụ trách LÚC THU" (P2): một
 * khoản thu trước khi bàn giao vụ vẫn tính cho luật sư CŨ ở đây, dù vụ nay đã có luật sư phụ trách
 * khác — khác donut/mix, nơi lọc theo luật sư HIỆN TẠI của vụ.
 *
 * Cột một chuỗi — một màu `#4a73bd`, không chú giải (kế hoạch M9, "Cột một chuỗi dùng đúng một
 * màu").
 */
class RevenueOverTimeWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasRevenueEmptyState;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;
    use RequiresBillingView;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    public ?string $filter = 'month';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $bucketsCache = null;

    /** "Doanh thu theo tháng/quý/năm" — theo cách chia cột đang chọn ở ô "Theo tháng" (làn rv). */
    public function getHeading(): string|Htmlable|null
    {
        $unit = in_array($this->filter, ['month', 'quarter', 'year'], true) ? $this->filter : 'month';

        return __('widgets.revenue_dashboard.over_time.heading', [
            'unit' => __('widgets.revenue_dashboard.over_time.heading_units.'.$unit),
        ]);
    }

    /**
     * Một dòng phụ: khoảng ngày tiền về ĐANG VẼ — khoảng xu hướng {@see RevenueFilters::trendWindow()}, không
     * phải kỳ đầu trang (2026-10-21). Cách tính nằm ở {@see self::getExplanation()}.
     */
    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.over_time.description', [
            'range' => $this->window()->rangeLabel(),
        ]);
    }

    /**
     * Chữ của nút ⓘ cạnh tiêu đề (làn rv, 2026-10-15): cách tính đầy đủ, trước làn rv in thẳng dưới tiêu
     * đề. `chart-with-table` đọc nó nếu widget khai. Đang lọc một nhân viên kinh doanh thì thêm nghĩa của bộ
     * lọc đó (2026-10-21).
     */
    public function getExplanation(): string
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        return __('widgets.revenue_dashboard.over_time.explanation', [
            'range' => $this->window()->rangeLabel(),
            'period' => $filters->rangeLabel(),
        ]).($filters->salesId !== null ? ' '.__('widgets.revenue_dashboard.sales_filter_meaning') : '');
    }

    /** Cách chia cột đang chọn; giá trị lạ (Livewire nhận từ trình duyệt) rơi về tháng. */
    private function granularity(): string
    {
        return in_array($this->filter, ['month', 'quarter', 'year'], true) ? $this->filter : 'month';
    }

    /** Khoảng của biểu đồ xu hướng — {@see RevenueFilters::trendWindow()}. */
    private function window(): RevenueFilters
    {
        return RevenueFilters::fromPageFilters($this->pageFilters)->trendWindow($this->granularity());
    }

    protected function getFilters(): ?array
    {
        return [
            'month' => __('widgets.revenue_dashboard.over_time.filter_month'),
            'quarter' => __('widgets.revenue_dashboard.over_time.filter_quarter'),
            'year' => __('widgets.revenue_dashboard.over_time.filter_year'),
        ];
    }

    /**
     * Không có "tròn": các giá trị ở đây không phải các phần của một tổng (chuỗi theo thời gian, tỷ lệ phần
     * trăm, trung vị), nên một hình tròn chia lát sẽ nói sai.
     */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line];
    }

    public function numberTableRows(): array
    {
        return collect($this->buckets())
            ->map(fn (int $amount, string|int $label): array => ['label' => (string) $label, 'value' => Money::format($amount)])
            ->values()
            ->all();
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();

        return [
            // PHP đổi khoá "2026" thành số nguyên: nhãn năm phải về lại chuỗi.
            'labels' => array_map('strval', array_keys($buckets)),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.over_time.series'),
                    'data' => array_values($buckets),
                    'backgroundColor' => '#4a73bd',
                    // Ít cột (ví dụ bốn quý) thì cột không phình hết khung (2026-10-21).
                    'maxBarThickness' => 56,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false], ...MoneyChart::pluginOptions()],
            'scales' => ['y' => MoneyChart::valueAxis()],
        ];
    }

    /** @return array<string, int> Nhãn kỳ (đã sắp theo thời gian) => tổng đã thu, chưa định dạng. */
    private function buckets(): array
    {
        // Bộ nhớ đệm phải tính theo $this->filter (granularity tháng/quý/năm đổi bucket) — không
        // chỉ một cache tĩnh, vì `getData()` và `numberTableRows()` phải thấy CÙNG kết quả trong
        // MỘT lần render, nhưng một lần đổi `$filter` qua Livewire lại phải tính lại.
        return $this->bucketsCache[$this->filter ?? 'month'] ??= $this->computeBuckets();
    }

    /** @return array<string, int> */
    private function computeBuckets(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        // Khoảng XU HƯỚNG (2026-10-21): kết thúc ở cuối kỳ đầu trang, bắt đầu ở đầu năm (tháng, quý) hoặc
        // 5 năm (năm) — {@see RevenueFilters::trendWindow()}. Luật sư và lĩnh vực vẫn là của trang.
        $granularity = $this->granularity();
        $filters = $this->window();

        // "Tiền đã thu trong khoảng, trong tầm nhìn của người xem" là CollectedRevenue (M13 Task 2, tách
        // nguyên văn từ đây — cột P7 của trang hiệu suất đọc cùng truy vấn); hai bộ lọc của trang gắn qua
        // `filtered()` (làn rv: cùng chỗ với ô "Đã thu" đầu trang).
        $rows = CollectedRevenue::filtered($user, $filters, $filters->bounds())
            ->get(['paid_on', 'amount']);

        // Mọi kỳ trong khoảng đều có cột, kỳ chưa có tiền là 0 — một chuỗi thời gian thiếu kỳ đọc sai xu hướng.
        $sums = [];
        for ($cursor = $filters->from->copy(); $cursor->lessThanOrEqualTo($filters->to); $cursor = $this->nextBucket($cursor, $granularity)) {
            [$sortKey, $label] = $this->bucketOf($cursor, $granularity);
            $sums[$sortKey] = ['label' => $label, 'amount' => 0];
        }

        foreach ($rows as $row) {
            [$sortKey, $label] = $this->bucketOf(Carbon::parse($row->paid_on), $granularity);

            $sums[$sortKey] ??= ['label' => $label, 'amount' => 0];
            $sums[$sortKey]['amount'] += (int) $row->amount;
        }

        ksort($sums);

        $labelled = [];
        foreach ($sums as $bucket) {
            $labelled[$bucket['label']] = $bucket['amount'];
        }

        return $labelled;
    }

    /** @return array{0: string, 1: string} Khoá sắp xếp và nhãn của kỳ chứa `$date`. */
    private function bucketOf(Carbon $date, string $granularity): array
    {
        return match ($granularity) {
            // Lượt rà soát cuối M9, M4: nhãn quý qua lang/vi, không chữ "Q" viết cứng.
            'quarter' => ["{$date->year}-{$date->quarter}", __('widgets.revenue_dashboard.over_time.quarter_label', ['quarter' => $date->quarter, 'year' => $date->year])],
            'year' => [(string) $date->year, (string) $date->year],
            default => [$date->format('Y-m'), $date->format('m/Y')],
        };
    }

    /** Ngày đầu của kỳ kế tiếp. */
    private function nextBucket(Carbon $date, string $granularity): Carbon
    {
        return match ($granularity) {
            'quarter' => $date->copy()->startOfQuarter()->addQuarterNoOverflow(),
            'year' => $date->copy()->startOfYear()->addYearNoOverflow(),
            default => $date->copy()->startOfMonth()->addMonthNoOverflow(),
        };
    }
}
