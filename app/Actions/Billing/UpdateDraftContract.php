<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sửa một hợp đồng còn `draft`: giá trị, thuế suất và lịch thu (M9 Task 7 — tab "Hợp đồng và
 * thanh toán" cần sửa lại một bản nháp trước khi kích hoạt, ví dụ đổi một con số gõ nhầm hay thêm
 * một đợt quên đưa vào). `DraftContract` chỉ TẠO; đây là đường DUY NHẤT sửa một hợp đồng đã tồn
 * tại nhưng chưa kích hoạt.
 *
 * **Không đổi `billing_model`.** M9 chỉ cài `fixed_fee`; sửa cách tính phí không phải việc của
 * "sửa bản nháp" — nó đổi cả bản chất hợp đồng, và không có Action nào làm chuyện đó ở M9.
 *
 * **Không kiểm "đã có khoản thu chưa" ở đây, và đó không phải một chỗ thiếu sót.** Một khoản thu
 * chỉ ghi được khi hợp đồng `active` (`RecordPayment` bước 3: `InstalmentNotPayable::
 * contractNotActive()`), nên một hợp đồng còn `draft` không bao giờ có khoản thu nào — bất biến đó
 * giữ ở `RecordPayment`, không lặp lại một lần kiểm tra thứ hai không bao giờ đỏ được ở đây.
 *
 * **Lịch thu được THAY TOÀN BỘ, không được vá từng dòng.** Bản nháp là nơi văn phòng còn đang sửa
 * (docblock `DraftContract`: "tổng các đợt KHÔNG phải khớp giá trị ở bản nháp"), và một bản nháp
 * không thể có khoản thu, nên xoá cứng toàn bộ đợt cũ rồi tạo lại đúng những gì màn hình gửi lên là
 * an toàn — không có gì để mất, và không cần một thuật toán so khớp dòng cũ/dòng mới. Hook
 * `Instalment::deleting` vẫn tự kiểm "hợp đồng còn draft không" trên từng dòng, nên đây không phải
 * một đường đi vòng qua nó.
 *
 * Các bước, tất cả trong MỘT transaction:
 *  1. Khoá hàng `contracts`, đọc lại từ hàng đã khoá.
 *  2. **Quyền:** `ContractPolicy::update` qua `Gate::forUser($actor)` (SPEC §5: `contract.manage`
 *     là cổng của MỌI thay đổi trên một hợp đồng đã có, kể cả sửa bản nháp — xem docblock
 *     `ContractPolicy`).
 *  3. Chỉ trên `draft` ({@see ContractStatusConflict::notDraftForUpdate()} — câu RIÊNG với
 *     `notDraft()` của `ActivateContract`, vì đây là "sửa" không phải "kích hoạt").
 *  4. Giá trị và thuế suất kiểm bằng đúng `ValidatesBillingInput` mà `DraftContract` dùng.
 *  5. Xoá toàn bộ đợt cũ (qua model, để hook `deleting` chạy), tạo lại đợt mới từ
 *     `instalmentAttributes()`, `sequence` theo thứ tự mảng từ 1 — cùng luật `DraftContract`.
 *  6. `Audit::record('contract_draft_updated', ..., $actor)` bên trong transaction.
 *
 * Mọi thứ trong một transaction; `blameOn($actor)` trước mọi `save()`. Không một dòng `Auth::`
 * nào.
 */
class UpdateDraftContract
{
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    /**
     * @param  array{total_amount?: mixed, vat_rate_percent?: mixed, note?: string|null}  $attributes
     * @param  list<array<string, mixed>>  $instalments
     */
    public function handle(User $actor, Contract $contract, array $attributes, array $instalments): Contract
    {
        return DB::transaction(function () use ($actor, $contract, $attributes, $instalments): Contract {
            $locked = $this->scopelessly(Contract::query())->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractStatusConflict::notDraftForUpdate($locked);
            }

            $matter = $this->scopelessly(Matter::query())->findOrFail($locked->matter_id);

            $totalAmount = $this->validatedAmount($attributes['total_amount'] ?? null, 'total_amount');
            $vatRate = $this->validatedVatRate($attributes['vat_rate_percent'] ?? null);

            $rows = [];

            foreach (array_values($instalments) as $index => $row) {
                $rows[] = $this->instalmentAttributes($matter, $row, "instalments.{$index}");
            }

            // Xoá qua model (không `->delete()` trên query builder) để hook `deleting` của
            // `Instalment` chạy trên từng dòng — dù hôm nay nó luôn cho qua (hợp đồng còn
            // `draft`), im lặng bỏ qua hook là một thói quen viết mã sai, không phải một tối ưu.
            $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->get()
                ->each(fn (Instalment $instalment) => $instalment->delete());

            foreach ($rows as $index => $row) {
                $instalment = new Instalment([
                    ...$row,
                    'contract_id' => $locked->id,
                    'sequence' => $index + 1,
                    'status' => InstalmentStatus::Pending,
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

            return $locked->refresh();
        });
    }
}
