<?php

namespace App\Actions\Billing;

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
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use App\Support\Billing\ScheduleTotal;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 * Các bước, tất cả trong một transaction:
 *  1. Khoá hàng `contracts`; mọi con số cũ — kể cả `previous_total_amount` — đọc từ hàng ĐÃ KHOÁ,
 *     không từ đối tượng người gọi đưa vào (có thể cũ hơn một phụ lục vừa ký ở tab khác).
 *  2. **Quyền:** `ContractPolicy::update` qua `Gate::forUser($actor)` (SPEC §5 gom phụ lục vào
 *     `contract.manage`; `ContractAmendmentPolicy` vì thế không có `create`).
 *  3. Chỉ trên `active` (`ContractNotAmendable`).
 *  4. Lý do ≥ 20 ký tự `mb_strlen`; ngày ký là ngày hợp lệ, không ở tương lai, không trước ngày ký
 *     hợp đồng; giá trị mới từ 1 tới `Money::MAX`; bản scan (nếu có) được **đọc lại và khoá bằng
 *     khoá của nó** (`lockForUpdate`, cùng lý do với bước 1 — đối tượng người gọi đưa vào có thể
 *     đã đổi `matter_id`/`group` từ lúc màn hình nạp nó), rồi mới hỏi có phải tài liệu nhóm D của
 *     chính vụ này không; đã bị xoá mềm giữa chừng thì cùng một câu từ chối. `document_id` ghi vào
 *     phụ lục là khoá của hàng ĐÃ ĐỌC LẠI, không phải của `$document`; phụ lục phải đổi ít nhất một
 *     thứ.
 *  5. Khoá các đợt của hợp đồng, kiểm từng thay đổi, rồi ghi tất cả bên trong
 *     `ScheduleTotal::whileAmending()` — hook tầng 2 tạm tắt cho ĐÚNG hợp đồng này, vì giữa các
 *     lần ghi tổng lệch là tất yếu.
 *  6. **Kiểm lại bất biến từ DB** sau khi ghi (`ScheduleTotal::of()` === giá trị mới). Đây là kiểm
 *     tra DUY NHẤT của tầng này — không có bản tính trước trong bộ nhớ, để không có hai định nghĩa.
 *  7. Ghi dòng `contract_amendments` (chỉ thêm) và `Audit::record('contract_amended', …, $actor)`.
 */
class AmendContract
{
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
        return DB::transaction(function () use ($actor, $contract, $newTotalAmount, $instalmentChanges, $reason, $signedAt, $document): ContractAmendment {
            $locked = $this->scopelessly(Contract::query())->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();

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

            $instalments = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $plan = $this->plan($locked, $instalments, $instalmentChanges);
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

            $scheduleTotal = ScheduleTotal::of($locked->id);

            if ($scheduleTotal !== $newTotal) {
                throw ContractTotalMismatch::onAmendment($locked, $newTotal, $scheduleTotal);
            }

            $amendment = new ContractAmendment([
                'contract_id' => $locked->id,
                'sequence' => (int) $this->scopelessly(ContractAmendment::query())->where('contract_id', $locked->id)->max('sequence') + 1,
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
     * Kiểm từng thay đổi trên các đợt ĐÃ KHOÁ, trả về kế hoạch ghi. Chưa ghi gì ở đây.
     *
     * @param  Collection<int, Instalment>  $instalments
     * @param  list<array<string, mixed>>  $changes
     * @return array{add: list<array<string, mixed>>, update: array<int, array<string, mixed>>, cancel: list<int>}
     */
    private function plan(Contract $contract, Collection $instalments, array $changes): array
    {
        $plan = ['add' => [], 'update' => [], 'cancel' => []];
        $touched = [];
        $matter = null;

        foreach (array_values($changes) as $index => $change) {
            $prefix = "instalment_changes.{$index}";
            $action = $change['action'] ?? null;

            if ($action === self::ADD) {
                $matter ??= $this->scopelessly(Matter::query())->findOrFail($contract->matter_id);
                $plan['add'][] = $this->instalmentAttributes($matter, $change, $prefix);

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

            $collected = (int) $this->scopelessly(Payment::query())
                ->where('instalment_id', $instalment->id)
                ->whereNull('voided_at')
                ->sum('amount');

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
        }

        return $plan;
    }
}
