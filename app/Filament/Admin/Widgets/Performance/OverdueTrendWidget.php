<?php

namespace App\Filament\Admin\Widgets\Performance;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Xu hướng số mốc quá hạn người này giữ (N5), 90 ngày kết thúc hôm qua (M13 Task 7, P8). Áp dụng cho mọi
 * người được theo dõi, trợ lý cũng giữ mốc (R6). Phần chung (kiểm quyền, khoảng ngày, quy cách biểu đồ) ở
 * {@see PerformanceTrendWidget}.
 */
class OverdueTrendWidget extends PerformanceTrendWidget
{
    public function getHeading(): string|Htmlable|null
    {
        return __('performance.trend.overdue_heading');
    }

    protected function series(): string
    {
        return 'overdue';
    }

    protected function seriesLabel(): string
    {
        return 'performance.trend.overdue_series';
    }

    protected function appliesToSubject(): bool
    {
        return true;
    }

    protected function tableValue(int $index): string
    {
        return __('performance.trend.overdue_row', ['overdue' => $this->trend()['overdue'][$index]]);
    }
}
