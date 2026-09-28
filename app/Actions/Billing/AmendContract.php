<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\ContractNotAmendable;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use App\Support\Billing\ScheduleTotal;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ký một phụ lục hợp đồng: đổi giá trị hợp đồng VÀ lịch thu, cùng lúc, trong MỘT transaction —
 * TẦNG 3 của bất biến tổng M9. Đổi tổng mà không đổi lịch (hay ngược lại) để lại một hợp đồng lệch
 * tổng, và phụ lục đó thất bại (`ContractTotalMismatch::onAmendment`) mà không ghi gì.
 *
 * **Huỷ một đợt của hợp đồng `active` chỉ đi qua đây** — không có Action `CancelInstalment`: một
 * đợt biến mất là tổng các đợt đổi, tức là một phụ lục.
 *
 * `$instalmentChanges` là danh sách thay đổi, mỗi phần tử mang `action`:
 *
 * - `['action' => 'add', ...]` — thêm một đợt, cùng các khoá và cùng luật với một dòng của
 *   `DraftContract` (`ValidatesBillingInput::instalmentAttributes()`). Đợt `on_signing` thêm bằng
 *   phụ lục đến hạn tính từ ngày ký PHỤ LỤC. Đợt `stage` gắn vào giai đoạn vụ đã đi qua thì chờ
 *   M9 Task 6 (đối chiếu hằng ngày kích hoạt một đợt thêm sau khi vụ đã qua giai đoạn đó).
 * - `['action' => 'update', 'instalment_id' => …, 'amount' => …, 'percent_basis' => …?]` — đổi số
 *   tiền của một đợt đang `pending`; không nhỏ hơn số đã thu trên đợt đó. `percent_basis` không
 *   đưa vào thì thành rỗng: phần trăm cũ không còn mô tả số tiền mới.
 * - `['action' => 'cancel', 'instalment_id' => …]` — huỷ một đợt đang `pending` chưa có khoản thu
 *   nào (chưa huỷ). Đợt vẫn nằm đó với `status = cancelled`, ra khỏi tổng.
 *
 * Các bước, tất cả trong một transaction (chỉ lần thăm dò id vụ việc chạy TRƯỚC khi nó mở — luật
 * "không đọc thường trước khoá đầu tiên" của {@see LocksBillingRows}):
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): hàng `matters`
 *     TRƯỚC (câu đầu tiên của transaction), rồi `contracts`; mọi con số cũ — kể cả
 *     `previous_total_amount` — đọc từ hàng ĐÃ KHOÁ, không từ đối tượng người gọi đưa vào (có thể
 *     cũ hơn một phụ lục vừa ký ở tab khác).
 *  2. **Quyền:** `ContractPolicy::update` qua `Gate::forUser($actor)` (SPEC §5 gom phụ lục vào
 *     `contract.manage`; `ContractAmendmentPolicy` vì thế không có `create`).
 *  3. Chỉ trên `active` (`ContractNotAmendable`).
 *  4. Lý do ≥ 20 ký tự `mb_strlen`; ngày ký là ngày hợp lệ, không ở tương lai, không trước ngày ký
 *     hợp đồng; giá trị mới từ 1 tới `Money::MAX`.
 *  5. Khoá MỌI đợt của hợp đồng (tiếp chuỗi khoá của bước 1: `instalments` sau `contracts`), rồi
 *     đọc tổng khoản thu chưa huỷ của từng đợt bằng MỘT lần đọc CÓ KHOÁ trên `payments`
 *     (`LocksBillingRows::lockedCollectedAmounts()`) — con số quyết định "huỷ được đợt không" và
 *     "sửa xuống được tới đâu" ở bước 7.
 *  6. Bản scan (nếu có) được **đọc lại và khoá bằng khoá của nó** — SAU chuỗi khoá tiền, không xen
 *     giữa (`lockForUpdate`, cùng lý do với bước 1 — đối tượng người gọi đưa vào có thể đã đổi
 *     `matter_id`/`group` từ lúc màn hình nạp nó), rồi mới hỏi có phải tài liệu nhóm D của chính
 *     vụ này không; đã bị xoá mềm giữa chừng thì cùng một câu từ chối. `document_id` ghi vào phụ
 *     lục là khoá của hàng ĐÃ ĐỌC LẠI, không phải của `$document`; phụ lục phải đổi ít nhất một thứ.
 *  7. Kiểm từng thay đổi trên các đợt đã khoá, rồi ghi tất cả bên trong
 *     `ScheduleTotal::whileAmending()` — hook tầng 2 tạm tắt cho ĐÚNG hợp đồng này, vì giữa các
 *     lần ghi tổng lệch là tất yếu. Một đợt sửa về ĐÚNG số đã thu (và số đó > 0) chuyển luôn sang
 *     `paid` — xem {@see self::plan()}.
 *  8. **Kiểm lại bất biến từ DB** sau khi ghi (`ScheduleTotal::lockedOf()` === giá trị mới — đọc CÓ
 *     KHOÁ, lại chính các đợt đã khoá ở bước 5 cộng các đợt vừa thêm). Đây là kiểm tra DUY NHẤT của
 *     tầng này — không có bản tính trước trong bộ nhớ, để không có hai định nghĩa.
 *  9. Ghi dòng `contract_amendments` (chỉ thêm; `sequence` = số lớn nhất hiện có + 1, đọc CÓ KHOÁ)
 *     và `Audit::record('contract_amended', …, $actor)`.
 */
class AmendContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public const ADD = 'add';

    public const UPDATE = 'update';

    public const CANCEL = 'cancel';

    /** @param  list<array<string, mixed>>  $instalmentChanges */
    public function handle(
        User $actor,
        Contract $contract,
        int $newTotalAmount,
        array $instalmentChanges,
        string $reason,
        DateTimeInterface|string $signedAt,
        ?Document $document = null,
    ): ContractAmendment {
        return $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor, $newTotalAmount, $instalmentChanges, $reason, $signedAt, $document): ContractAmendment {
            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Active) {
                throw ContractNotAmendable::make($locked);
            }

            $reason = $this->validatedReason($reason);
            $signedOn = $this->validatedPastDate($signedAt, 'signed_at');

            if ($signedOn->lt($locked->signed_at)) {
                throw ValidationException::withMessages([
                    'signed_at' => [__('billing.validation.amendment_signed_before_contract', ['date' => $locked->signed_at->format('d/m/Y')])],
                ]);
            }

            $newTotal = $this->validatedAmount($newTotalAmount, 'new_total_amount');

            // Đợt và khoản thu của chúng khoá TRƯỚC bản scan: `instalments` → `payments` thuộc chuỗi
            // khoá tiền, `documents` khoá SAU chuỗi đó (LocksBillingRows). Tổng đã thu của mọi đợt
            // đọc MỘT lần, CÓ KHOÁ — con số quyết định "huỷ được không"/"sửa xuống được tới đâu"
            // không bao giờ đọc từ ảnh chụp của transaction (lượt sửa thứ hai, N1).
            $instalments = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $collected = $this->lockedCollectedAmounts($instalments->keys()->all());

            $lockedDocument = null;

            if ($document !== null) {
                $lockedDocument = $this->scopelessly(Document::query())
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedDocument === null || $lockedDocument->matter_id !== $locked->matter_id || $lockedDocument->group !== DocumentGroup::Internal) {
                    throw ValidationException::withMessages(['document_id' => [__('billing.validation.document_not_eligible')]]);
                }
            }

            if ($instalmentChanges === [] && $newTotal === $locked->total_amount) {
                throw ValidationException::withMessages(['instalment_changes' => [__('billing.validation.amendment_changes_nothing')]]);
            }

            $plan = $this->plan($locked, $instalments, $collected, $instalmentChanges);
            $previousTotal = $locked->total_amount;

            ScheduleTotal::whileAmending($locked, function () use ($actor, $locked, $instalments, $plan, $signedOn, $newTotal): void {
                foreach ($plan['update'] as $id => $attributes) {
                    $instalments[$id]->fill($attributes)->blameOn($actor)->save();
                }

                foreach ($plan['cancel'] as $id) {
                    $instalments[$id]->fill(['status' => InstalmentStatus::Cancelled])->blameOn($actor)->save();
                }

                $sequence = (int) $instalments->max('sequence');

                foreach ($plan['add'] as $attributes) {
                    $added = new Instalment([
                        ...$attributes,
                        'contract_id' => $locked->id,
                        'sequence' => ++$sequence,
                        'status' => InstalmentStatus::Pending,
                    ]);

                    if ($added->trigger_type === InstalmentTrigger::OnSigning) {
                        $added->due_date = $signedOn->copy()->addDays($added->due_days_after_trigger)->toDateString();
                        $added->triggered_at = now();
                    }

                    $added->blameOn($actor)->save();
                }

                $locked->total_amount = $newTotal;
                $locked->blameOn($actor)->save();
            });

            $scheduleTotal = ScheduleTotal::lockedOf($locked->id);

            if ($scheduleTotal !== $newTotal) {
                throw ContractTotalMismatch::onAmendment($locked, $newTotal, $scheduleTotal);
            }

            $amendment = new ContractAmendment([
                'contract_id' => $locked->id,
                'sequence' => (int) $this->scopelessly(ContractAmendment::query())->where('contract_id', $locked->id)->lockForUpdate()->max('sequence') + 1,
                'previous_total_amount' => $previousTotal,
                'new_total_amount' => $newTotal,
                'reason' => $reason,
                'signed_at' => $signedOn->toDateString(),
                'document_id' => $lockedDocument?->id,
            ]);
            $amendment->blameOn($actor)->save();

            Audit::record('contract_amended', $locked, [
                'code' => $locked->code,
                'amendment_id' => $amendment->id,
                'sequence' => $amendment->sequence,
                'previous_total_amount' => $previousTotal,
                'new_total_amount' => $newTotal,
                'added' => count($plan['add']),
                'updated' => array_keys($plan['update']),
                'cancelled' => $plan['cancel'],
            ], $actor);

            return $amendment;
        });
    }

    /**
     * Kiểm từng thay đổi trên các đợt ĐÃ KHOÁ, trả về kế hoạch ghi. Chưa ghi gì ở đây, và không
     * đọc gì thêm từ DB: `$collectedByInstalment` là tổng khoản thu chưa huỷ của từng đợt, đã đọc
     * CÓ KHOÁ ở `handle()` (`LocksBillingRows::lockedCollectedAmounts()`; đợt chưa có khoản thu nào
     * vắng mặt).
     *
     * @param  Collection<int, Instalment>  $instalments
     * @param  array<int, int>  $collectedByInstalment
     * @param  list<array<string, mixed>>  $changes
     * @return array{add: list<array<string, mixed>>, update: array<int, array<string, mixed>>, cancel: list<int>}
     */
    private function plan(Contract $contract, Collection $instalments, array $collectedByInstalment, array $changes): array
    {
        $plan = ['add' => [], 'update' => [], 'cancel' => []];
        $touched = [];

        foreach (array_values($changes) as $index => $change) {
            $prefix = "instalment_changes.{$index}";
            $action = $change['action'] ?? null;

            if ($action === self::ADD) {
                // `$contract->matter` là hàng `matters` ĐÃ KHOÁ ở bước 1 (LocksBillingRows gắn sẵn).
                $plan['add'][] = $this->instalmentAttributes($contract->matter, $change, $prefix);

                continue;
            }

            if ($action !== self::UPDATE && $action !== self::CANCEL) {
                throw ValidationException::withMessages(["{$prefix}.action" => [__('billing.validation.change_action_invalid')]]);
            }

            $instalment = $instalments->get($change['instalment_id'] ?? null);

            if ($instalment === null) {
                throw ValidationException::withMessages(["{$prefix}.instalment_id" => [__('billing.validation.instalment_not_in_contract')]]);
            }

            if (isset($touched[$instalment->id])) {
                throw ValidationException::withMessages(["{$prefix}.instalment_id" => [__('billing.validation.instalment_changed_twice')]]);
            }

            $touched[$instalment->id] = true;

            if ($instalment->status !== InstalmentStatus::Pending) {
                throw ValidationException::withMessages([
                    "{$prefix}.instalment_id" => [__('billing.validation.instalment_not_pending', ['name' => $instalment->name])],
                ]);
            }

            $collected = $collectedByInstalment[$instalment->id] ?? 0;

            if ($action === self::CANCEL) {
                if ($collected > 0) {
                    throw ValidationException::withMessages([
                        "{$prefix}.instalment_id" => [__('billing.validation.instalment_has_payments', [
                            'name' => $instalment->name,
                            'collected' => Money::format($collected),
                        ])],
                    ]);
                }

                $plan['cancel'][] = $instalment->id;

                continue;
            }

            $amount = $this->validatedAmount($change['amount'] ?? null, "{$prefix}.amount");

            if ($amount < $collected) {
                throw ValidationException::withMessages([
                    "{$prefix}.amount" => [__('billing.validation.instalment_below_collected', [
                        'name' => $instalment->name,
                        'collected' => Money::format($collected),
                    ])],
                ]);
            }

            $plan['update'][$instalment->id] = [
                'amount' => $amount,
                'percent_basis' => $this->validatedPercentBasis($change['percent_basis'] ?? null, "{$prefix}.percent_basis"),
            ];

            // Lượt rà soát cuối M9, M2: sửa về ĐÚNG số đã thu là đã thu đủ — `paid` ngay, cùng
            // cách `RecordPayment` bước 8 đổi `status` khi tiền về đủ. "Đã thu > 0" không cần viết
            // thêm: `validatedAmount()` đòi `$amount` ≥ 1, nên `$amount === $collected` đã kéo theo
            // `$collected` ≥ 1 — một đợt chưa thu đồng nào không bao giờ tới được nhánh này.
            if ($amount === $collected) {
                $plan['update'][$instalment->id]['status'] = InstalmentStatus::Paid;
            }
        }

        return $plan;
    }
}
