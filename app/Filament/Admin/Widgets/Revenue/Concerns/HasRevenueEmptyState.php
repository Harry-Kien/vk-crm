<?php

namespace App\Filament\Admin\Widgets\Revenue\Concerns;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Trạng thái rỗng của các biểu đồ trang Doanh thu (chủ văn phòng 2026-10-21, kèm ảnh: mọi số bằng 0 mà biểu
 * đồ vẫn vẽ trục rỗng, nhãn trục lặp "0, 0, 0, 1, 1, 1").
 *
 * `ChartWidget::isEmpty()` của Filament chỉ rỗng khi `getData()` trả MẢNG RỖNG — một biểu đồ có nhãn mà mọi
 * giá trị bằng 0 (mười hai lĩnh vực đều 0, bốn quý đều 0, ba lát vành khuyên đều 0) vẫn được vẽ. Ở đây "rỗng"
 * là: chuỗi số đầu tiên (`datasets[0]`, chuỗi duy nhất của mọi biểu đồ trang này) không có giá trị nào khác 0.
 * Khi đó view `chart-with-table` (bản sao của view vendor) ẩn khung canvas, ẩn nút "Xem bảng số" và in một
 * câu trong khung: {@see self::emptyStateKey()} — câu về tiền, hay câu về số vụ cho biểu đồ đếm.
 */
trait HasRevenueEmptyState
{
    public function isEmpty(): bool
    {
        foreach ($this->getCachedData()['datasets'][0]['data'] ?? [] as $value) {
            if (is_numeric($value) && (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }

    public function getEmptyStateHeading(): string|Htmlable
    {
        return __($this->emptyStateKey());
    }

    public function getEmptyStateIcon(): string|BackedEnum|Htmlable
    {
        return Heroicon::OutlinedChartBar;
    }

    /** Khoá lang của câu rỗng — mặc định câu về tiền; biểu đồ đếm ghi đè. */
    protected function emptyStateKey(): string
    {
        return 'widgets.revenue_dashboard.empty.money';
    }
}
