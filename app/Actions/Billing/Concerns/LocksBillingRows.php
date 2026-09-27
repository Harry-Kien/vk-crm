<?php

namespace App\Actions\Billing\Concerns;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * **Thứ tự khoá hàng DUY NHẤT của mọi Action tiền** — phán quyết toàn dự án (controller M6.5,
 * 2026-09-27): **`matters` TRƯỚC, rồi `contracts` → `instalments` → `payments`.** Hàng nào khác mà
 * một Action cần khoá (bản scan `documents` của `AmendContract`/`RecordPayment`, `code_sequences`
 * của `DraftContract`) khoá SAU chuỗi này, không bao giờ xen giữa.
 *
 * Vì sao `matters` đứng đầu: các Action vụ việc (`AddTeamMember`, `RemoveTeamMember`, mở mốc
 * thời hạn, `OpenMatter`) khoá hàng `matters` TRƯỚC mọi hàng con của nó. Một Action tiền khoá
 * `contracts` trước rồi mới tới `matters` (như `RecordPayment` bản trước) chạy song song với một
 * Action vụ việc khoá `matters` rồi đọc-khoá tiền của vụ đó (hook dư nợ khi huỷ vụ việc, lúc merge
 * với M6.5) là đúng hình dạng deadlock A-giữ-X-chờ-Y / B-giữ-Y-chờ-X. Cùng một thứ tự ở mọi nơi
 * thì hai transaction trên cùng một vụ chỉ XẾP HÀNG ở hàng `matters`, không khoá chéo nhau.
 *
 * **Đọc thăm dò KHÔNG khoá, rồi mới khoá theo thứ tự.** Người gọi chỉ cầm id của hàng SÂU nhất
 * (một đợt, một khoản thu); để khoá `matters` trước, phải biết nó là vụ nào — nên đi NGƯỢC lên
 * bằng những lần đọc thường (`payments.instalment_id` → `instalments.contract_id` →
 * `contracts.matter_id`), rồi khoá XUÔI từ trên xuống. An toàn vì ba cột khoá ngoại đó không Action
 * nào đổi sau khi tạo hàng; mọi GIÁ TRỊ dùng để quyết định (trạng thái, số tiền, vụ việc của
 * policy) đều đọc lại từ hàng ĐÃ KHOÁ, không từ lần thăm dò.
 *
 * **Vụ việc khoá kèm `withTrashed()`.** Một vụ đã xoá mềm vẫn khoá được — để POLICY (cổng
 * `ChecksBillingAccess::canSeeBilling()`, vốn đóng đúng trên `trashed()`) là nơi từ chối, bằng một
 * `AuthorizationException`, thay vì một lần đọc không thấy hàng biến thành 404 ở một Action và
 * thành 403 ở Action khác.
 *
 * **Quan hệ được gắn sẵn bằng đúng các hàng đã khoá** (`contract->matter`, `instalment->contract`,
 * `payment->instalment`), để policy hỏi qua `Gate::forUser($actor)` đọc CHÍNH các hàng đó — không
 * phải một lần nạp lười (không khoá) khác của cùng hàng.
 *
 * Lớp dùng trait này phải dùng cả {@see ReadsWithoutPortalScope} (đọc không qua
 * `ClientPortalScope`: một phiên cổng khách mở song song không được làm hàng tiền thành "không
 * có").
 */
trait LocksBillingRows
{
    /**
     * Khoá `matters` rồi `contracts` của một hợp đồng.
     *
     * @return array{0: Matter, 1: Contract}
     */
    protected function lockContractChain(int $contractId): array
    {
        $matterId = $this->scopelessly(Contract::query())->whereKey($contractId)->value('matter_id');

        if ($matterId === null) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$contractId]);
        }

        $matter = $this->scopelessly(Matter::query())->withTrashed()->whereKey($matterId)->lockForUpdate()->firstOrFail();
        $contract = $this->scopelessly(Contract::query())->whereKey($contractId)->lockForUpdate()->firstOrFail();

        $contract->setRelation('matter', $matter);

        return [$matter, $contract];
    }

    /**
     * Khoá `matters`, `contracts`, rồi đúng hàng `instalments` này.
     *
     * @return array{0: Matter, 1: Contract, 2: Instalment}
     */
    protected function lockInstalmentChain(int $instalmentId): array
    {
        $contractId = $this->scopelessly(Instalment::query())->whereKey($instalmentId)->value('contract_id');

        if ($contractId === null) {
            throw (new ModelNotFoundException)->setModel(Instalment::class, [$instalmentId]);
        }

        [$matter, $contract] = $this->lockContractChain((int) $contractId);

        $instalment = $this->scopelessly(Instalment::query())->whereKey($instalmentId)->lockForUpdate()->firstOrFail();
        $instalment->setRelation('contract', $contract);

        return [$matter, $contract, $instalment];
    }

    /**
     * Khoá `matters`, `contracts`, `instalments`, rồi đúng hàng `payments` này.
     *
     * @return array{0: Matter, 1: Contract, 2: Instalment, 3: Payment}
     */
    protected function lockPaymentChain(int $paymentId): array
    {
        $instalmentId = $this->scopelessly(Payment::query())->whereKey($paymentId)->value('instalment_id');

        if ($instalmentId === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
        }

        [$matter, $contract, $instalment] = $this->lockInstalmentChain((int) $instalmentId);

        $payment = $this->scopelessly(Payment::query())->whereKey($paymentId)->lockForUpdate()->firstOrFail();
        $payment->setRelation('instalment', $instalment);

        return [$matter, $contract, $instalment, $payment];
    }
}
