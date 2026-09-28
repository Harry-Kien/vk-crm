<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InstalmentNotPayable;
use App\Exceptions\PaymentExceedsInstalment;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ghi nhận một khoản tiền THẬT SỰ nhận được, cho một {@see Instalment} (M9 Task 5).
 *
 * **Thu một phần là bình thường.** Thu VƯỢT — tổng khoản thu chưa huỷ cộng khoản mới này lớn hơn
 * `amount` của đợt — bị từ chối bằng {@see PaymentExceedsInstalment}, nêu còn thiếu bao nhiêu.
 * *Không* tự rải phần dư sang đợt sau: đó là một quyết định thương mại (đường đi đúng là phụ lục,
 * `AmendContract`), không phải việc của một Action ghi tiền đã về.
 *
 * Các bước, tất cả trong MỘT transaction (chỉ lần thăm dò id vụ việc/hợp đồng chạy TRƯỚC khi
 * transaction mở — luật "không đọc thường trước khoá đầu tiên" của {@see LocksBillingRows}):
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): hàng `matters`
 *     TRƯỚC (câu đầu tiên của transaction), rồi `contracts`, rồi đúng hàng `instalments` này —
 *     không phải các hàng người gọi cầm trong tay. Tổng đã thu (bước 5) khoá `payments` sau đó;
 *     bản scan biên lai (nếu có, bước 6) khoá SAU cả chuỗi.
 *  2. **Quyền:** `PaymentPolicy::create` qua `Gate::forUser($actor)`, với NGỮ CẢNH VỤ VIỆC (hàng
 *     `matters` VỪA khoá ở bước 1) — không dùng `$matter` người gọi đưa vào, đúng lý do
 *     `ChecksBillingAccess::matterForBillingGate()` (I1): một vụ khách gọi đưa vào có thể đã đổi
 *     `confidentiality` giữa lúc màn hình nạp và lúc bấm nút.
 *  3. Hợp đồng phải `active` ({@see InstalmentNotPayable::contractNotActive()}) và đợt phải
 *     `pending` ({@see InstalmentNotPayable::toRecordPayment()}) — hai điều kiện tách biệt, vì dữ
 *     liệu CÓ THỂ mang một đợt `pending` trên một hợp đồng đã `cancelled`/`completed` (constraint
 *     (a), `CancelContract`/`CompleteContract` không chạm tới đợt).
 *  4. `amount` từ 1 tới `Money::MAX`. `paid_on` là một ngày hợp lệ, KHÔNG ở tương lai — so theo
 *     NGÀY ở múi giờ ứng dụng, cùng cách `TransitionMatterStage` bước 3 (`validatedPastDate()`).
 *  5. Thu vượt thì từ chối ({@see PaymentExceedsInstalment}), tính trên tổng khoản thu CHƯA HUỶ
 *     đọc bằng một lần đọc CÓ KHOÁ (`LocksBillingRows::lockedCollectedAmount()`, bản commit mới
 *     nhất, không phải ảnh chụp của transaction) dưới khoá của bước 1 — không tin một con số
 *     người gọi đã tính trước. Hai lần ghi đồng thời 6 triệu trên một đợt 10 triệu: lần thứ hai
 *     bị từ chối (`RecordPaymentConcurrencyTest`, MariaDB, hai kết nối).
 *  6. Bản scan biên lai (nếu có) được ĐỌC LẠI và KHOÁ bằng khoá của nó (cùng lý do bước 4 của
 *     `AmendContract`: đối tượng người gọi đưa vào có thể đã đổi `matter_id`/`group` từ lúc màn
 *     hình nạp nó), rồi mới hỏi có phải tài liệu nhóm D của ĐÚNG vụ việc này không.
 *  7. `attributed_lawyer_id` (P2) = `lead_lawyer_id` đọc từ hàng `matters` ĐÃ KHOÁ ở bước 1 —
 *     KHÔNG từ đối tượng `Matter` người gọi đưa vào, và KHÔNG BAO GIỜ đổi sau đó kể cả khi vụ việc
 *     được bàn giao (`ReassignMatter` không dời tiền đã thu).
 *  8. Ghi dòng `payments`. Tổng khoản thu chưa huỷ (tổng đọc có khoá ở bước 5 cộng dòng vừa ghi)
 *     ≥ `amount` của đợt thì `status = paid`, TRONG CÙNG transaction, dưới khoá đợt của bước 1.
 *  9. `Audit::record('payment_recorded', …, $actor)` bên trong transaction.
 */
class RecordPayment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    /** `payments.reference` là `string(100)`. */
    public const MAX_REFERENCE_LENGTH = 100;

    public function handle(
        User $actor,
        Instalment $instalment,
        int $amount,
        DateTimeInterface|string $paidOn,
        PaymentMethod $method,
        ?string $reference,
        ?Document $receipt,
        ?string $note,
    ): Payment {
        return $this->inInstalmentTransaction((int) $instalment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment) use ($actor, $amount, $paidOn, $method, $reference, $receipt, $note): Payment {
            Gate::forUser($actor)->authorize('create', [Payment::class, $lockedMatter]);

            if ($lockedContract->status !== ContractStatus::Active) {
                throw InstalmentNotPayable::contractNotActive($lockedInstalment);
            }

            if ($lockedInstalment->status !== InstalmentStatus::Pending) {
                throw InstalmentNotPayable::toRecordPayment($lockedInstalment);
            }

            $amount = $this->validatedAmount($amount, 'amount');
            $paidOn = $this->validatedPastDate($paidOn, 'paid_on');

            $collected = $this->lockedCollectedAmount($lockedInstalment->id);

            if ($collected + $amount > $lockedInstalment->amount) {
                throw PaymentExceedsInstalment::make($lockedInstalment, $amount, $collected);
            }

            $lockedReceipt = null;

            if ($receipt !== null) {
                $lockedReceipt = $this->scopelessly(Document::query())
                    ->whereKey($receipt->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedReceipt === null || $lockedReceipt->matter_id !== $lockedMatter->id || $lockedReceipt->group !== DocumentGroup::Internal) {
                    throw ValidationException::withMessages(['receipt_document_id' => [__('billing.validation.receipt_not_eligible')]]);
                }
            }

            $reference = $this->normalizedNullable($reference);

            if ($reference !== null && mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
                throw ValidationException::withMessages([
                    'reference' => [__('billing.validation.reference_too_long', ['max' => self::MAX_REFERENCE_LENGTH])],
                ]);
            }

            $payment = new Payment([
                'instalment_id' => $lockedInstalment->id,
                'amount' => $amount,
                'paid_on' => $paidOn->toDateString(),
                'method' => $method,
                'reference' => $reference,
                'receipt_document_id' => $lockedReceipt?->id,
                'attributed_lawyer_id' => $lockedMatter->lead_lawyer_id,
                'note' => $this->normalizedNullable($note),
            ]);
            $payment->blameOn($actor)->save();

            if ($collected + $amount >= $lockedInstalment->amount) {
                $lockedInstalment->fill(['status' => InstalmentStatus::Paid]);
                $lockedInstalment->blameOn($actor)->save();
            }

            Audit::record('payment_recorded', $payment, [
                'instalment_id' => $lockedInstalment->id,
                'contract_code' => $lockedContract->code,
                'amount' => $amount,
                'method' => $method->value,
                'paid_on' => $paidOn->toDateString(),
                'attributed_lawyer_id' => $lockedMatter->lead_lawyer_id,
            ], $actor);

            return $payment->refresh();
        });
    }

    /** Chuỗi rỗng hay chỉ khoảng trắng thành `null`; ngược lại trả bản đã cắt hai đầu. */
    private function normalizedNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
