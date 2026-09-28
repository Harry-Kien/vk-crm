<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\ContractNotDestroyable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

/**
 * Xoá cứng một hợp đồng còn `draft` chưa có khoản thu nào, cùng lịch thu của nó (lượt rà soát cuối
 * M9, M9) — tab "Hợp đồng và thanh toán" cần đường này cho một bản nháp soạn nhầm vụ, hay soạn lại
 * từ đầu. Hợp đồng KHÔNG xoá mềm (M9 quyết định 4, docblock `ContractNotDestroyable`), nên đây là
 * một lần xoá thật; mã `HD-…` đã cấp không được dùng lại (`CodeSequence` chỉ tăng).
 *
 * Các bước, tất cả trong MỘT transaction:
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): hàng `matters`
 *     TRƯỚC (câu đầu tiên của transaction — lần thăm dò id vụ việc chạy trước khi nó mở), rồi
 *     `contracts`; đọc lại từ hàng đã khoá.
 *  2. **Quyền:** `ContractPolicy::delete` qua `Gate::forUser($actor)` (`contract.manage` trên một vụ
 *     người đó thấy được tiền — cùng cổng với sửa bản nháp).
 *  3. "Xoá được không" là {@see Contract::assertDestroyable()} — ĐÚNG hàm hook `deleting` gọi, không
 *     một điều kiện thứ hai: còn `draft` và chưa có khoản thu nào ({@see ContractNotDestroyable}).
 *  4. Xoá các đợt qua model (hook `Instalment::deleting` vẫn chạy, cùng lý do `UpdateDraftContract`),
 *     rồi xoá hợp đồng (hook `Contract::deleting` hỏi lại bước 3 — vô hại).
 *  5. `Audit::record('contract_draft_deleted', …, $actor)` bên trong transaction, **gắn vào VỤ
 *     VIỆC** (hàng `contracts` không còn để trỏ tới), mang mã hợp đồng, giá trị và số đợt đã xoá.
 */
class DeleteDraftContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;

    public function handle(User $actor, Contract $contract): void
    {
        $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor): void {
            Gate::forUser($actor)->authorize('delete', $locked);

            $locked->assertDestroyable();

            $instalments = $this->scopelessly(Instalment::query())->where('contract_id', $locked->id)->get();
            $instalments->each(fn (Instalment $instalment) => $instalment->delete());

            $locked->delete();

            Audit::record('contract_draft_deleted', $lockedMatter, [
                'code' => $locked->code,
                'contract_id' => $locked->id,
                'total_amount' => $locked->total_amount,
                'instalment_count' => $instalments->count(),
            ], $actor);
        });
    }
}
