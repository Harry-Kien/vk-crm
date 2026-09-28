<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
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
 * Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters` TRƯỚC (câu
 * đầu tiên của transaction — lần thăm dò id vụ việc chạy trước khi nó mở), rồi `contracts`, rồi
 * mọi hàng `instalments` của hợp đồng, rồi các khoản thu của chúng; đọc lại từ hàng đã khoá;
 * `ContractPolicy::update` qua `Gate::forUser($actor)`; `Audit::record(..., $actor)` bên trong
 * transaction.
 *
 * **Tổng đã thu mà `state()` dùng để nói "đã thu đủ" đọc bằng MỘT lần đọc CÓ KHOÁ**
 * (`LocksBillingRows::lockedCollectedAmounts()`), rồi đưa cho `state()` qua thuộc tính
 * `collected_amount` — đúng lối vào mà `Instalment::collectedForState()` để sẵn cho nơi gọi đã tính
 * trước. Không có nó, `state()` tự chạy một SUM() đọc thường trên ảnh chụp của transaction (lượt
 * sửa thứ hai sau rà soát cuối M9, N1). Các đợt này KHÔNG được lưu lại sau đó: `collected_amount`
 * không phải một cột.
 */
class CompleteContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;

    public function handle(User $actor, Contract $contract): Contract
    {
        return $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor): Contract {
            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractStatusConflict::notActive($locked);
            }

            $instalments = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->lockForUpdate()
                ->get();

            $collected = $this->lockedCollectedAmounts($instalments->modelKeys());

            $unsettled = $instalments
                ->each(fn (Instalment $instalment) => $instalment->setAttribute('collected_amount', $collected[$instalment->id] ?? 0))
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
