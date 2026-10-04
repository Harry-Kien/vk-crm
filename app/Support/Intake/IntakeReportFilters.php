<?php

namespace App\Support\Intake;

use App\Support\Billing\RevenueFilters;
use Illuminate\Support\Carbon;

/**
 * Đọc MỘT lần bộ lọc của trang "Bức tranh đầu vào" (`IntakeReport::filtersForm()`, M10 Task 6) từ mảng
 * `$this->pageFilters` mà mỗi widget nhận qua `InteractsWithPageFilters` — cùng vai trò với
 * {@see RevenueFilters} ở trang doanh thu, để bốn widget không tự diễn dịch bộ lọc bốn lần.
 *
 * **Khoảng thời gian KHÔNG có định nghĩa thứ hai**: ba trường `period` (`this_month` mặc định,
 * `this_quarter`, `this_year`, `custom`), `date_from`, `date_to` được giao nguyên cho
 * {@see RevenueFilters::fromPageFilters()} — đúng một chỗ tính "tháng này/quý này/năm nay/tuỳ chọn"
 * cho cả hai trang báo cáo. Chỉ ba khoá đó được chuyển sang; các bộ lọc riêng của trang doanh thu
 * (luật sư, lĩnh vực, đếm theo số vụ) không có nghĩa ở đây và không lọt vào.
 *
 * `receiver_id` là **người tiếp nhận = nhân sự đã GHI bản ghi** (`intake_requests.created_by`), không
 * phải người được giao (`assigned_to`). Lớp này không biết người xem là ai — giới hạn "thấy được" là
 * việc của `IntakeRequest::scopeVisibleTo()` ở tầng truy vấn của widget.
 */
final class IntakeReportFilters
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $receiverId,
        private readonly RevenueFilters $period,
    ) {}

    /** @param  array<string, mixed>|null  $pageFilters */
    public static function fromPageFilters(?array $pageFilters): self
    {
        $pageFilters ??= [];

        $period = RevenueFilters::fromPageFilters([
            'period' => $pageFilters['period'] ?? null,
            'date_from' => $pageFilters['date_from'] ?? null,
            'date_to' => $pageFilters['date_to'] ?? null,
        ]);

        return new self(
            from: $period->from,
            to: $period->to,
            receiverId: filled($pageFilters['receiver_id'] ?? null) ? (int) $pageFilters['receiver_id'] : null,
            period: $period,
        );
    }

    /** Nhãn tiếng Việt của khoảng ngày đang lọc ("01/10/2026 – 31/10/2026"), để mỗi widget in lên chính nó. */
    public function rangeLabel(): string
    {
        return $this->period->rangeLabel();
    }
}
