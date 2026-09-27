<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\InstalmentStatus;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use App\Support\Scopes\ClientPortalScope;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * "Đã thu / còn phải thu / quá hạn" — vành khuyên, BA lát, kèm một bảng số (M9 Task 9). Nơi DUY
 * NHẤT trên trang này một biểu đồ tròn xứng đáng: một tổng thể (giá trị đã ký trong kỳ, trừ phần
 * đã miễn), ba phần cộng đúng lại 100%.
 *
 * **Thời gian lọc `contracts.signed_at`** — "việc đã ký trong kỳ" — KHÔNG phải `payments.paid_on`.
 * Ba lát vẫn tính TẠI HÔM NAY (đã thu/còn phải thu/quá hạn là ảnh chụp hiện tại của những hợp
 * đồng đã ký trong kỳ đó, không phải "đã thu trong kỳ").
 *
 * **Luật sư lọc theo `matters.lead_lawyer_id`** — "luật sư phụ trách HIỆN TẠI" — áp dụng ĐỀU cho
 * cả ba lát (khác `RevenueOverTimeWidget`/`RevenueByStageWidget`, nơi lát "đã thu" lọc theo
 * `payments.attributed_lawyer_id`): donut mô tả một TẬP VỤ VIỆC, không phải một tập khoản thu, nên
 * một nghĩa lọc duy nhất là đúng cho toàn bộ nó.
 *
 * **Ba lát cộng lại ĐÚNG "tổng giá trị đã ký trong kỳ trừ phần đã miễn"** — không phải một luật lệ
 * cần canh giữ ở nhiều chỗ: `not_yet_due` được tính bằng PHÉP TRỪ (`signed - waived - collected -
 * overdue`), không phải một công thức độc lập — nên đẳng thức đúng theo cấu trúc, không theo may
 * rủi. `overdue` dùng lại {@see Instalment::scopeOverdue()} (đã đòi hợp đồng `active`): một hợp
 * đồng đã ký trong kỳ nhưng sau đó `completed`/`cancelled` đóng góp 0 vào lát "quá hạn" và phần dư
 * của nó rơi vào "còn phải thu, chưa tới hạn" — một đơn giản hoá có chủ đích, ghi lại ở đây vì nó
 * ảnh hưởng tới ý nghĩa hiển thị (không ảnh hưởng tới đẳng thức tổng).
 *
 * Bộ ba màu đã kiểm chứng máy (kế hoạch M9): đã thu `#0ca30c`, còn phải thu chưa tới hạn `#4a73bd`,
 * quá hạn `#d03b3b`.
 */
class ReceivablesDonutWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.donut.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.donut.description', [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function numberTableRows(): array
    {
        $amounts = $this->amounts();

        return [
            ['label' => __('widgets.revenue_dashboard.donut.table.signed_total'), 'value' => Money::format($amounts['signed'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.waived_total'), 'value' => Money::format($amounts['waived'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.collected'), 'value' => Money::format($amounts['collected'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.not_yet_due'), 'value' => Money::format($amounts['not_yet_due'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.overdue'), 'value' => Money::format($amounts['overdue'])],
        ];
    }

    protected function getData(): array
    {
        $amounts = $this->amounts();

        return [
            'labels' => [
                __('widgets.revenue_dashboard.donut.slices.collected', ['amount' => Money::format($amounts['collected'])]),
                __('widgets.revenue_dashboard.donut.slices.not_yet_due', ['amount' => Money::format($amounts['not_yet_due'])]),
                __('widgets.revenue_dashboard.donut.slices.overdue', ['amount' => Money::format($amounts['overdue'])]),
            ],
            'datasets' => [
                [
                    'data' => [$amounts['collected'], $amounts['not_yet_due'], $amounts['overdue']],
                    'backgroundColor' => ['#0ca30c', '#4a73bd', '#d03b3b'],
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
        ];
    }

    /** @return array{signed: int, waived: int, collected: int, not_yet_due: int, overdue: int} */
    private function amounts(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return ['signed' => 0, 'waived' => 0, 'collected' => 0, 'not_yet_due' => 0, 'overdue' => 0];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();

        $matterScope = fn (Builder $query): Builder => $query
            ->listableBy($user)
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matter_type_id', $v))
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('lead_lawyer_id', $v));

        $contractScope = fn (Builder $query): Builder => $query
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereBetween('signed_at', [$from, $to])
            ->whereHas('matter', $matterScope);

        $signed = (int) Contract::query()->tap($contractScope)->sum('total_amount');

        $waived = (int) Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', InstalmentStatus::Waived->value)
            ->whereHas('contract', $contractScope)
            ->sum('amount');

        $collected = (int) Payment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('voided_at')
            ->whereHas('instalment.contract', $contractScope)
            ->sum('amount');

        $overdue = (int) Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->overdue()
            ->whereHas('contract', $contractScope)
            ->sum('amount');

        $notYetDue = max(0, $signed - $waived - $collected - $overdue);

        return [
            'signed' => $signed,
            'waived' => $waived,
            'collected' => $collected,
            'not_yet_due' => $notYetDue,
            'overdue' => $overdue,
        ];
    }
}
