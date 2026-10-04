<?php

namespace App\Support\Billing;

use App\Models\Payment;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Tiền đã thu" trong một khoảng ngày, theo đúng tầm nhìn của một người xem — MỘT truy vấn cho trang
 * Doanh thu (`RevenueOverTimeWidget`) và cột P7 "Doanh thu đã thu" của M13.
 *
 * **Tách NGUYÊN VĂN từ `RevenueOverTimeWidget::computeBuckets()` (M13 Task 2), không viết lại.** Cùng
 * bốn điều kiện mà widget đã chạy từ M9:
 *  - bỏ `ClientPortalScope` (con số không phụ thuộc phiên khách nào đang mở);
 *  - khoản thu chưa huỷ (`voided_at` rỗng);
 *  - `paid_on` trong `$bounds` — hai cận ĐỦ GIỜ của `RevenueFilters::bounds()` / `PerformancePeriod::bounds()`
 *    (cột `date` trên SQLite lưu `Y-m-d 00:00:00`; cận trên ngày trần làm rơi tiền về đúng ngày cuối kỳ,
 *    bài học M9 Task 13);
 *  - vụ của khoản thu nằm trong `Matter::listableBy($viewer)` (P3 của M9: tầm nhìn áp cả cho số tổng hợp,
 *    nên trưởng phòng không thấy tiền của vụ `restricted`).
 *
 * Bộ lọc luật sư (`attributed_lawyer_id`, P2 của M9: "luật sư phụ trách LÚC THU") và lĩnh vực của trang
 * Doanh thu vẫn nằm ở widget, gắn THÊM vào truy vấn trả về; P7 của M13 quy về người bằng `GROUP BY` cùng
 * cột đó. `SingleSourceParityTest` ghim: tổng của `query()` bằng tổng các cột của widget cho cùng kỳ và
 * cùng người xem, kể cả khoản thu vào đúng ngày cuối kỳ, trên SQLite và MariaDB.
 */
final class CollectedRevenue
{
    /**
     * @param  array{0: string, 1: string}  $bounds
     * @return Builder<Payment>
     */
    public static function query(User $viewer, array $bounds): Builder
    {
        return Payment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('voided_at')
            ->whereBetween('paid_on', $bounds)
            ->whereHas('instalment.contract.matter', fn (Builder $matters): Builder => $matters->listableBy($viewer));
    }
}
