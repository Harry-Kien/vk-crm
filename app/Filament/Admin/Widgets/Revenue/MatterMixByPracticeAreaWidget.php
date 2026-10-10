<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\ChartKind;
use App\Enums\ContractStatus;
use App\Enums\Permission;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Models\Contract;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Cơ cấu vụ việc theo lĩnh vực hành nghề — cột ngang XẾP HẠNG (giảm dần theo giá trị đang đo),
 * KHÔNG bao giờ một biểu đồ tròn: 12 lát (hay 6, tuỳ Task 1 đã chạy hay chưa — xem dưới) không đọc
 * được trên một hình tròn.
 *
 * **Đủ MỌI lĩnh vực đang cấu hình, kể cả lĩnh vực 0 vụ** — liệt kê từ `MatterType`, không suy từ
 * tập hợp đồng có dữ liệu: một lĩnh vực không ai ký hợp đồng nào trong kỳ vẫn phải hiện với giá
 * trị 0, để "văn phòng có bao nhiêu lĩnh vực đang hoạt động" đọc được từ chính biểu đồ, không chỉ
 * từ những lĩnh vực có việc.
 *
 * **Task 1 (12 lĩnh vực) hoãn** — hôm nay chỉ có bấy nhiêu `MatterType` đã seed; widget không hề
 * biết số 12, nó vẽ đúng số lĩnh vực đang tồn tại trong cấu hình. Task 13 đối chiếu lại với đủ 12.
 *
 * **Thời gian lọc `contracts.signed_at`** ("việc đã ký trong kỳ"), **luật sư lọc
 * `matters.lead_lawyer_id`** ("luật sư phụ trách hiện tại") — cùng nghĩa với donut.
 *
 * **Chỉ hợp đồng `active` và `completed`** (lượt rà soát cuối M9, M7) — CÙNG quần thể với phần đối
 * chiếu của `ReceivablesDonutWidget`: một hợp đồng đã huỷ không còn là "việc đã ký" của lĩnh vực
 * đó, và bản trước cộng nó vào cả số tiền lẫn số vụ, nên hai biểu đồ cạnh nhau trên cùng trang nói
 * hai con số "giá trị đã ký" khác nhau. `draft` tự ra ngoài vì chưa có `signed_at`.
 *
 * **Công tắc "đếm theo số vụ"** (`RevenueFilters::$byCount`) đổi THỨ ĐƯỢC ĐO — số hợp đồng đã ký
 * hay tổng giá trị đã ký — KHÔNG thêm trục thứ hai: luôn đúng một chuỗi dữ liệu.
 *
 * **Đòi thêm `revenue.viewAny`** (`canView()`): đây là một trong hai widget SO SÁNH TOÀN VĂN
 * PHÒNG (kế hoạch M9, "Phân quyền của trang"). Luật sư (có `billing.view`, không có
 * `revenue.viewAny`) không thấy widget này. Phạm vi "toàn văn phòng" không cần mã riêng: mọi vai
 * trò có `revenue.viewAny` (kế toán, quản lý, admin) cũng có `matter.viewAny`, nên
 * `Matter::scopeListableBy()` cho họ đã LÀ mọi vụ thường (kế toán/quản lý) hay mọi vụ (admin) —
 * đúng định nghĩa duy nhất P3, không viết lại.
 */
class MatterMixByPracticeAreaWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $rankedCache = null;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::RevenueViewAny->value);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.mix_by_practice_area.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.mix_by_practice_area.description', [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    /** Một chuỗi số theo từng mục rời nhau: đủ ba dạng, cột là dạng gốc. */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line, ChartKind::Pie];
    }

    public function numberTableRows(): array
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        return collect($this->ranked())
            ->map(fn (array $row): array => [
                'label' => $row['label'],
                'value' => $filters->byCount ? (string) $row['count'] : Money::format($row['amount']),
            ])
            ->all();
    }

    protected function getData(): array
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $ranked = $this->ranked();

        return [
            'labels' => array_column($ranked, 'label'),
            'datasets' => [
                [
                    'label' => $filters->byCount
                        ? __('widgets.revenue_dashboard.mix_by_practice_area.series_count')
                        : __('widgets.revenue_dashboard.mix_by_practice_area.series_amount'),
                    'data' => array_map(
                        fn (array $row): int => $filters->byCount ? $row['count'] : $row['amount'],
                        $ranked,
                    ),
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
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }

    /** @return list<array{label: string, count: int, amount: int}> Xếp giảm dần theo giá trị đang đo. */
    private function ranked(): array
    {
        return $this->rankedCache ??= $this->computeRanked();
    }

    /** @return list<array{label: string, count: int, amount: int}> */
    private function computeRanked(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        [$from, $to] = $filters->bounds();

        $signed = Contract::query()
            ->join('matters', 'matters.id', '=', 'contracts.matter_id')
            ->whereBetween('contracts.signed_at', [$from, $to])
            ->whereIn('contracts.status', [ContractStatus::Active->value, ContractStatus::Completed->value])
            ->whereHas('matter', fn (Builder $q) => $q
                ->listableBy($user)
                ->when($filters->lawyerId, fn (Builder $mq, int $v) => $mq->where('lead_lawyer_id', $v)))
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matters.matter_type_id', $v))
            ->groupBy('matters.matter_type_id')
            ->selectRaw('matters.matter_type_id as matter_type_id, count(*) as matters_count, sum(contracts.total_amount) as total_amount')
            ->get()
            ->keyBy('matter_type_id');

        $rows = MatterType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (MatterType $type, int $index) use ($signed): array {
                $row = $signed->get($type->id);

                return [
                    'label' => $type->name,
                    'count' => (int) ($row->matters_count ?? 0),
                    'amount' => (int) ($row->total_amount ?? 0),
                    'tie_break' => $index,
                ];
            })
            ->all();

        usort($rows, fn (array $a, array $b): int => ($filters->byCount ? $b['count'] <=> $a['count'] : $b['amount'] <=> $a['amount'])
            ?: $a['tie_break'] <=> $b['tie_break']);

        return array_map(fn (array $row): array => [
            'label' => $row['label'],
            'count' => $row['count'],
            'amount' => $row['amount'],
        ], $rows);
    }
}
