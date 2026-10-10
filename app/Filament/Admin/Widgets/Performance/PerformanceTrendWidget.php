<?php

namespace App\Filament\Admin\Widgets\Performance;

use App\Actions\Performance\BuildPerformanceTrend;
use App\Enums\ChartKind;
use App\Filament\Admin\Pages\TeamMember;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Filament\Admin\Widgets\Performance\Concerns\AuthorizesPerformanceSubject;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Support\Performance\PerformancePeriod;
use App\Support\Performance\Ratio;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phần chung của hai biểu đồ xu hướng trên trang của một người (M13 Task 7, P8): {@see StaleTrendWidget}
 * (N4) và {@see OverdueTrendWidget} (N5). Dữ liệu là {@see BuildPerformanceTrend::handle()} trên 90 ngày kết
 * thúc hôm qua ({@see BuildPerformanceTrend::MEMBER_PAGE_DAYS}, `PerformancePeriod::trailingDays()`) — trang
 * không có ô chọn kỳ, nên không để người cài tự chọn.
 *
 * Quy cách biểu đồ của M9 Task 9 và phán quyết CSP M8 R4: một chuỗi, một màu `#4a73bd`, không chú giải,
 * MỘT trục y; view dùng chung `filament.admin.widgets.revenue.chart-with-table` với bảng số đi kèm
 * ({@see HasMoneyNumberTable}), tuỳ chọn là mảng PHP thuần, không JS mới. Ngày không có ảnh chụp là `null`
 * trong chuỗi (Chart.js để trống, không nối qua) và "—" trong bảng số — không bao giờ 0.
 *
 * Kiểm quyền ở trait {@see AuthorizesPerformanceSubject} (mount và boot, 404). Không thăm dò
 * (`$pollingInterval = null`, không kế thừa `'5s'` của `CanPoll`): mỗi lần thăm dò là một lần chạy lại
 * `BuildPerformanceTrend`, mà ảnh chụp chỉ đổi một lần mỗi đêm. Không có trên trang chủ
 * (`$isDiscovered = false`); chỉ {@see TeamMember::getFooterWidgets()} đặt nó.
 */
abstract class PerformanceTrendWidget extends ChartWidget
{
    use AuthorizesPerformanceSubject;
    use HasMoneyNumberTable;
    use HasSwitchableChartKind;

    /** Màu duy nhất của mọi biểu đồ một chuỗi (quy cách biểu đồ M9). */
    public const SERIES_COLOUR = '#4a73bd';

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** @var array{dates: list<string>, stale: list<int|null>, overdue: list<int|null>, checklist: list<?Ratio>}|null bộ nhớ đệm TRONG một request */
    private ?array $trend = null;

    /** Khoá chuỗi trong kết quả của {@see BuildPerformanceTrend::handle()}: `stale` hoặc `overdue`. */
    abstract protected function series(): string;

    /** Khoá `performance.trend.*` của tên chuỗi. */
    abstract protected function seriesLabel(): string;

    /** Biểu đồ có áp dụng cho người này không (R6, theo quyền); không thì widget in "Không áp dụng". */
    abstract protected function appliesToSubject(): bool;

    /** Chữ trong bảng số của ngày thứ `$index` (ngày đó CÓ ảnh chụp). */
    abstract protected function tableValue(int $index): string;

    public function getDescription(): string|Htmlable|null
    {
        return __('performance.trend.description', ['period' => self::period()->label()]);
    }

    /** "Không áp dụng" — trạng thái rỗng chỉ xảy ra khi biểu đồ không áp dụng cho người này. */
    public function getEmptyStateHeading(): string|Htmlable
    {
        return __('performance.not_applicable');
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return __('performance.explain.not_applicable');
    }

    /** Một dòng mỗi ngày; view chỉ in bảng khi biểu đồ không rỗng (tức khi biểu đồ áp dụng cho người này). */
    public function numberTableRows(): array
    {
        $trend = $this->trend();
        $rows = [];

        foreach ($trend['dates'] as $index => $date) {
            $rows[] = [
                'label' => CarbonImmutable::parse($date)->format('d/m/Y'),
                'value' => $trend[$this->series()][$index] === null ? __('performance.trend.missing') : $this->tableValue($index),
            ];
        }

        return $rows;
    }

    /**
     * Không có "tròn": các giá trị ở đây không phải các phần của một tổng (chuỗi theo thời gian, tỷ lệ phần
     * trăm, trung vị), nên một hình tròn chia lát sẽ nói sai.
     */
    protected function chartKinds(): array
    {
        return [ChartKind::Line, ChartKind::Bar];
    }

    protected function getData(): array
    {
        if (! $this->appliesToSubject()) {
            return [];
        }

        $trend = $this->trend();

        return [
            'labels' => array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('d/m'), $trend['dates']),
            'datasets' => [
                [
                    'label' => __($this->seriesLabel()),
                    'data' => $trend[$this->series()],
                    'borderColor' => self::SERIES_COLOUR,
                    'backgroundColor' => self::SERIES_COLOUR,
                    'pointRadius' => 2,
                    'spanGaps' => false,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    /** @return array{dates: list<string>, stale: list<int|null>, overdue: list<int|null>, checklist: list<?Ratio>} */
    protected function trend(): array
    {
        return $this->trend ??= app(BuildPerformanceTrend::class)->handle(
            $this->performanceViewer(),
            $this->performanceSubject(),
            self::period(),
        );
    }

    private static function period(): PerformancePeriod
    {
        return PerformancePeriod::trailingDays(BuildPerformanceTrend::MEMBER_PAGE_DAYS);
    }
}
