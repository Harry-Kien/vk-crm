<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\Permission;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\RevenueFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Tải theo luật sư — cột ngang, số vụ việc ĐANG MỞ (`closed_at is null`) theo luật sư phụ trách.
 *
 * **KHÔNG phụ thuộc bộ lọc thời gian của trang.** Đây là một ẢNH CHỤP HIỆN TẠI ("ai đang cầm bao
 * nhiêu vụ ngay lúc này"), không phải một số phát sinh trong một kỳ — nên đổi kỳ lọc trên trang
 * KHÔNG đổi widget này (có test khẳng định đúng điều đó). Bộ lọc luật sư, nếu chọn, chỉ còn lại
 * đúng một cột — một phép thu hẹp tầm thường nhưng nhất quán với mọi widget khác.
 *
 * **Đòi thêm `revenue.viewAny`** — widget so sánh toàn văn phòng thứ hai (cùng lý do
 * `MatterMixByPracticeAreaWidget`): luật sư không thấy widget này.
 *
 * Cột một chuỗi, một màu `#4a73bd`, không chú giải. Không có bảng số tiền đi kèm (đây là số VỤ,
 * không phải số tiền) — nhưng vẫn giữ cùng view dùng chung để có bảng số ĐẾM, đúng "luôn có một
 * bảng số đi kèm mỗi biểu đồ".
 */
class LoadPerLawyerWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::RevenueViewAny->value);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.load_per_lawyer.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.load_per_lawyer.description');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function numberTableRows(): array
    {
        return collect($this->counts())
            ->map(fn (int $count, string $label): array => ['label' => $label, 'value' => (string) $count])
            ->values()
            ->all();
    }

    protected function getData(): array
    {
        $counts = $this->counts();

        return [
            'labels' => array_keys($counts),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.load_per_lawyer.series'),
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
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }

    /** @return array<string, int> Tên luật sư (giảm dần theo số vụ) => số vụ đang mở. */
    private function counts(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        $rows = Matter::query()
            ->listableBy($user)
            ->whereNull('closed_at')
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matter_type_id', $v))
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('lead_lawyer_id', $v))
            ->join('users', 'users.id', '=', 'matters.lead_lawyer_id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->orderBy('users.name')
            ->selectRaw('users.id as user_id, users.name as lawyer_name, count(*) as total')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->lawyer_name] = (int) $row->total;
        }

        return $result;
    }
}
