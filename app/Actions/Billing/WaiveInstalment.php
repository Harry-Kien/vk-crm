<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\InstalmentNotPayable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\ScheduleTotal;
use Illuminate\Support\Facades\Gate;

/**
 * Miễn một đợt thanh toán còn `pending` (M9 Task 5): bớt số khách phải trả trên một hợp đồng
 * KHÔNG đổi `total_amount` — khác một phụ lục (`AmendContract`), vốn đổi cả giá trị hợp đồng lẫn
 * lịch thu cùng lúc. Miễn chỉ đổi MỘT đợt, và tiền vẫn tính đủ trong bất biến tổng M9
 * ({@see ScheduleTotal::counted()}: `waived` VẪN tính, chỉ `cancelled` ra
 * khỏi tổng) — "còn phải thu" mới là chỗ trừ phần miễn ({@see Instalment::outstanding()},
 * {@see BillingSummary}).
 *
 * Lý do ≥ 20 ký tự `mb_strlen` (`ValidatesBillingInput::validatedReason()`), nội bộ, không bao giờ
 * ra portal (`HidesInternalAttributesFromPortal` trên `Instalment`).
 *
 * Các bước, trong MỘT transaction (lần thăm dò id vụ việc/hợp đồng chạy TRƯỚC khi nó mở):
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters` TRƯỚC
 *     (câu đầu tiên của transaction), rồi `contracts`, rồi đúng hàng `instalments` này.
 *  2. **Quyền:** `InstalmentPolicy::waive` qua `Gate::forUser($actor)` — đi theo `contract.manage`
 *     (quyết định thương mại), KHÔNG theo `payment.record` (xem docblock policy).
 *  3. Hợp đồng phải `active` ({@see InstalmentNotPayable::contractNotActive()}) và đợt phải
 *     `pending` ({@see InstalmentNotPayable::toWaive()}) — cùng lý do tách hai điều kiện với
 *     `RecordPayment`: dữ liệu CÓ THỂ mang một đợt `pending` trên một hợp đồng đã đóng.
 *  4. Ghi `status = waived`, `waived_reason`, `waived_by`, `waived_at`.
 *  5. `Audit::record('instalment_waived', …, $actor)` bên trong transaction.
 */
class WaiveInstalment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Instalment $instalment, string $reason): Instalment
    {
        return $this->inInstalmentTransaction((int) $instalment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment) use ($actor, $reason): Instalment {
            Gate::forUser($actor)->authorize('waive', $lockedInstalment);

            if ($lockedContract->status !== ContractStatus::Active) {
                throw InstalmentNotPayable::contractNotActive($lockedInstalment);
            }

            if ($lockedInstalment->status !== InstalmentStatus::Pending) {
                throw InstalmentNotPayable::toWaive($lockedInstalment);
            }

            $reason = $this->validatedReason($reason);

            $lockedInstalment->fill([
                'status' => InstalmentStatus::Waived,
                'waived_reason' => $reason,
                'waived_by' => $actor->id,
                'waived_at' => now(),
            ]);
            $lockedInstalment->blameOn($actor)->save();

            Audit::record('instalment_waived', $lockedInstalment, [
                'contract_code' => $lockedContract->code,
                'amount' => $lockedInstalment->amount,
                'reason' => $reason,
            ], $actor);

            return $lockedInstalment->refresh();
        });
    }
}
