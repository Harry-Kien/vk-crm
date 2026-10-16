<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Việc đã ký trong kỳ" của trang Doanh thu — MỘT truy vấn cho biểu đồ "Cơ cấu theo lĩnh vực"
 * (`MatterMixByPracticeAreaWidget`, gộp theo lĩnh vực) và ô "Hợp đồng ký mới" đầu trang
 * (`RevenueKpiWidget`, làn rv, cộng cả kỳ và kỳ so sánh). Tách nguyên văn từ widget cơ cấu:
 *
 *  - `contracts.signed_at` trong `$bounds` (hai cận đủ giờ của {@see RevenueFilters::bounds()});
 *  - chỉ hợp đồng `active` và `completed` (lượt rà soát cuối M9, M7 — cùng quần thể phần đối chiếu của
 *    vành khuyên: hợp đồng đã huỷ không còn là "việc đã ký"; `draft` tự ra ngoài vì chưa có `signed_at`);
 *  - vụ nằm trong `Matter::listableBy($viewer)` (P3: vụ `restricted` không lọt vào số tổng hợp);
 *  - bộ lọc luật sư = luật sư phụ trách HIỆN TẠI (`matters.lead_lawyer_id`), lĩnh vực = loại của vụ.
 *
 * @see RevenueFilters
 */
final class SignedContracts
{
    /**
     * @param  array{0: string, 1: string}  $bounds
     * @return Builder<Contract>
     */
    public static function query(User $viewer, RevenueFilters $filters, array $bounds): Builder
    {
        return Contract::query()
            ->whereBetween('contracts.signed_at', $bounds)
            ->whereIn('contracts.status', [ContractStatus::Active->value, ContractStatus::Completed->value])
            ->whereHas('matter', fn (Builder $q) => $q
                ->listableBy($viewer)
                ->when($filters->lawyerId, fn (Builder $mq, int $v) => $mq->where('lead_lawyer_id', $v))
                ->when($filters->practiceAreaId, fn (Builder $mq, int $v) => $mq->where('matter_type_id', $v)));
    }
}
