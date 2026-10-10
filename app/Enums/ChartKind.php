<?php

namespace App\Enums;

use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;

/**
 * Dạng mà người xem chọn cho một biểu đồ trên bảng điều khiển (yêu cầu của chủ văn phòng, 2026-10-10):
 * cột, đường hay tròn. Giá trị là thứ được lưu ở `chart_preferences.chart_kind` và là giá trị của ô
 * `<select>` trên widget — KHÔNG phải lúc nào cũng là kiểu Chart.js: "tròn" của widget công nợ vẽ bằng
 * `doughnut` ({@see HasSwitchableChartKind::pieChartType()}).
 */
enum ChartKind: string
{
    case Bar = 'bar';
    case Line = 'line';
    case Pie = 'pie';

    public function label(): string
    {
        return __('enums.chart_kind.'.$this->value);
    }
}
