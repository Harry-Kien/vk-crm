<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\InstalmentTrigger;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Doanh thu ĐÃ THU theo từng đợt/giai đoạn — cột ngang, xếp theo THỨ TỰ GIAI ĐOẠN của loại vụ
 * việc (thứ tự thời gian chính là thông tin, cùng thành ngữ `MattersByStageWidget`). Trả lời: văn
 * phòng đang kẹt tiền ở khúc nào của quy trình.
 *
 * **Chỉ đợt `trigger_type = stage` gộp được theo giai đoạn.** Task 6 (đợt theo giai đoạn tự kích
 * hoạt) bị hoãn ở làn này, nên nhiều đợt `stage` vẫn có thể ở trạng thái `scheduled` (chưa có
 * `due_date`) — KHÔNG ảnh hưởng widget này, vì nó gộp theo KHOẢN THU đã về (`payments`), không
 * theo trạng thái đợt. Đợt `on_signing`/`due_date` không gắn với một giai đoạn nào — CHÚNG ĐI VÀO
 * hai "bó" riêng, có nhãn rõ ràng ("Tạm ứng khi ký hợp đồng", "Đến hạn theo ngày cụ thể"), KHÔNG bị
 * bỏ sót và KHÔNG bị gộp lẫn vào một giai đoạn nào chúng không thuộc về.
 *
 * **Gộp theo (matter_type, stage), không theo nhãn** — cùng lý do `MattersByStageWidget`: nhãn
 * giai đoạn không duy nhất toàn hệ thống, chỉ duy nhất trong một loại vụ việc.
 *
 * **Thời gian lọc `payments.paid_on`** ("tiền về trong kỳ"); **luật sư lọc
 * `payments.attributed_lawyer_id`** ("luật sư phụ trách lúc thu") — cùng nghĩa với
 * `RevenueOverTimeWidget`, vì đây cũng là một biểu đồ tiền ĐÃ THU.
 *
 * Cột một chuỗi, một màu `#4a73bd`, không chú giải.
 */
class RevenueByStageWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_stage.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_stage.description', [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
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
                    'label' => __('widgets.revenue_dashboard.by_stage.series'),
                    'data' => array_values($buckets),
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

    /** @return array<string, int> Nhãn bó (theo thứ tự giai đoạn, rồi hai bó "khác") => tổng đã thu. */
    private function buckets(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();

        // Một truy vấn Eloquent để lấy đúng tập vụ việc `Matter::scopeListableBy()` cho phép,
        // rồi dùng lại tập id đó trong truy vấn JOIN thô bên dưới — không viết lại điều kiện
        // "ai thấy vụ nào" bằng SQL tay (P3: MỘT định nghĩa duy nhất).
        $matterIds = Matter::query()
            ->listableBy($user)
            ->when($filters->practiceAreaId, fn ($q, int $v) => $q->where('matter_type_id', $v))
            ->pluck('id');

        if ($matterIds->isEmpty()) {
            return [];
        }

        $base = fn (): Builder => DB::table('payments')
            ->join('instalments', 'instalments.id', '=', 'payments.instalment_id')
            ->join('contracts', 'contracts.id', '=', 'instalments.contract_id')
            ->whereIn('contracts.matter_id', $matterIds)
            ->whereNull('payments.voided_at')
            ->whereBetween('payments.paid_on', [$from, $to])
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('payments.attributed_lawyer_id', $v));

        $stageRows = $base()
            ->join('matters', 'matters.id', '=', 'contracts.matter_id')
            ->join('matter_type_stages', function ($join): void {
                $join->on('matter_type_stages.matter_type_id', '=', 'matters.matter_type_id')
                    ->on('matter_type_stages.key', '=', 'instalments.trigger_stage_key')
                    ->whereNull('matter_type_stages.deleted_at');
            })
            ->join('matter_types', 'matter_types.id', '=', 'matter_type_stages.matter_type_id')
            ->where('instalments.trigger_type', InstalmentTrigger::Stage->value)
            ->groupBy(
                'matter_types.id', 'matter_types.name', 'matter_types.sort_order',
                'matter_type_stages.label', 'matter_type_stages.sort_order',
            )
            ->orderBy('matter_types.sort_order')
            ->orderBy('matter_types.id')
            ->orderBy('matter_type_stages.sort_order')
            ->selectRaw('matter_types.name as type_name, matter_type_stages.label as stage_label, sum(payments.amount) as total')
            ->get();

        $catchAllRows = $base()
            ->whereIn('instalments.trigger_type', [InstalmentTrigger::OnSigning->value, InstalmentTrigger::DueDate->value])
            ->groupBy('instalments.trigger_type')
            ->selectRaw('instalments.trigger_type as trigger_type, sum(payments.amount) as total')
            ->get()
            ->keyBy('trigger_type');

        $buckets = [];

        foreach ($stageRows as $row) {
            $label = __('widgets.revenue_dashboard.by_stage.bucket_label', [
                'type' => $row->type_name,
                'stage' => $row->stage_label,
            ]);
            $buckets[$label] = (int) $row->total;
        }

        if ($onSigning = $catchAllRows->get(InstalmentTrigger::OnSigning->value)) {
            $buckets[__('widgets.revenue_dashboard.by_stage.on_signing_bucket')] = (int) $onSigning->total;
        }

        if ($dueDate = $catchAllRows->get(InstalmentTrigger::DueDate->value)) {
            $buckets[__('widgets.revenue_dashboard.by_stage.due_date_bucket')] = (int) $dueDate->total;
        }

        return $buckets;
    }
}
