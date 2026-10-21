<?php

namespace App\Support\Billing;

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NGƯỜI trên trang Doanh thu — ai được đưa vào hai ô chọn "Luật sư phụ trách" / "Nhân viên kinh doanh"
 * (`RevenueDashboard::filtersForm()`), và tiền đã thu trong kỳ của từng người cho biểu đồ "Doanh thu theo
 * người" (`RevenueByPersonWidget`, 2026-10-21). Mọi tập hồ sơ đi qua `Matter::scopeListableBy($viewer)` (P3):
 * không cái tên nào chỉ dính vào một vụ `restricted` tới tay người không thấy vụ đó (SPEC §10.10 — chọn tên đó
 * rồi mọi biểu đồ ra 0 sẽ ngầm xác nhận "có một người như vậy").
 */
final class RevenuePeople
{
    public const LAWYER = 'lawyer';

    public const SALES = 'sales';

    /**
     * Ô "Luật sư phụ trách": luật sư phụ trách ít nhất một vụ người xem liệt kê được, hoặc được ghi doanh thu
     * trên một khoản thu chưa huỷ của một vụ người xem liệt kê được (kể cả vụ đã huỷ — cùng tập khoản thu với
     * {@see CollectedRevenue}). Chuyển nguyên văn từ `RevenueDashboard::lawyerOptions()` (M9 Task 13, làn fa3).
     *
     * @return array<int, string>
     */
    public static function lawyerOptions(User $viewer): array
    {
        return User::query()
            ->where(fn ($q) => $q
                ->whereHas('leadMatters', fn ($matters) => $matters->listableBy($viewer))
                ->orWhereHas('attributedPayments', fn ($payments) => $payments
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->whereNull('voided_at')
                    ->whereHas('instalment.contract.matter', fn ($matters) => $matters->withTrashed()->listableBy($viewer))))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Ô "Nhân viên kinh doanh" (2026-10-21): (a) người có vai Kinh doanh đang hoạt động, và (b) người — kể cả
     * đã nghỉ, kể cả tài khoản đã xoá mềm — từng là người được giao một bản ghi tiếp nhận đã thành hồ sơ CÓ TIỀN
     * (hồ sơ có một hợp đồng đã ký — bất kỳ trạng thái nào trừ bản nháp; một bản nháp chưa phải tiền) mà người xem
     * liệt kê được ({@see SalesAttribution}). Vế (b) giữ được tiền người cũ đã
     * đem về; nó đi qua `listableBy()` (kể cả hồ sơ đã huỷ, như {@see CollectedRevenue}) nên một người chỉ đem về
     * một vụ `restricted` không hiện với người không thấy vụ đó. Vế (a) chỉ là danh sách nhân sự đang làm.
     *
     * @return array<int, string>
     */
    public static function salesOptions(User $viewer): array
    {
        $broughtIn = DB::table('intake_requests')
            ->whereNotNull('intake_requests.assigned_to')
            ->whereIn('intake_requests.matter_id', Matter::query()
                ->withTrashed()
                ->listableBy($viewer)
                ->whereHas('contracts', fn (Builder $contracts) => $contracts
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->where('contracts.status', '!=', ContractStatus::Draft->value))
                ->select('matters.id'))
            ->select('intake_requests.assigned_to');

        return User::query()
            ->withTrashed()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $active) => $active
                    ->whereNull('users.deleted_at')
                    ->where('users.is_active', true)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('name', Role::Sales->value)))
                ->orWhereIn('users.id', $broughtIn))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Tiền ĐÃ THU trong kỳ của từng người — {@see CollectedRevenue::filtered()} với kỳ và lĩnh vực của trang,
     * KHÔNG hai bộ lọc người ({@see RevenueFilters::withoutPeople()}: biểu đồ so sánh hiện mọi người), gộp theo:
     *
     *  - `lawyer`: `payments.attributed_lawyer_id` — luật sư phụ trách LÚC tiền về (P2 của M9, cột không null);
     *  - `sales`: nhân viên kinh doanh đã đem hồ sơ của khoản thu về ({@see SalesAttribution::salesIdOfPayment()});
     *    `null` = hồ sơ không qua nhân viên kinh doanh nào.
     *
     * Cùng một truy vấn khoản thu với ô "Đã thu" và biểu đồ xu hướng, nên tổng các dòng BẰNG ô "Đã thu" khi
     * trang không chọn người (`RevenueByPersonTest` ghim, cả SQLite lẫn MariaDB). Chưa sắp xếp.
     *
     * @return array<int|string, int> id người (hay `''` cho "không thuộc ai") => số đồng
     */
    public static function collectedPerPerson(User $viewer, RevenueFilters $filters, string $dimension): array
    {
        $payments = CollectedRevenue::filtered($viewer, $filters->withoutPeople(), $filters->bounds())
            ->select('payments.amount');

        if ($dimension === self::SALES) {
            $payments->selectSub(SalesAttribution::salesIdOfPayment(), 'person_id');
        } else {
            $payments->addSelect('payments.attributed_lawyer_id as person_id');
        }

        return DB::query()
            ->fromSub($payments, 'collected')
            ->groupBy('collected.person_id')
            ->selectRaw('collected.person_id as person_id, sum(collected.amount) as total')
            ->get()
            ->mapWithKeys(fn (object $row): array => [($row->person_id === null ? '' : (int) $row->person_id) => (int) $row->total])
            ->all();
    }
}
