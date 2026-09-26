<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\InstalmentStatus;
use App\Exceptions\PaymentAlreadyVoided;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
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
 * **Thứ tự khoá bảng — CÙNG hình dạng với `RecordPayment` (hợp đồng, rồi đợt, rồi khoản thu),
 * KHÔNG phải thứ tự "tự nhiên" (khoản thu → đợt → hợp đồng, đi ngược quan hệ `belongsTo`).** Nếu
 * khoá ngược, một `VoidPayment` và một `RecordPayment` chạy đồng thời trên cùng đợt có thể khoá
 * chéo nhau (A giữ hợp đồng chờ đợt, B giữ khoản thu... không, B giữ đợt chờ hợp đồng do A đang
 * giữ) — deadlock. Hai lần đọc ĐẦU (`$probe*`) KHÔNG khoá, chỉ để biết id hợp đồng và id đợt của
 * đúng khoản thu này trước khi khoá theo đúng thứ tự đó; an toàn vì `payments.instalment_id` và
 * `instalments.contract_id` không Action nào đổi sau khi tạo.
 *
 * Các bước:
 *  1. Đọc (không khoá) khoản thu và đợt của nó để biết id hợp đồng/đợt cần khoá.
 *  2. Khoá `contracts`, rồi `instalments`, rồi `payments` — đúng hàng, đọc lại từ hàng đã khoá.
 *  3. **Quyền:** `PaymentPolicy::void` qua `Gate::forUser($actor)`, trên khoản thu ĐÃ KHOÁ.
 *  4. Đã huỷ từ trước thì từ chối ({@see PaymentAlreadyVoided}) — `PaymentPolicy::void()` cố ý
 *     không hỏi câu này (docblock `ContractPolicy`), nên đây là chốt chặn DUY NHẤT.
 *  5. Lý do ≥ 20 ký tự `mb_strlen`.
 *  6. Ghi `voided_at`/`voided_by`/`void_reason`. Tính lại tổng khoản thu CHƯA HUỶ (đã loại dòng vừa
 *     huỷ) dưới khoá của bước 2; tụt dưới `amount` VÀ đợt đang `paid` thì hạ về `pending`.
 *  7. `Audit::record('payment_voided', …, $actor)` bên trong transaction.
 */
class VoidPayment
{
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($actor, $payment, $reason): Payment {
            $probePayment = $this->scopelessly(Payment::query())->whereKey($payment->getKey())->firstOrFail();
            $probeInstalment = $this->scopelessly(Instalment::query())->whereKey($probePayment->instalment_id)->firstOrFail();

            $this->scopelessly(Contract::query())->whereKey($probeInstalment->contract_id)->lockForUpdate()->firstOrFail();
            $lockedInstalment = $this->scopelessly(Instalment::query())->whereKey($probeInstalment->getKey())->lockForUpdate()->firstOrFail();
            $lockedPayment = $this->scopelessly(Payment::query())->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('void', $lockedPayment);

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

            $collected = (int) $this->scopelessly(Payment::query())
                ->where('instalment_id', $lockedInstalment->id)
                ->whereNull('voided_at')
                ->sum('amount');

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
