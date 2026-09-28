<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\InstalmentStatus;
use App\Exceptions\ContractStatusConflict;
use App\Exceptions\PaymentAlreadyVoided;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

/**
 * Huỷ một khoản thu ghi nhầm (M9 Task 5): `voided_at`/`voided_by`/`void_reason`, lý do ≥ 20 ký tự
 * `mb_strlen`. Dòng {@see Payment} VẪN nằm đó — hook `Payment::deleting` chặn xoá vô điều kiện,
 * đúng tinh thần `stage_logs` (chỉ thêm).
 *
 * **Hạ `status` của đợt về `pending`** khi tổng khoản thu chưa huỷ (SAU khi huỷ dòng này) tụt
 * xuống dưới `amount` của đợt — đúng nghịch đảo của bước 8 `RecordPayment`. Đợt trở lại `pending`
 * thì `state()` tự tính lại `overdue`/`due`/`partially_paid` từ `due_date` và số còn lại; không có
 * gì phải làm thêm ở đây cho hiển thị.
 *
 * **Thứ tự khoá bảng — thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters`
 * TRƯỚC, rồi `contracts` → `instalments` → `payments`,** KHÔNG phải thứ tự "tự nhiên" của một lần
 * huỷ khoản thu (khoản thu → đợt → hợp đồng, đi ngược quan hệ `belongsTo`). Khoá ngược thì một
 * `VoidPayment` và một `RecordPayment` chạy đồng thời trên cùng đợt khoá chéo nhau — deadlock. Các
 * lần đọc thăm dò đi ngược lên (không khoá) chỉ để biết vụ việc/hợp đồng/đợt nào cần khoá — xem
 * docblock trait.
 *
 * **Hợp đồng đã `completed` thì từ chối** ({@see ContractStatusConflict::voidOnCompleted()},
 * phán quyết C1 của lượt rà soát cuối M9). Mọi màn hình công nợ đọc hợp đồng `active` (constraint
 * (a), docblock `CancelContract`); huỷ một khoản thu trên hợp đồng đã hoàn tất sẽ mở lại một khoản
 * nợ mà KHÔNG màn hình nào thấy và KHÔNG ai thu được (`RecordPayment` đòi hợp đồng `active`) —
 * một khoản nợ tàng hình. Mở lại một hợp đồng đã hoàn tất là một hành động tường minh riêng của
 * milestone sau (phán quyết controller cho câu hỏi đã gác lại). Hợp đồng `active` và `cancelled`
 * vẫn huỷ được: trên `active` đợt quay về `pending` và hiện lại thành nợ đúng chỗ; trên `cancelled`
 * tiền ghi nhầm của một hợp đồng đã huỷ phải sửa được, và không gì của nó tính vào công nợ.
 *
 * Các bước:
 *  1. Khoá `matters`, `contracts`, `instalments`, rồi `payments` — đúng hàng, đọc lại từ hàng đã
 *     khoá. Lần thăm dò không khoá (đi ngược lên để biết vụ việc nào) chạy TRƯỚC khi transaction
 *     mở; câu đầu tiên của transaction là khoá `matters` (luật ở docblock trait).
 *  2. **Quyền:** `PaymentPolicy::void` qua `Gate::forUser($actor)`, trên khoản thu ĐÃ KHOÁ.
 *  3. Hợp đồng `completed` thì từ chối (xem trên) — `ContractStatus::allowsPaymentVoid()`, cùng
 *     định nghĩa nút "Huỷ khoản thu" của mục "Khoản thu gần đây" dùng để ẩn mình.
 *  4. Đã huỷ từ trước thì từ chối ({@see PaymentAlreadyVoided}) — `PaymentPolicy::void()` cố ý
 *     không hỏi câu này (docblock `ContractPolicy`), nên đây là chốt chặn DUY NHẤT.
 *  5. Lý do ≥ 20 ký tự `mb_strlen`.
 *  6. Ghi `voided_at`/`voided_by`/`void_reason`. Tính lại tổng khoản thu CHƯA HUỶ (đã loại dòng vừa
 *     huỷ) bằng một lần đọc CÓ KHOÁ (`LocksBillingRows::lockedCollectedAmount()`) dưới khoá của
 *     bước 1; tụt dưới `amount` VÀ đợt đang `paid` thì hạ về `pending`.
 *  7. `Audit::record('payment_voided', …, $actor)` bên trong transaction.
 */
class VoidPayment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Payment $payment, string $reason): Payment
    {
        return $this->inPaymentTransaction((int) $payment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment, Payment $lockedPayment) use ($actor, $reason): Payment {
            Gate::forUser($actor)->authorize('void', $lockedPayment);

            if (! $lockedContract->status->allowsPaymentVoid()) {
                throw ContractStatusConflict::voidOnCompleted();
            }

            if ($lockedPayment->voided_at !== null) {
                throw PaymentAlreadyVoided::make();
            }

            $reason = $this->validatedReason($reason);

            $lockedPayment->fill([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);
            $lockedPayment->blameOn($actor)->save();

            $collected = $this->lockedCollectedAmount($lockedInstalment->id);

            if ($collected < $lockedInstalment->amount && $lockedInstalment->status === InstalmentStatus::Paid) {
                $lockedInstalment->fill(['status' => InstalmentStatus::Pending]);
                $lockedInstalment->blameOn($actor)->save();
            }

            Audit::record('payment_voided', $lockedPayment, [
                'instalment_id' => $lockedInstalment->id,
                'amount' => $lockedPayment->amount,
                'reason' => $reason,
            ], $actor);

            return $lockedPayment->refresh();
        });
    }
}
