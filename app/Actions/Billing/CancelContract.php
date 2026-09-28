<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
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
 * Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters` TRƯỚC (câu
 * đầu tiên của transaction — lần thăm dò id vụ việc chạy trước khi nó mở), rồi `contracts`; đọc
 * lại từ hàng đã khoá; `ContractPolicy::update` qua `Gate::forUser($actor)`;
 * `Audit::record(..., $actor)` bên trong transaction.
 */
class CancelContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Contract $contract, string $reason): Contract
    {
        return $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor, $reason): Contract {
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
