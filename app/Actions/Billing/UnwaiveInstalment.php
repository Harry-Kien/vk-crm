<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Exceptions\InstalmentNotPayable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ScheduleTotal;
use Illuminate\Support\Facades\Gate;

/**
 * "Bỏ miễn" — đưa một đợt đã miễn (`waived`) về lại `pending` (sửa sau kiểm tra nghiệp vụ toàn hệ
 * thống, làn fb, mục A2). Trước Action này một lần miễn nhầm không sửa được: phụ lục từ chối đợt
 * không còn `pending`, và đường vòng duy nhất (thêm một đợt mới, nâng giá trị hợp đồng lên đúng số
 * đó) làm sai giá trị hợp đồng trên cổng khách, gói bàn giao và trang Doanh thu.
 *
 * Đối xứng với {@see WaiveInstalment}, cùng mọi luật của nó:
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters` (câu đầu
 *     tiên của transaction), `contracts`, rồi đúng hàng `instalments` này.
 *  2. **Quyền:** `InstalmentPolicy::waive` qua `Gate::forUser($actor)` — bỏ miễn là cùng một quyết
 *     định thương mại với miễn (`contract.manage` cộng "thấy tiền của vụ"), nên cùng một câu hỏi.
 *  3. Hợp đồng phải `active` ({@see InstalmentNotPayable::contractNotActive()}) — hợp đồng đã hoàn
 *     tất hay đã huỷ là sổ đã đóng — và đợt phải `waived` ({@see InstalmentNotPayable::toUnwaive()}).
 *  4. Lý do ≥ 20 ký tự `mb_strlen`.
 *  5. Ghi `status = pending`, xoá ba cột `waived_*` (chúng mô tả một lần miễn ĐANG có hiệu lực);
 *     lần miễn cũ còn nguyên ở dòng nhật ký `instalment_waived` của nó, và dòng
 *     `instalment_unwaived` chép lại lý do, người và lúc miễn cũ.
 *
 * Bất biến tổng M9 không đổi: `waived` và `pending` đều được tính trong tổng lịch thu
 * ({@see ScheduleTotal::counted()}), nên `total_amount` và tổng các đợt chưa huỷ giữ nguyên. Đợt quay
 * lại công nợ, nhắc quá hạn và khối tiền trên cổng khách theo đúng `due_date` sẵn có của nó.
 */
class UnwaiveInstalment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Instalment $instalment, string $reason): Instalment
    {
        return $this->inInstalmentTransaction((int) $instalment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment) use ($actor, $reason): Instalment {
            Gate::forUser($actor)->authorize('waive', $lockedInstalment);

            if ($lockedContract->status !== ContractStatus::Active) {
                throw InstalmentNotPayable::contractNotActive($lockedInstalment);
            }

            if ($lockedInstalment->status !== InstalmentStatus::Waived) {
                throw InstalmentNotPayable::toUnwaive($lockedInstalment);
            }

            $reason = $this->validatedReason($reason);

            $previous = [
                'previous_waived_reason' => $lockedInstalment->waived_reason,
                'previous_waived_by' => $lockedInstalment->waived_by,
                'previous_waived_at' => $lockedInstalment->waived_at?->toIso8601String(),
            ];

            $lockedInstalment->fill([
                'status' => InstalmentStatus::Pending,
                'waived_reason' => null,
                'waived_by' => null,
                'waived_at' => null,
            ]);
            $lockedInstalment->blameOn($actor)->save();

            Audit::record('instalment_unwaived', $lockedInstalment, [
                'contract_code' => $lockedContract->code,
                'amount' => $lockedInstalment->amount,
                'reason' => $reason,
                ...$previous,
            ], $actor);

            return $lockedInstalment->refresh();
        });
    }
}
