<?php

namespace App\Filament\Admin\Widgets\Performance;

use App\Support\Performance\TeamRoster;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Xu hướng số vụ quá hạn cập nhật cho khách (N4) của một người, 90 ngày kết thúc hôm qua (M13 Task 7, P8).
 * Bảng số đi kèm mang thêm mức hoàn thiện danh mục (X/Y của N10) của cùng ngày: khác đơn vị, nên chỉ nằm
 * trong bảng, không vẽ chung — không bao giờ hai trục y. Cả hai là cột chỉ dành cho người phụ trách vụ: với
 * người không đứng tên phụ trách vụ (`TeamRoster::leadsMatters()` sai, ví dụ trợ lý) widget in "Không áp
 * dụng" thay cho một đường số 0 (R6), theo QUYỀN, không theo "có vụ nào không".
 *
 * Phần chung (kiểm quyền, khoảng ngày, quy cách biểu đồ) ở {@see PerformanceTrendWidget}.
 */
class StaleTrendWidget extends PerformanceTrendWidget
{
    public function getHeading(): string|Htmlable|null
    {
        return __('performance.trend.stale_heading');
    }

    protected function series(): string
    {
        return 'stale';
    }

    protected function seriesLabel(): string
    {
        return 'performance.trend.stale_series';
    }

    protected function appliesToSubject(): bool
    {
        return TeamRoster::leadsMatters($this->performanceSubject());
    }

    protected function tableValue(int $index): string
    {
        $trend = $this->trend();
        $checklist = $trend['checklist'][$index];

        return __('performance.trend.stale_row', [
            'stale' => $trend['stale'][$index],
            'checklist' => "{$checklist->numerator}/{$checklist->denominator}",
        ]);
    }
}
