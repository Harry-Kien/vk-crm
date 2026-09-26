<?php

namespace App\Actions\Billing;

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
use Illuminate\Support\Facades\DB;
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
 * Các bước, tất cả trong MỘT transaction:
 *  1. Khoá hàng `contracts` (không phải hàng người gọi cầm trong tay), rồi khoá `instalments` của
 *     đúng hợp đồng đó, rồi khoá hàng `matters` — CÙNG thứ tự bảng với `AmendContract` (hợp đồng
 *     trước, đợt sau) để hai Action không bao giờ khoá ngược nhau và gây deadlock; `matters` khoá
 *     SAU CÙNG vì không Action nào khác trong `app/Actions/Billing/` khoá nó.
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
 *     đọc lại dưới khoá của bước 1 — không tin một con số người gọi đã tính trước.
 *  6. Bản scan biên lai (nếu có) được ĐỌC LẠI và KHOÁ bằng khoá của nó (cùng lý do bước 4 của
 *     `AmendContract`: đối tượng người gọi đưa vào có thể đã đổi `matter_id`/`group` từ lúc màn
 *     hình nạp nó), rồi mới hỏi có phải tài liệu nhóm D của ĐÚNG vụ việc này không.
 *  7. `attributed_lawyer_id` (P2) = `lead_lawyer_id` đọc từ hàng `matters` ĐÃ KHOÁ ở bước 1 —
 *     KHÔNG từ đối tượng `Matter` người gọi đưa vào, và KHÔNG BAO GIỜ đổi sau đó kể cả khi vụ việc
 *     được bàn giao (`ReassignMatter` không dời tiền đã thu).
 *  8. Ghi dòng `payments`. Tổng khoản thu chưa huỷ (đã cộng dòng vừa ghi) ≥ `amount` của đợt thì
 *     `status = paid`, tính lại TRONG CÙNG transaction, dưới khoá đợt của bước 1.
 *  9. `Audit::record('payment_recorded', …, $actor)` bên trong transaction.
 */
class RecordPayment
{
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
        return DB::transaction(function () use ($actor, $instalment, $amount, $paidOn, $method, $reference, $receipt, $note): Payment {
            $lockedContract = $this->scopelessly(Contract::query())->whereKey($instalment->contract_id)->lockForUpdate()->firstOrFail();
            $lockedInstalment = $this->scopelessly(Instalment::query())->whereKey($instalment->getKey())->lockForUpdate()->firstOrFail();
            $lockedMatter = $this->scopelessly(Matter::query())->whereKey($lockedContract->matter_id)->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('create', [Payment::class, $lockedMatter]);

            if ($lockedContract->status !== ContractStatus::Active) {
                throw InstalmentNotPayable::contractNotActive($lockedInstalment->setRelation('contract', $lockedContract));
            }

            if ($lockedInstalment->status !== InstalmentStatus::Pending) {
                throw InstalmentNotPayable::toRecordPayment($lockedInstalment);
            }

            $amount = $this->validatedAmount($amount, 'amount');
            $paidOn = $this->validatedPastDate($paidOn, 'paid_on');

            $collected = (int) $this->scopelessly(Payment::query())
                ->where('instalment_id', $lockedInstalment->id)
                ->whereNull('voided_at')
                ->sum('amount');

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
