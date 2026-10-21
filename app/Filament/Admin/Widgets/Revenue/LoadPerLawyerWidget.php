<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\ChartKind;
use App\Enums\Permission;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasRevenueEmptyState;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\RevenueColours;
use App\Support\Billing\RevenueFilters;
use App\Support\Charts\MoneyChart;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Tải theo luật sư — cột ngang, số vụ việc ĐANG MỞ (`closed_at is null`) theo luật sư phụ trách
 * HIỆN TẠI (`matters.lead_lawyer_id`, Fix round 1, I6 — nghĩa này giờ được NÓI RA trên
 * `getExplanation()`, nút ⓘ cạnh tiêu đề từ làn rv; trước đó là `getDescription()` — không chỉ ngụ ý qua
 * tên widget).
 *
 * **KHÔNG phụ thuộc bộ lọc thời gian của trang.** Đây là một ẢNH CHỤP HIỆN TẠI ("ai đang cầm bao
 * nhiêu vụ ngay lúc này"), không phải một số phát sinh trong một kỳ — nên đổi kỳ lọc trên trang
 * KHÔNG đổi widget này (có test khẳng định đúng điều đó).
 *
 * **Biểu đồ SO SÁNH — không thu hẹp theo người** (2026-10-21, chủ văn phòng: chọn một luật sư để xem người
 * đó, vẫn phải so được với đồng nghiệp). Chọn một luật sư ở bộ lọc trang thì MỌI luật sư vẫn hiện, cột của
 * người đang chọn giữ màu chuỗi, cột người khác nhạt đi (`RevenueColours::forPeople()`), bảng số ghi
 * "đang xem" bên tên người đó. Trước đó bộ lọc luật sư thu hẹp còn đúng một cột. Bộ lọc nhân viên kinh
 * doanh không đổi biểu đồ này (tải là của luật sư); lĩnh vực vẫn thu hẹp tập vụ.
 *
 * **Gộp theo ID luật sư, không theo tên** (Fix round 1, I3): hai luật sư trùng tên (không hiếm ở
 * Việt Nam) sẽ đè số vụ của nhau nếu khoá là chuỗi tên hiển thị. `counts()` trả về một DANH SÁCH
 * (không phải mảng kết hợp theo tên), mỗi phần tử tự mang `key` (id luật sư) và `label` (tên hiển
 * thị) riêng.
 *
 * **Đòi thêm `revenue.viewAny`** — widget so sánh toàn văn phòng thứ hai (cùng lý do
 * `MatterMixByPracticeAreaWidget`): luật sư không thấy widget này.
 *
 * Cột một chuỗi, một màu `#4a73bd` (hai sắc khi đang chọn một luật sư, xem trên), không chú giải. Không có
 * vụ đang mở nào thì khung in câu rỗng của biểu đồ đếm (`HasRevenueEmptyState`). Không có bảng số tiền đi kèm (đây là số VỤ,
 * không phải số tiền) — nhưng vẫn giữ cùng view dùng chung để có bảng số ĐẾM, đúng "luôn có một
 * bảng số đi kèm mỗi biểu đồ".
 */
class LoadPerLawyerWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasRevenueEmptyState;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $countsCache = null;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::RevenueViewAny->value);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.load_per_lawyer.heading');
    }

    /** Một dòng phụ (làn rv). Cách tính nằm ở {@see self::getExplanation()}. */
    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.load_per_lawyer.description');
    }

    /**
     * Chữ của nút ⓘ cạnh tiêu đề (làn rv, 2026-10-15): cách tính đầy đủ, trước làn rv in thẳng dưới tiêu
     * đề. `chart-with-table` đọc nó nếu widget khai.
     */
    public function getExplanation(): string
    {
        return __('widgets.revenue_dashboard.load_per_lawyer.explanation');
    }

    /** Một chuỗi số theo từng mục rời nhau: đủ ba dạng, cột là dạng gốc. */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar, ChartKind::Line, ChartKind::Pie];
    }

    public function numberTableRows(): array
    {
        $selected = RevenueFilters::fromPageFilters($this->pageFilters)->lawyerId;

        return collect($this->counts())
            ->map(fn (array $row): array => [
                'label' => $row['key'] === $selected
                    ? __('widgets.revenue_dashboard.by_person.selected', ['name' => $row['label']])
                    : $row['label'],
                'value' => (string) $row['amount'],
            ])
            ->values()
            ->all();
    }

    protected function getData(): array
    {
        $counts = $this->counts();
        $selected = RevenueFilters::fromPageFilters($this->pageFilters)->lawyerId;

        return [
            'labels' => array_column($counts, 'label'),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.load_per_lawyer.series'),
                    'data' => array_column($counts, 'amount'),
                    // Không chọn ai: MỘT màu (chuỗi, để dạng tròn tự tô mỗi lát một màu). Chọn một luật sư: tô
                    // người đó, nhạt những người khác.
                    'backgroundColor' => $selected === null
                        ? RevenueColours::PERSON_SELECTED
                        : RevenueColours::forPeople(array_column($counts, 'key'), $selected),
                ],
            ],
        ];
    }

    protected function emptyStateKey(): string
    {
        return 'widgets.revenue_dashboard.empty.load';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => MoneyChart::valueAxis()],
        ];
    }

    /** @return list<array{key: int, label: string, amount: int}> Theo luật sư (id), giảm dần theo số vụ. */
    private function counts(): array
    {
        return $this->countsCache ??= $this->computeCounts();
    }

    /** @return list<array{key: int, label: string, amount: int}> */
    private function computeCounts(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        $rows = Matter::query()
            ->listableBy($user)
            ->open()
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matter_type_id', $v))
            ->join('users', 'users.id', '=', 'matters.lead_lawyer_id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->orderBy('users.name')
            ->selectRaw('users.id as user_id, users.name as lawyer_name, count(*) as total')
            ->get();

        return $rows->map(fn ($row): array => [
            'key' => (int) $row->user_id,
            'label' => $row->lawyer_name,
            'amount' => (int) $row->total,
        ])->all();
    }
}
