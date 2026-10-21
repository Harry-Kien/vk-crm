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
 *    nên trưởng phòng không thấy tiền của vụ `restricted`) — **kể cả vụ đã huỷ (xoá mềm)**, làn fa3
 *    (2026-10-16, mục 1, phán quyết controller): tiền đã về là sự thật kế toán. Từ làn fa3 `CancelMatter`
 *    không còn huỷ được hồ sơ có khoản thu chưa huỷ; hồ sơ đã huỷ TRƯỚC luật đó mà còn khoản thu vẫn được
 *    đếm ở "Đã thu", biểu đồ theo tháng và P7. `withTrashed()` chỉ gỡ `SoftDeletingScope`; mọi nhánh của
 *    `listableBy()` (vụ hạn chế, đội ngũ, `matter.viewAny`) giữ nguyên.
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
            ->whereHas('instalment.contract.matter', fn (Builder $matters): Builder => $matters->withTrashed()->listableBy($viewer));
    }

    /**
     * {@see self::query()} cộng HAI bộ lọc của trang Doanh thu — luật sư theo `attributed_lawyer_id`
     * ("luật sư phụ trách LÚC THU", P2 của M9) và lĩnh vực của vụ — trong khoảng `$bounds` (kỳ đang xem,
     * hay kỳ so sánh của {@see RevenueFilters::previousPeriod()}). MỘT chỗ cho biểu đồ "Doanh thu theo
     * tháng" (`RevenueOverTimeWidget`) và ô "Đã thu" đầu trang (`RevenueKpiWidget`, làn rv) — trước làn rv
     * hai điều kiện này nằm trong widget; tách nguyên văn ra đây để ô số không viết lại chúng.
     *
     * Một khoản thu thuộc đúng một vụ, nên `whereHas` lĩnh vực thứ hai tương đương với điều kiện nằm trong
     * cùng closure `listableBy` — và cũng `withTrashed()` như closure đó (làn fa3), để lọc theo lĩnh vực
     * không bỏ lại tiền của vụ đã huỷ.
     *
     * Bộ lọc thứ ba (2026-10-21): nhân viên kinh doanh — khoản thu của hồ sơ người đó đem về từ tiếp nhận
     * ({@see SalesAttribution}), cùng `withTrashed()` như lĩnh vực. Chọn cả luật sư lẫn kinh doanh là giao.
     *
     * @param  array{0: string, 1: string}  $bounds
     * @return Builder<Payment>
     */
    public static function filtered(User $viewer, RevenueFilters $filters, array $bounds): Builder
    {
        return self::query($viewer, $bounds)
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('attributed_lawyer_id', $v))
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q
                ->whereHas('instalment.contract.matter', fn (Builder $mq) => $mq->withTrashed()->where('matter_type_id', $v)))
            ->when($filters->salesId, fn (Builder $q, int $v) => $q
                ->whereHas('instalment.contract.matter', fn (Builder $mq) => SalesAttribution::constrainMatters($mq->withTrashed(), $v)));
    }
}
