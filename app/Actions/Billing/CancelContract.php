<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Huỷ một hợp đồng đã ký: `active` → `cancelled`, `ended_at = hôm nay`, lý do ≥ 20 ký tự
 * `mb_strlen` vào `ended_reason` (nội bộ, không bao giờ ra cổng — M9 P1).
 *
 * Chỉ từ `active`. Bản nháp không huỷ: nó xoá cứng được khi chưa có khoản thu nào (hook
 * `Contract::deleting`).
 *
 * **Không chạm tới đợt nào và khoản thu nào.** Tiền đã thu vẫn là tiền đã thu, và lịch thu là lịch
 * sử của hợp đồng đó. Hệ quả cho các task sau, ghi ở đây để chúng không quên: mọi nơi đọc công nợ
 * (M9 Task 5 `scopeOverdue`/`BillingSummary`, Task 8, 9, 11) PHẢI lọc hợp đồng `active` — nếu
 * không, các đợt còn `pending` của một hợp đồng đã huỷ sẽ hiện thành nợ quá hạn. Bất biến tổng chỉ
 * giữ trên hợp đồng `active`.
 *
 * Khoá hàng `contracts`, đọc lại từ hàng đã khoá; `ContractPolicy::update` qua
 * `Gate::forUser($actor)`; `Audit::record(..., $actor)` bên trong transaction.
 */
class CancelContract
{
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Contract $contract, string $reason): Contract
    {
        return DB::transaction(function () use ($actor, $contract, $reason): Contract {
            $locked = $this->scopelessly(Contract::query())->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractStatusConflict::notActive($locked);
            }

            $reason = $this->validatedReason($reason);

            $locked->fill([
                'status' => ContractStatus::Cancelled,
                'ended_at' => today()->toDateString(),
                'ended_reason' => $reason,
            ]);
            $locked->blameOn($actor)->save();

            Audit::record('contract_cancelled', $locked, ['code' => $locked->code], $actor);

            return $locked->refresh();
        });
    }
}
