<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\BillingModelNotSupported;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\CodeSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Soạn một hợp đồng dịch vụ pháp lý ở trạng thái `draft`, kèm lịch thu (M9 Task 4).
 *
 *  1. **Khoá hàng `matters`** và đọc lại vụ từ hàng đã khoá, không qua `ClientPortalScope`
 *     (`ReadsWithoutPortalScope`: một phiên cổng khách mở song song không được làm vụ hay hợp đồng
 *     đang có thành "không có").
 *  2. **Quyền:** `ContractPolicy::create` với ngữ cảnh là vụ ĐÃ KHOÁ, hỏi qua
 *     `Gate::forUser($actor)` — không phải đối tượng người gọi cầm: một vụ vừa chuyển sang
 *     `restricted` sau khi màn hình nạp nó thì quản lý không còn thấy tiền của nó nữa. Định nghĩa
 *     "ai thấy tiền của vụ nào" nằm ở `ChecksBillingAccess`, không viết lại ở đây.
 *  3. **Một hợp đồng cho một vụ** (M9 quyết định 1): hỏi "đã có hợp đồng chưa" sau khi đã khoá vụ,
 *     để hai lần soạn đồng thời xếp hàng thay vì cùng thấy "chưa". Unique index thật trên
 *     `contracts.matter_id` là chốt chặn cuối.
 *  4. **Chỉ `fixed_fee`** — hình thức khác bị từ chối bằng `BillingModelNotSupported`.
 *  5. **Giá trị** là số nguyên đồng từ 1 tới `Money::MAX`; thuế suất rỗng hoặc 0–100
 *     (`App\Support\Billing\Vat` tách phần thuế nằm TRONG tổng khi hiển thị).
 *  6. **Lịch thu** — mỗi dòng qua `ValidatesBillingInput::instalmentAttributes()` (ba loại kích
 *     hoạt, giai đoạn có thật và không phải giai đoạn đầu). `sequence` theo thứ tự mảng, từ 1.
 *     `amount` lưu đúng số người gọi đưa vào; `percent_basis` lưu nguyên, không tính lại gì.
 *  7. **Mã** `HD-{YYYY}-{0001}` qua `CodeSequence` (năm theo múi giờ ứng dụng), không bao giờ đổi.
 *
 * **Tổng các đợt KHÔNG phải khớp giá trị ở bản nháp.** Bản nháp là nơi văn phòng còn đang sửa;
 * bất biến tổng bắt đầu giữ từ lúc kích hoạt (`ActivateContract`, tầng 1).
 *
 * Mọi thứ trong một transaction; `blameOn($actor)` trước mọi `save()`; `Audit::record(...,
 * $actor)` bên trong transaction (không mutation probe nào phân biệt được vị trí đó với vị trí
 * ngay sau commit — nói thẳng như kế hoạch yêu cầu). Không một dòng `Auth::` nào.
 */
class DraftContract
{
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    /**
     * @param  array{total_amount?: mixed, vat_rate_percent?: mixed, billing_model?: BillingModel|string|null, note?: string|null}  $attributes
     * @param  list<array<string, mixed>>  $instalments
     */
    public function handle(User $actor, Matter $matter, array $attributes, array $instalments): Contract
    {
        return DB::transaction(function () use ($actor, $matter, $attributes, $instalments): Contract {
            $lockedMatter = $this->scopelessly(Matter::query())->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('create', [Contract::class, $lockedMatter]);

            if ($this->scopelessly(Contract::query())->where('matter_id', $lockedMatter->id)->exists()) {
                throw ValidationException::withMessages(['matter_id' => [__('billing.validation.contract_exists')]]);
            }

            $billingModel = $this->billingModel($attributes['billing_model'] ?? null);

            if ($billingModel !== BillingModel::FixedFee) {
                throw BillingModelNotSupported::make($billingModel);
            }

            $totalAmount = $this->validatedAmount($attributes['total_amount'] ?? null, 'total_amount');
            $vatRate = $this->vatRate($attributes['vat_rate_percent'] ?? null);

            $rows = [];

            foreach (array_values($instalments) as $index => $row) {
                $rows[] = $this->instalmentAttributes($lockedMatter, $row, "instalments.{$index}");
            }

            $year = now()->year;

            $contract = new Contract([
                'matter_id' => $lockedMatter->id,
                'code' => CodeSequence::format("HD-{$year}-", CodeSequence::next("contract:{$year}")),
                'status' => ContractStatus::Draft,
                'billing_model' => $billingModel,
                'total_amount' => $totalAmount,
                'vat_rate_percent' => $vatRate,
                'note' => isset($attributes['note']) && trim($attributes['note']) !== '' ? trim($attributes['note']) : null,
            ]);
            $contract->blameOn($actor)->save();

            foreach ($rows as $index => $row) {
                $instalment = new Instalment([
                    ...$row,
                    'contract_id' => $contract->id,
                    'sequence' => $index + 1,
                    'status' => InstalmentStatus::Pending,
                ]);
                $instalment->blameOn($actor)->save();
            }

            Audit::record('contract_drafted', $contract, [
                'code' => $contract->code,
                'matter_id' => $lockedMatter->id,
                'total_amount' => $totalAmount,
                'instalment_count' => count($rows),
            ], $actor);

            return $contract->refresh();
        });
    }

    private function billingModel(BillingModel|string|null $value): BillingModel
    {
        if ($value === null) {
            return BillingModel::FixedFee;
        }

        $model = $value instanceof BillingModel ? $value : BillingModel::tryFrom($value);

        if ($model === null) {
            throw ValidationException::withMessages(['billing_model' => [__('billing.validation.billing_model_invalid')]]);
        }

        return $model;
    }

    /** `null` (không có dòng thuế) hoặc số nguyên 0–100. `0` là hoá đơn thuế suất 0%, khác `null`. */
    private function vatRate(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > 100) {
            throw ValidationException::withMessages(['vat_rate_percent' => [__('billing.validation.vat_rate_out_of_range')]]);
        }

        return $value;
    }
}
