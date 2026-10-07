<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildPerformanceReport;

/**
 * Kết quả của {@see BuildPerformanceReport} cho MỘT người xem, MỘT kỳ, MỘT tập người (M13 Task 6).
 *
 * - `$reference`: dòng "Chung — các vụ anh/chị được xem" (R8), chỉ khi người xem có `performance.viewAny`.
 * - `$rows`: một dòng cho mỗi người, khoá là `user_id`, cùng thứ tự với tập người đưa vào (theo tên).
 * - `$revenueVisible`: cột P7 có trên trang hay không. `false` thì Action không tính P7 chút nào, và mọi
 *   `revenueCollected` là `null` — khác "Không áp dụng" của một người không phụ trách vụ (R6).
 */
final readonly class PerformanceReport
{
    /** @param  array<int, PerformanceRow>  $rows */
    public function __construct(
        public ?PerformanceRow $reference,
        public array $rows,
        public bool $revenueVisible,
    ) {}
}
