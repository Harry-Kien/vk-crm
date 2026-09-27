<?php

namespace App\Filament\Admin\Widgets\Revenue\Concerns;

/**
 * "Luôn có một bảng số đi kèm mỗi biểu đồ, mở ra được, định dạng qua `Money::format()` ở phía
 * PHP" (kế hoạch M9, hệ thống màu và quy cách biểu đồ). Năm widget dạng `ChartWidget` của trang
 * doanh thu dùng CHUNG một view (`filament.admin.widgets.revenue.chart-with-table`) render bảng
 * này, để không viết lại markup bảng năm lần — mỗi widget chỉ khai báo DỮ LIỆU của bảng.
 */
trait HasMoneyNumberTable
{
    /**
     * @return list<array{label: string, value: string}> Mỗi dòng đã định dạng SẴN bằng
     *                                                   `Money::format()` hoặc một chuỗi đếm — không có số thô nào ra view để rồi bị định
     *                                                   dạng lần hai bằng JS.
     */
    abstract public function numberTableRows(): array;
}
