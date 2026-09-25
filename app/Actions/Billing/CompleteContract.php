<?php

namespace App\Actions\Billing;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Hoàn tất một hợp đồng: `active` → `completed`, `ended_at = hôm nay` (M9 Task 4).
 *
 * **Chỉ khi mọi đợt đã xong** — `Instalment::state()` (chỗ DUY NHẤT suy ra trạng thái hiển thị)
 * là `paid`, `waived` hoặc `cancelled`. Lý do: công nợ, nhắc quá hạn và chặn xoá vụ còn nợ (M9 Task
 * 5, 8, 9, 11) đọc hợp đồng `active`; hoàn tất một hợp đồng còn tiền chưa thu sẽ làm khoản nợ đó
 * biến khỏi mọi màn hình mà không ai quyết định miễn nó. Đường đi qua là thu nốt, hoặc miễn tường
 * minh kèm lý do (`WaiveInstalment`, Task 5).
 *
 * Khoá hàng `contracts`, đọc lại từ hàng đã khoá; `ContractPolicy::update` qua
 * `Gate::forUser($actor)`; `Audit::record(..., $actor)` bên trong transaction.
 */
class CompleteContract
{
    use ReadsWithoutPortalScope;

    public function handle(User $actor, Contract $contract): Contract
    {
        return DB::transaction(function () use ($actor, $contract): Contract {
            $locked = $this->scopelessly(Contract::query())->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractStatusConflict::notActive($locked);
            }

            $unsettled = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->lockForUpdate()
                ->get()
                ->reject(fn (Instalment $instalment) => in_array($instalment->state(), [
                    InstalmentState::Paid, InstalmentState::Waived, InstalmentState::Cancelled,
                ], true));

            if ($unsettled->isNotEmpty()) {
                throw ContractStatusConflict::hasUnsettled($locked, $unsettled->count());
            }

            $locked->fill(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()]);
            $locked->blameOn($actor)->save();

            Audit::record('contract_completed', $locked, ['code' => $locked->code], $actor);

            return $locked->refresh();
        });
    }
}
