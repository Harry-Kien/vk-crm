<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\ChartKind;
use App\Enums\Permission;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasRevenueEmptyState;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueColours;
use App\Support\Billing\RevenueFilters;
use App\Support\Billing\RevenuePeople;
use App\Support\Billing\SalesAttribution;
use App\Support\Charts\MoneyChart;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Doanh thu theo người" (chủ văn phòng 2026-10-21: "để biết người đó đem về bao nhiêu tiền") — cột ngang, tiền
 * ĐÃ THU trong kỳ của từng người, giảm dần, kèm bảng số. Ô chọn riêng của widget (`$filter`, như "Theo tháng"
 * của biểu đồ xu hướng) chia theo:
 *
 *  - **Luật sư** — luật sư phụ trách LÚC tiền về (`payments.attributed_lawyer_id`, P2 của M9);
 *  - **Kinh doanh** — nhân viên kinh doanh đã đem hồ sơ về từ tiếp nhận ({@see SalesAttribution});
 *    tiền của hồ sơ không qua nhân viên kinh doanh nào gom vào MỘT cột xám cuối, để tổng các cột vẫn bằng ô
 *    "Đã thu".
 *
 * Số liệu: {@see RevenuePeople::collectedPerPerson()} — cùng truy vấn khoản thu với ô "Đã thu" và biểu đồ xu
 * hướng (`CollectedRevenue`), theo bộ lọc KỲ và LĨNH VỰC của trang. **Không** theo hai bộ lọc người: đây là
 * biểu đồ để SO người với người, nên khi trang đang chọn một người nó vẫn hiện mọi người và tô người đó
 * ({@see RevenueColours::forPeople()}; bảng số ghi thêm chữ "đang xem" — không chỉ bằng màu). Người đang chọn
 * chưa thu đồng nào trong kỳ vẫn có một dòng 0, nếu người đó có trong ô chọn của người xem.
 *
 * Mở ra ở "Kinh doanh" khi trang chỉ đang chọn một nhân viên kinh doanh; còn lại ở "Luật sư".
 *
 * **Chỉ người có `revenue.viewAny`** (kế toán, quản lý, admin): luật sư thường không thấy tiền của đồng nghiệp.
 * `boot()` trả 404 cả khi widget bị mount thẳng. Vụ hạn chế: tập khoản thu là của `CollectedRevenue` cho đúng
 * người xem (`listableBy`), nên tiền và tên chỉ dính vào vụ hạn chế không tới người không thấy vụ đó — tên người
 * lấy từ chính các dòng tiền đó, hoặc (người đang chọn) từ ô chọn của người xem, không bao giờ từ một id gõ tay.
 */
class RevenueByPersonWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use HasRevenueEmptyState;
    use HasSwitchableChartKind;
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array<string, list<array{key: int|null, label: string, amount: int}>> theo chế độ */
    private array $rowsCache = [];

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && Gate::forUser($user)->allows(Permission::RevenueViewAny->value);
    }

    /** Hook `boot` của Livewire: 404 cho người không qua `canView()`, kể cả khi widget bị mount thẳng. */
    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    public function mount(): void
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $this->filter = $filters->salesId !== null && $filters->lawyerId === null ? RevenuePeople::SALES : RevenuePeople::LAWYER;

        parent::mount();
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_person.heading');
    }

    /** Một dòng phụ: khoảng ngày tiền về và cách chia. Cách tính nằm ở {@see self::getExplanation()}. */
    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.by_person.description_'.$this->mode(), [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    public function getExplanation(): string
    {
        return __('widgets.revenue_dashboard.by_person.explanation_'.$this->mode(), [
            'range' => RevenueFilters::fromPageFilters($this->pageFilters)->rangeLabel(),
        ]);
    }

    protected function getFilters(): ?array
    {
        return [
            RevenuePeople::LAWYER => __('widgets.revenue_dashboard.by_person.filter_lawyer'),
            RevenuePeople::SALES => __('widgets.revenue_dashboard.by_person.filter_sales'),
        ];
    }

    /** Chỉ cột ngang: nhiều người là nhiều mục rời nhau, xếp hạng; một đường nối người với người là nói sai. */
    protected function chartKinds(): array
    {
        return [ChartKind::Bar];
    }

    public function numberTableRows(): array
    {
        $selected = $this->selectedId();

        return array_map(fn (array $row): array => [
            'label' => $selected !== null && $row['key'] === $selected
                ? __('widgets.revenue_dashboard.by_person.selected', ['name' => $row['label']])
                : $row['label'],
            'value' => Money::format($row['amount']),
        ], $this->rows());
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            // Cột ngang: tên dài ngắt dòng, không thì Chart.js cắt mất phần đầu.
            'labels' => array_map(fn (array $row): array => MoneyChart::wrapLabel($row['label']), $rows),
            'datasets' => [
                [
                    'label' => __('widgets.revenue_dashboard.by_person.series'),
                    'data' => array_column($rows, 'amount'),
                    'backgroundColor' => RevenueColours::forPeople(array_column($rows, 'key'), $this->selectedId()),
                    'maxBarThickness' => 40,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false], ...MoneyChart::pluginOptions()],
            'scales' => ['x' => MoneyChart::valueAxis()],
        ];
    }

    /** Chế độ đang chọn; giá trị lạ (Livewire nhận từ trình duyệt) rơi về luật sư. */
    private function mode(): string
    {
        return $this->filter === RevenuePeople::SALES ? RevenuePeople::SALES : RevenuePeople::LAWYER;
    }

    /** Người đang chọn ở bộ lọc trang, theo chế độ của widget. */
    private function selectedId(): ?int
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        return $this->mode() === RevenuePeople::SALES ? $filters->salesId : $filters->lawyerId;
    }

    /** @return list<array{key: int|null, label: string, amount: int}> */
    private function rows(): array
    {
        return $this->rowsCache[$this->mode()] ??= $this->computeRows();
    }

    /** @return list<array{key: int|null, label: string, amount: int}> */
    private function computeRows(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $mode = $this->mode();
        $totals = RevenuePeople::collectedPerPerson($user, RevenueFilters::fromPageFilters($this->pageFilters), $mode);
        $unattributed = $totals[''] ?? null;
        unset($totals['']);

        $names = User::query()->withTrashed()->whereKey(array_keys($totals))->pluck('name', 'id');

        $rows = [];
        foreach ($totals as $id => $amount) {
            $rows[] = ['key' => (int) $id, 'label' => (string) ($names[$id] ?? ''), 'amount' => $amount];
        }

        // Người đang chọn chưa thu đồng nào trong kỳ: một dòng 0 — chỉ khi người đó có trong ô chọn của người xem.
        $selected = $this->selectedId();
        if ($selected !== null && ! array_key_exists($selected, $totals)) {
            $options = $mode === RevenuePeople::SALES ? RevenuePeople::salesOptions($user) : RevenuePeople::lawyerOptions($user);

            if (array_key_exists($selected, $options)) {
                $rows[] = ['key' => $selected, 'label' => $options[$selected], 'amount' => 0];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['amount'] <=> $a['amount'] ?: strcmp($a['label'], $b['label']));

        if ($unattributed !== null) {
            $rows[] = ['key' => null, 'label' => __('widgets.revenue_dashboard.by_person.no_sales'), 'amount' => $unattributed];
        }

        return $rows;
    }
}
