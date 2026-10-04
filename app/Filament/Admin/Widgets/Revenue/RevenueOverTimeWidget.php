<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\RequiresBillingView;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use App\Support\Scopes\ClientPortalScope;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
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
    use InteractsWithPageFilters;
    use RequiresBillingView;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    public ?string $filter = 'month';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $bucketsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.over_time.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.over_time.description', [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    protected function getFilters(): ?array
    {
        return [
            'month' => __('widgets.revenue_dashboard.over_time.filter_month'),
            'quarter' => __('widgets.revenue_dashboard.over_time.filter_quarter'),
            'year' => __('widgets.revenue_dashboard.over_time.filter_year'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        return collect($this->buckets())
            ->map(fn (int $amount, string $label): array => ['label' => $label, 'value' => Money::format($amount)])
            ->values()
            ->all();
    }

    protected function getData(): array
    {
        $buckets = $this->buckets();

        return [
            'labels' => array_keys($buckets),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.over_time.series'),
                    'data' => array_values($buckets),
                    'backgroundColor' => '#4a73bd',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['ticks' => ['precision' => 0]]],
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

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        $rows = Payment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('voided_at')
            ->whereBetween('paid_on', $filters->bounds())
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('attributed_lawyer_id', $v))
            ->whereHas('instalment.contract.matter', fn (Builder $q) => $q
                ->listableBy($user)
                ->when($filters->practiceAreaId, fn (Builder $mq, int $v) => $mq->where('matter_type_id', $v)))
            ->get(['paid_on', 'amount']);

        $granularity = in_array($this->filter, ['month', 'quarter', 'year'], true) ? $this->filter : 'month';

        $sums = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->paid_on);
            [$sortKey, $label] = match ($granularity) {
                // Lượt rà soát cuối M9, M4: nhãn quý qua lang/vi, không chữ "Q" viết cứng.
                'quarter' => ["{$date->year}-{$date->quarter}", __('widgets.revenue_dashboard.over_time.quarter_label', ['quarter' => $date->quarter, 'year' => $date->year])],
                'year' => [(string) $date->year, (string) $date->year],
                default => [$date->format('Y-m'), $date->format('m/Y')],
            };

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
}
