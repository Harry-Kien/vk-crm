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
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Dời hạn đợt" (sửa sau kiểm tra nghiệp vụ toàn hệ thống, làn fb, mục B) — khách xin khất, văn phòng
 * đồng ý: đổi `due_date` của MỘT đợt còn chờ thu. Trước Action này không có đường nào: phụ lục chỉ
 * đổi số tiền của đợt, và đợt đã thu một phần thì không huỷ được để thêm lại.
 *
 * Không đổi số tiền, không đổi trạng thái, nên bất biến tổng M9 không bị chạm. Đợt quay lại "chưa
 * đến hạn" nếu ngày mới ở tương lai — công nợ quá hạn, nhắc quá hạn và cổng khách đọc theo ngày mới.
 *
 * Luật, trong MỘT transaction theo chuỗi khoá DUY NHẤT ({@see LocksBillingRows}: `matters`,
 * `contracts`, rồi đúng hàng `instalments` này):
 *  1. **Quyền:** `InstalmentPolicy::reschedule` — cùng trục với miễn (`contract.manage` cộng "thấy
 *     tiền của vụ"): khất nợ là một quyết định thương mại, không phải việc ghi tiền đã về.
 *  2. Hợp đồng `active` ({@see InstalmentNotPayable::contractNotActive()}), đợt `pending` (đã thu đủ,
 *     đã miễn, đã huỷ thì không còn gì để khất — {@see InstalmentNotPayable::toReschedule()}), và đợt
 *     ĐÃ CÓ ngày đến hạn: đợt theo giai đoạn chưa tới giai đoạn thì chưa có ngày nào để dời.
 *  3. Ngày mới hợp lệ (`Y-m-d`) và khác ngày cũ; lý do ≥ 20 ký tự.
 *  4. Nhật ký `instalment_rescheduled`: ngày cũ, ngày mới, lý do.
 */
class RescheduleInstalment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Instalment $instalment, DateTimeInterface|string|null $dueDate, string $reason): Instalment
    {
        return $this->inInstalmentTransaction((int) $instalment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment) use ($actor, $dueDate, $reason): Instalment {
            Gate::forUser($actor)->authorize('reschedule', $lockedInstalment);

            if ($lockedContract->status !== ContractStatus::Active) {
                throw InstalmentNotPayable::contractNotActive($lockedInstalment);
            }

            if ($lockedInstalment->status !== InstalmentStatus::Pending) {
                throw InstalmentNotPayable::toReschedule($lockedInstalment);
            }

            if ($lockedInstalment->due_date === null) {
                throw ValidationException::withMessages([
                    'due_date' => [__('billing_corrections.reschedule.no_due_date_yet', ['name' => $lockedInstalment->name])],
                ]);
            }

            $newDate = $this->validatedDate($dueDate, 'due_date');
            $previous = $lockedInstalment->due_date->toDateString();

            if ($newDate->toDateString() === $previous) {
                throw ValidationException::withMessages([
                    'due_date' => [__('billing_corrections.reschedule.same_date')],
                ]);
            }

            $reason = $this->validatedReason($reason);

            $lockedInstalment->fill(['due_date' => $newDate->toDateString()]);
            $lockedInstalment->blameOn($actor)->save();

            Audit::record('instalment_rescheduled', $lockedInstalment, [
                'contract_code' => $lockedContract->code,
                'from' => $previous,
                'to' => $newDate->toDateString(),
                'reason' => $reason,
            ], $actor);

            return $lockedInstalment->refresh();
        });
    }
}
