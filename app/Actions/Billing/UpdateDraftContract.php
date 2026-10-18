<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sửa một hợp đồng còn `draft`: giá trị, thuế suất và lịch thu (M9 Task 7 — tab "Hợp đồng và
 * thanh toán" cần sửa lại một bản nháp trước khi kích hoạt, ví dụ đổi một con số gõ nhầm hay thêm
 * một đợt quên đưa vào). `DraftContract` chỉ TẠO; đây là đường DUY NHẤT sửa một hợp đồng đã tồn
 * tại nhưng chưa kích hoạt.
 *
 * **Không đổi `billing_model`.** M9 chỉ cài `fixed_fee`; sửa cách tính phí không phải việc của
 * "sửa bản nháp" — nó đổi cả bản chất hợp đồng, và không có Action nào làm chuyện đó ở M9.
 *
 * **Bản nháp không có khoản thu CHƯA HUỶ nào.** Một khoản thu chỉ ghi được khi hợp đồng `active`
 * (`RecordPayment` bước 3: `InstalmentNotPayable::contractNotActive()`), và `RevertContractToDraft` (làn
 * fa3) chỉ đưa một hợp đồng về nháp khi mọi khoản thu của nó đã huỷ. Nhưng từ làn fa3 một bản nháp CÓ THỂ
 * mang khoản thu ĐÃ HUỶ (lịch sử của lần kích hoạt nhầm) — xem đoạn sau.
 *
 * **Lịch thu được THAY TOÀN BỘ, không được vá từng dòng** — với một ngoại lệ. Bản nháp là nơi văn phòng
 * còn đang sửa (docblock `DraftContract`: "tổng các đợt KHÔNG phải khớp giá trị ở bản nháp"), nên xoá cứng
 * đợt cũ rồi tạo lại đúng những gì màn hình gửi lên. Ngoại lệ (làn fa3, 2026-10-16): đợt còn mang khoản thu
 * đã huỷ KHÔNG xoá được (khoá ngoại `payments.instalment_id` là `restrictOnDelete`, và lịch sử khoản thu
 * không được mất), nên đợt đó được GIỮ và ghi đè tại chỗ bằng dòng CÙNG VỊ TRÍ của lịch thu mới (vị trí =
 * `sequence`); lịch thu mới ngắn hơn vị trí của nó thì từ chối, không xoá gì
 * (`billing_corrections.revert.keeps_voided_history`). Hook `Instalment::deleting` vẫn tự kiểm "hợp đồng
 * còn draft không" trên từng dòng xoá.
 *
 * **Sau commit** (làn fa3, mục 5): người sửa không phải luật sư phụ trách (kế toán, trưởng phòng) thì luật
 * sư phụ trách nhận một dòng chuông ({@see NotifyLeadLawyerOfContractEntry}, `DRAFT_UPDATED`) — fix round 1 (m8):
 * CHỈ khi điều khoản tiền đổi ({@see self::moneyTerms()}: giá trị, thuế suất, số đợt, và mỗi đợt — số tiền, mốc
 * kích hoạt, giai đoạn, số ngày sau mốc, ngày đến hạn cố định). Lưu lại y nguyên, hay chỉ đổi ghi chú hoặc tên
 * đợt, không báo ai (nhật ký `contract_draft_updated` vẫn ghi mọi lần lưu).
 *
 * Các bước, tất cả trong MỘT transaction:
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): hàng `matters`
 *     TRƯỚC (câu đầu tiên của transaction — lần thăm dò id vụ việc chạy trước khi nó mở), rồi
 *     `contracts`; đọc lại cả hai từ hàng đã khoá (vụ việc đã khoá là ngữ cảnh kiểm giai đoạn kích
 *     hoạt ở bước 5).
 *  2. **Quyền:** `ContractPolicy::updateDraft` qua `Gate::forUser($actor)` — `contract.manage` hoặc
 *     `contract.enter` (làn kt, SPEC §5 bổ sung 2026-10-15: kế toán sửa được bản nháp mình nhập; trước
 *     làn kt câu này hỏi `update`, nay chỉ còn là cổng của phụ lục/hoàn tất/huỷ — docblock
 *     `ContractPolicy`).
 *  3. Chỉ trên `draft` ({@see ContractStatusConflict::notDraftForUpdate()} — câu RIÊNG với
 *     `notDraft()` của `ActivateContract`, vì đây là "sửa" không phải "kích hoạt").
 *  4. Giá trị và thuế suất kiểm bằng đúng `ValidatesBillingInput` mà `DraftContract` dùng.
 *  5. Xoá mọi đợt cũ không mang khoản thu nào (qua model, để hook `deleting` chạy), tạo lại đợt mới từ
 *     `instalmentAttributes()`, `sequence` theo thứ tự mảng từ 1 — cùng luật `DraftContract`; đợt mang
 *     khoản thu đã huỷ được ghi đè tại chỗ (đoạn trên).
 *  6. `Audit::record('contract_draft_updated', ..., $actor)` bên trong transaction.
 *
 * Mọi thứ trong một transaction; `blameOn($actor)` trước mọi `save()`. Không một dòng `Auth::`
 * nào.
 */
class UpdateDraftContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function __construct(private NotifyLeadLawyerOfContractEntry $notifier) {}

    /**
     * Sửa trong transaction tiền, rồi SAU commit báo luật sư phụ trách (docblock lớp, "Sau commit").
     *
     * @param  array{total_amount?: mixed, vat_rate_percent?: mixed, note?: string|null}  $attributes
     * @param  list<array<string, mixed>>  $instalments
     */
    public function handle(User $actor, Contract $contract, array $attributes, array $instalments): Contract
    {
        [$updated, $changed] = $this->updateInTransaction($actor, $contract, $attributes, $instalments);

        if ($changed) {
            $this->notifier->handle($actor, $updated, NotifyLeadLawyerOfContractEntry::DRAFT_UPDATED);
        }

        return $updated;
    }

    /**
     * @param  array{total_amount?: mixed, vat_rate_percent?: mixed, note?: string|null}  $attributes
     * @param  list<array<string, mixed>>  $instalments
     * @return array{0: Contract, 1: bool} hợp đồng đã sửa, và điều khoản tiền có đổi không ({@see self::moneyTerms()})
     */
    private function updateInTransaction(User $actor, Contract $contract, array $attributes, array $instalments): array
    {
        return $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor, $attributes, $instalments): array {
            Gate::forUser($actor)->authorize('updateDraft', $locked);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractStatusConflict::notDraftForUpdate($locked);
            }

            $totalAmount = $this->validatedAmount($attributes['total_amount'] ?? null, 'total_amount');
            $vatRate = $this->validatedVatRate($attributes['vat_rate_percent'] ?? null);

            $rows = [];

            foreach (array_values($instalments) as $index => $row) {
                $rows[] = $this->instalmentAttributes($lockedMatter, $row, "instalments.{$index}");
            }

            $existing = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->orderBy('sequence')
                ->get();

            $before = self::moneyTerms($locked->total_amount, $locked->vat_rate_percent, $existing->map(fn (Instalment $instalment): array => $instalment->only([
                'amount', 'trigger_type', 'trigger_stage_key', 'due_days_after_trigger', 'due_date',
            ]))->all());

            // Làn fa3: đợt mang khoản thu (chỉ có thể là khoản ĐÃ HUỶ — docblock lớp) được giữ, theo vị trí.
            // Đọc thường sau khoá `matters` → `contracts`: không Action tiền nào ghi khoản thu của vụ này
            // khi khoá đó đang ở đây, và bản nháp không nhận khoản thu mới.
            $withHistory = Payment::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->whereIn('instalment_id', $existing->modelKeys())
                ->distinct()
                ->pluck('instalment_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            /** @var array<int, Instalment> $kept vị trí (sequence) => đợt giữ lại */
            $kept = $existing->filter(fn (Instalment $instalment): bool => in_array($instalment->getKey(), $withHistory, true))
                ->keyBy(fn (Instalment $instalment): int => (int) $instalment->sequence)
                ->all();

            foreach ($kept as $sequence => $instalment) {
                if ($sequence > count($rows)) {
                    throw ValidationException::withMessages([
                        'instalment_history' => [__('billing_corrections.revert.keeps_voided_history', [
                            'name' => $instalment->name,
                            'position' => $sequence,
                        ])],
                    ]);
                }
            }

            // Xoá qua model (không `->delete()` trên query builder) để hook `deleting` của
            // `Instalment` chạy trên từng dòng — dù hôm nay nó luôn cho qua (hợp đồng còn
            // `draft`), im lặng bỏ qua hook là một thói quen viết mã sai, không phải một tối ưu.
            $existing->reject(fn (Instalment $instalment): bool => in_array($instalment->getKey(), $withHistory, true))
                ->each(fn (Instalment $instalment) => $instalment->delete());

            foreach ($rows as $index => $row) {
                $instalment = $kept[$index + 1] ?? new Instalment(['contract_id' => $locked->id, 'sequence' => $index + 1]);

                $instalment->fill([
                    ...$row,
                    'status' => InstalmentStatus::Pending,
                    'triggered_at' => null,
                    'triggered_by_stage_log_id' => null,
                    'waived_reason' => null,
                    'waived_by' => null,
                    'waived_at' => null,
                ]);
                $instalment->blameOn($actor)->save();
            }

            $locked->fill([
                'total_amount' => $totalAmount,
                'vat_rate_percent' => $vatRate,
                'note' => isset($attributes['note']) && trim((string) $attributes['note']) !== ''
                    ? trim((string) $attributes['note'])
                    : null,
            ]);
            $locked->blameOn($actor)->save();

            Audit::record('contract_draft_updated', $locked, [
                'code' => $locked->code,
                'total_amount' => $totalAmount,
                'instalment_count' => count($rows),
            ], $actor);

            return [$locked->refresh(), $before !== self::moneyTerms($totalAmount, $vatRate, $rows)];
        });
    }

    /**
     * Fix round 1 (m8): điều khoản TIỀN của một bản nháp, dạng so sánh được — giá trị, thuế suất, và theo thứ tự
     * mỗi đợt: số tiền, mốc kích hoạt, giai đoạn (chỉ với đợt theo giai đoạn), số ngày sau mốc (không với đợt có
     * ngày cố định), ngày đến hạn (chỉ với đợt có ngày cố định — hạn của đợt khác là số tính ra, không phải điều
     * khoản). Tên đợt, ghi chú và `percent_basis` (chỉ để hiển thị) không phải điều khoản tiền.
     *
     * @param  list<array<string, mixed>>  $instalments
     * @return array<string, mixed>
     */
    private static function moneyTerms(mixed $total, mixed $vatRate, array $instalments): array
    {
        return [
            'total' => (int) $total,
            'vat' => $vatRate === null ? null : (int) $vatRate,
            'instalments' => array_map(function (array $row): array {
                $trigger = $row['trigger_type'] instanceof InstalmentTrigger
                    ? $row['trigger_type']
                    : InstalmentTrigger::tryFrom((string) $row['trigger_type']);
                $dueDate = $row['due_date'] ?? null;

                return [
                    (int) $row['amount'],
                    $trigger?->value,
                    $trigger === InstalmentTrigger::Stage ? $row['trigger_stage_key'] : null,
                    $trigger === InstalmentTrigger::DueDate ? null : (int) $row['due_days_after_trigger'],
                    $trigger === InstalmentTrigger::DueDate && $dueDate !== null
                        ? ($dueDate instanceof DateTimeInterface ? $dueDate->format('Y-m-d') : (string) $dueDate)
                        : null,
                ];
            }, array_values($instalments)),
        ];
    }
}
