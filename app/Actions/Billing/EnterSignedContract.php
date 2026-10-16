<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use DateTimeInterface;

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
        $contract = $this->moneyTransaction(function () use ($actor, $matter, $attributes, $instalments, $signedAt): Contract {
            $lockedMatter = $this->scopelessly(Matter::query())->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            $draft = $this->drafter->draftLocked($actor, $lockedMatter, $attributes, $instalments);

            $locked = $this->scopelessly(Contract::query())->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            return $this->activator->activateLocked($actor, $lockedMatter, $locked, $signedAt);
        });

        $this->notifier->handle($actor, $contract, NotifyLeadLawyerOfContractEntry::ENTERED);

        return $contract;
    }
}
