<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\ContractStatusConflict;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ScheduleTotal;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Kích hoạt một hợp đồng đã ký: `draft` → `active` (M9 Task 4). Đây là TẦNG 1 của bất biến tổng —
 * lịch thu lệch khỏi giá trị hợp đồng dù một đồng thì hợp đồng không rời được `draft`.
 *
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): hàng `matters`
 *     TRƯỚC (câu đầu tiên của transaction — lần thăm dò id vụ việc chạy trước khi nó mở), rồi
 *     `contracts`; đọc lại trạng thái và giá trị từ hàng đã khoá — không tin đối tượng người gọi
 *     cầm trong tay.
 *  2. **Quyền:** `ContractPolicy::update` qua `Gate::forUser($actor)`.
 *  3. Chỉ từ `draft` (`ContractStatusConflict::notDraft`).
 *  4. `signed_at` là một ngày hợp lệ, không ở tương lai (so theo ngày, múi giờ ứng dụng).
 *  5. **Bất biến:** `ScheduleTotal::lockedOf()` (tổng các đợt chưa huỷ, đọc từ DB bằng một lần
 *     đọc CÓ KHOÁ — `instalments` sau `contracts`) === `total_amount`, nếu không thì
 *     `ContractTotalMismatch::onActivation`, nêu cả hai con số và phần lệch.
 *  6. Ghi `status = active`, `signed_at`, `activated_by = $actor` (người ở văn phòng ghi nhận việc
 *     ký — không phải chữ ký số).
 *  7. Mọi đợt `on_signing`: `due_date = signed_at + due_days_after_trigger`, `triggered_at =
 *     now()`. (Đợt của một bản nháp luôn `pending` — hôm nay `DraftContract` chỉ tạo đợt
 *     `pending`, và không Action nào đổi trạng thái đợt của bản nháp.)
 *  8. **Đợt theo giai đoạn vụ đã chạm** (M9 Task 6) — {@see self::releaseStageTriggeredInstalments()}.
 *  9. `Audit::record('contract_activated', ..., $actor)` bên trong transaction.
 *
 * **Không** tự đổi đầu mục danh mục nào (M9 Task 7: tab tiền chỉ nhắc luật sư tải bản đã ký lên).
 */
class ActivateContract
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function __construct(private TriggerInstalmentsForStage $stageTrigger) {}

    public function handle(User $actor, Contract $contract, DateTimeInterface|string $signedAt): Contract
    {
        return $this->inContractTransaction((int) $contract->getKey(), function (Matter $lockedMatter, Contract $locked) use ($actor, $signedAt): Contract {
            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractStatusConflict::notDraft($locked);
            }

            $signedOn = $this->validatedPastDate($signedAt, 'signed_at');

            $scheduleTotal = ScheduleTotal::lockedOf($locked->id);

            if ($scheduleTotal !== $locked->total_amount) {
                throw ContractTotalMismatch::onActivation($locked, $scheduleTotal);
            }

            $locked->fill([
                'status' => ContractStatus::Active,
                'signed_at' => $signedOn->toDateString(),
                'activated_by' => $actor->id,
            ]);
            $locked->blameOn($actor)->save();

            $onSigning = $this->scopelessly(Instalment::query())
                ->where('contract_id', $locked->id)
                ->where('trigger_type', InstalmentTrigger::OnSigning->value)
                ->orderBy('sequence')
                ->get();

            foreach ($onSigning as $instalment) {
                $instalment->fill([
                    'due_date' => $signedOn->copy()->addDays($instalment->due_days_after_trigger)->toDateString(),
                    'triggered_at' => now(),
                ]);
                $instalment->blameOn($actor)->save();
            }

            $this->releaseStageTriggeredInstalments($lockedMatter, $locked);

            Audit::record('contract_activated', $locked, [
                'code' => $locked->code,
                'signed_at' => $signedOn->toDateString(),
                'total_amount' => $locked->total_amount,
                'on_signing_released' => $onSigning->count(),
            ], $actor);

            return $locked->refresh();
        });
    }

    /**
     * **M9 Task 6 — đợt `stage` mà vụ ĐÃ chạm giai đoạn của nó trước lúc kích hoạt** (luật sư thường
     * nhận việc và nộp đơn trước khi hợp đồng giấy về): kích hoạt luôn, trong CÙNG transaction, sau
     * khi hợp đồng đã `active` — qua LÕI {@see TriggerInstalmentsForStage::releaseLocked()} với hai
     * hàng `matters` → `contracts` mà transaction này đã khoá. Không gọi `handle()` của Action đó: nó
     * tự mở một transaction tiền, và một transaction tiền lồng trong transaction của người khác là
     * đúng thứ {@see LocksBillingRows} cấm (Laravel không chạy lại transaction lồng). Một logic, không
     * bản sao: "vụ đã chạm giai đoạn X" (lần chạm ĐẦU), ngày đến hạn (ngày chạm, kẹp không sớm hơn
     * `signed_at` vừa ghi ở bước 6), dòng nhật ký không causer và `updated_by` của đợt giữ nguyên —
     * tất cả ở đó. Đợt của giai đoạn vụ CHƯA chạm vẫn chờ (`due_date` rỗng, hiển thị `scheduled`).
     */
    private function releaseStageTriggeredInstalments(Matter $lockedMatter, Contract $locked): void
    {
        $this->stageTrigger->releaseLocked($lockedMatter, $locked);
    }
}
