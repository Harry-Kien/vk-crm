<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\ContractTotalMismatch;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\Money;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * "Khách đã ký — kích hoạt luôn" (làn kt, 2026-10-15): nhập MỘT hợp đồng giấy đã ký trong một lần —
 * soạn bản nháp ({@see DraftContract::draftLocked()}) rồi kích hoạt nó ({@see ActivateContract::
 * activateLocked()}) trong CÙNG một transaction tiền. Dùng ở trang "Hợp đồng" của kế toán; luật nào
 * cũng là luật của hai Action đó, không viết lại dòng nào:
 *
 *  1. **Khoá hàng `matters`** — câu ĐẦU TIÊN của transaction ({@see LocksBillingRows}), đọc lại vụ
 *     không qua `ClientPortalScope`.
 *  2. **Soạn** qua lõi của `DraftContract`: `ContractPolicy::create` trên vụ đã khoá, một hợp đồng
 *     chưa huỷ cho một vụ, chỉ `fixed_fee`, giá trị, thuế suất, lịch thu, mã `HD-…` (khoá
 *     `code_sequences`).
 *  3. **Khoá hàng `contracts` vừa chèn** rồi **kích hoạt** qua lõi của `ActivateContract`:
 *     `ContractPolicy::activate`, ngày ký không ở tương lai, bất biến tổng (khoá `instalments`), đợt
 *     khi ký và đợt của giai đoạn vụ đã chạm. Thứ tự khoá trên hàng có sẵn vẫn là `matters` → … →
 *     `instalments`; `code_sequences` đứng trước `contracts` chỉ vì hàng `contracts` ở đây là hàng
 *     CHÍNH transaction này vừa chèn — chưa commit nên không transaction nào khác thấy hay khoá được
 *     nó, không thể là một mắt xích của vòng chờ khoá nào.
 *
 * **Tất cả hoặc không gì cả.** Một lần từ chối ở bước 3 — tổng các đợt lệch giá trị, ngày ký ở tương
 * lai — rollback cả bản nháp của bước 2: không có hợp đồng nháp "mồ côi", mã `HD-…` không bị tiêu (dãy
 * số rollback cùng), modal của màn hình ở lại với dữ liệu đã nhập để sửa.
 *
 * **Lệch tổng là lỗi nhập liệu của lịch thu, gắn vào ô `instalments`** (sửa 2026-10-21, chủ văn phòng
 * báo: tổng 100.000.000, chưa thêm đợt nào, bấm "Lưu và kích hoạt" sáu lần — sáu thông báo nổi "Chưa
 * kích hoạt được hợp đồng HD-2026-0026…" mà không biết sửa ở đâu, và mã đó chưa bao giờ được lưu).
 * Lõi kích hoạt ném {@see ContractTotalMismatch} với câu của TẦNG 1 (nói về một hợp đồng có sẵn, kèm
 * mã) — đúng cho nút "Kích hoạt" trên một bản nháp. Ở đây không có bản nháp nào sau rollback, nên Action
 * đổi nó thành `ValidationException` trên `instalments` — cùng hình dạng với lỗi ngày ký ở tương lai
 * (`signed_at`) và lỗi từng dòng (`instalments.N.*`) của lõi soạn — với câu riêng: lịch thu trống nói
 * "thêm đợt", lịch thu lệch nói ba con số. Luật vẫn là MỘT (kiểm tra của `ActivateContract`); chỉ câu
 * và chỗ hiện đổi. Màn hình ({@see ReportsActionFailures}) in lỗi ngay dưới
 * lịch thu, hộp thoại ở lại.
 *
 * **Sau commit:** một dòng chuông `entered` cho luật sư phụ trách khi người nhập không phải họ
 * ({@see NotifyLeadLawyerOfContractEntry}) — một dòng, không phải hai (`drafted` rồi `activated`).
 * Nhật ký hoạt động có cả hai dòng `contract_drafted` và `contract_activated` của hai lõi, mang người
 * nhập.
 */
class EnterSignedContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;

    public function __construct(
        private readonly DraftContract $drafter,
        private readonly ActivateContract $activator,
        private readonly NotifyLeadLawyerOfContractEntry $notifier,
    ) {}

    /**
     * @param  array{total_amount?: mixed, vat_rate_percent?: mixed, note?: string|null}  $attributes
     * @param  list<array<string, mixed>>  $instalments
     */
    public function handle(User $actor, Matter $matter, array $attributes, array $instalments, DateTimeInterface|string $signedAt): Contract
    {
        try {
            $contract = $this->moneyTransaction(function () use ($actor, $matter, $attributes, $instalments, $signedAt): Contract {
                $lockedMatter = $this->scopelessly(Matter::query())->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

                $draft = $this->drafter->draftLocked($actor, $lockedMatter, $attributes, $instalments);

                $locked = $this->scopelessly(Contract::query())->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

                return $this->activator->activateLocked($actor, $lockedMatter, $locked, $signedAt);
            });
        } catch (ContractTotalMismatch $mismatch) {
            throw ValidationException::withMessages(['instalments' => $instalments === []
                ? __('billing.errors.schedule_empty_on_entry', ['total' => Money::format($mismatch->contractTotal)])
                : __('billing.errors.schedule_mismatch_on_entry', [
                    'schedule' => Money::format($mismatch->scheduleTotal),
                    'total' => Money::format($mismatch->contractTotal),
                    'difference' => Money::format(abs($mismatch->contractTotal - $mismatch->scheduleTotal)),
                ])]);
        }

        $this->notifier->handle($actor, $contract, NotifyLeadLawyerOfContractEntry::ENTERED);

        return $contract;
    }
}
