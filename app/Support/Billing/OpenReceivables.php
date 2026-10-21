<?php

namespace App\Support\Billing;

use App\Models\Instalment;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Các đợt còn phải thu trong tầm nhìn của người xem, theo bộ lọc luật sư/lĩnh vực của trang Doanh thu —
 * MỘT truy vấn cho lát "còn phải thu"/"quá hạn" của vành khuyên (`ReceivablesDonutWidget`, giới hạn thêm
 * hợp đồng KÝ trong kỳ) và hai ô "Còn phải thu"/"Quá hạn" đầu trang (`RevenueKpiWidget`, làn rv: ảnh
 * chụp HÔM NAY trên mọi hợp đồng đang hiệu lực, cùng tập đợt với trang "Công nợ").
 *
 * Nền là {@see BillingSummary::pendingInstalmentsQuery()} — đúng MỘT công thức toàn dự án (đợt `pending`
 * của hợp đồng `active`, hai cột tính sẵn `collected_amount`/`outstanding_amount`); thêm `->overdue()`
 * ({@see Instalment::scopeOverdue()}) cho "quá hạn" và cộng bằng {@see BillingSummary::sumOutstanding()}.
 * Ở đây chỉ gắn phạm vi:
 *
 *  - vụ trong `Matter::listableBy($viewer)` (P3 — vụ `restricted` không lọt vào số của người không xem được);
 *  - luật sư = luật sư phụ trách HIỆN TẠI của vụ (`matters.lead_lawyer_id`, I2 của M9: tiền còn treo
 *    thuộc về người đang cầm vụ), lĩnh vực = loại của vụ;
 *  - nhân viên kinh doanh (2026-10-21) = người đã đem hồ sơ về từ tiếp nhận ({@see SalesAttribution});
 *  - `$signedBounds` (tuỳ chọn): chỉ hợp đồng có `signed_at` trong khoảng đó — "việc đã ký trong kỳ" của
 *    vành khuyên. Bỏ trống thì mọi hợp đồng.
 */
final class OpenReceivables
{
    /**
     * @param  array{0: string, 1: string}|null  $signedBounds
     * @return Builder<Instalment>
     */
    public static function query(User $viewer, RevenueFilters $filters, ?array $signedBounds = null): Builder
    {
        return BillingSummary::pendingInstalmentsQuery()
            ->whereHas('contract', fn (Builder $contract) => $contract
                ->withoutGlobalScope(ClientPortalScope::class)
                ->when($signedBounds, fn (Builder $q, array $bounds) => $q->whereBetween('signed_at', $bounds))
                ->whereHas('matter', fn (Builder $matter) => $matter
                    ->listableBy($viewer)
                    ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matter_type_id', $v))
                    ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('lead_lawyer_id', $v))
                    ->tap(fn (Builder $q) => SalesAttribution::constrainMatters($q, $filters->salesId))));
    }
}
