<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\ContractStatusConflict;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ScheduleTotal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Kích hoạt một hợp đồng đã ký: `draft` → `active` (M9 Task 4). Đây là TẦNG 1 của bất biến tổng —
 * lịch thu lệch khỏi giá trị hợp đồng dù một đồng thì hợp đồng không rời được `draft`.
 *
 *  1. Khoá hàng `contracts` rồi đọc lại trạng thái và giá trị từ hàng đã khoá — không tin đối
 *     tượng người gọi cầm trong tay.
 *  2. **Quyền:** `ContractPolicy::update` qua `Gate::forUser($actor)`.
 *  3. Chỉ từ `draft` (`ContractStatusConflict::notDraft`).
 *  4. `signed_at` là một ngày hợp lệ, không ở tương lai (so theo ngày, múi giờ ứng dụng).
 *  5. **Bất biến:** `ScheduleTotal::of()` (tổng các đợt chưa huỷ, đọc từ DB) === `total_amount`,
 *     nếu không thì `ContractTotalMismatch::onActivation`, nêu cả hai con số và phần lệch.
 *  6. Ghi `status = active`, `signed_at`, `activated_by = $actor` (người ở văn phòng ghi nhận việc
 *     ký — không phải chữ ký số).
 *  7. Mọi đợt `on_signing`: `due_date = signed_at + due_days_after_trigger`, `triggered_at =
 *     now()`. (Đợt của một bản nháp luôn `pending` — `DraftContract` chỉ tạo đợt `pending`, và
 *     không Action nào đổi trạng thái đợt trước khi kích hoạt.)
 *  8. **Điểm nối cho đợt theo giai đoạn** — {@see self::releaseStageTriggeredInstalments()}.
 *  9. `Audit::record('contract_activated', ..., $actor)` bên trong transaction.
 *
 * **Không** tự đổi đầu mục danh mục nào (M9 Task 7: tab tiền chỉ nhắc luật sư tải bản đã ký lên).
 */
class ActivateContract
{
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    public function handle(User $actor, Contract $contract, DateTimeInterface|string $signedAt): Contract
    {
        return DB::transaction(function () use ($actor, $contract, $signedAt): Contract {
            $locked = $this->scopelessly(Contract::query())->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            if ($locked->status !== ContractStatus::Draft) {
                throw ContractStatusConflict::notDraft($locked);
            }

            $signedOn = $this->validatedPastDate($signedAt, 'signed_at');

            $scheduleTotal = ScheduleTotal::of($locked->id);

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

            $this->releaseStageTriggeredInstalments($locked);

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
     * **ĐIỂM NỐI — M9 Task 6 (`TriggerInstalmentsForStage`), CHƯA CÀI.**
     *
     * Kế hoạch: kích hoạt xong, gọi `TriggerInstalmentsForStage` cho mọi đợt `stage` mà vụ việc
     * ĐÃ đi qua giai đoạn kích hoạt của nó trước ngày ký (luật sư thường nhận việc và nộp đơn
     * trước khi hợp đồng giấy về). Task 6 bị hoãn tới khi M6.5 merge (phán quyết controller, sổ
     * `.superpowers/sdd/2026-09-19-m9-contracts-and-payments/progress.md`), vì nó sửa
     * `TransitionMatterStage` và sự kiện đổi giai đoạn mà các làn M6.5 đang viết lại.
     *
     * Cố ý để TRỐNG, không một bản sao nào của logic kích hoạt: một bản sao ở đây sẽ là định nghĩa
     * thứ hai của "vụ đã qua giai đoạn X chưa" và "đợt này đến hạn ngày nào", và nó sẽ lệch với
     * bản của Task 6. Hệ quả tạm thời, nói thẳng: một đợt `stage` của vụ đã qua giai đoạn đó thì
     * sau kích hoạt vẫn `due_date` rỗng (hiển thị `scheduled`) cho tới khi Task 6 — hoặc tác vụ
     * đối chiếu hằng ngày `ReconcileStageTriggeredInstalments` của nó — chạy. Test `todo()` trong
     * `tests/Feature/Actions/Billing/ActivateContractTest.php` mang đúng tên việc còn thiếu.
     *
     * Task 6 thay thân hàm này bằng lời gọi thật, trong CÙNG transaction, sau khi hợp đồng đã
     * `active` (Task 6 không kích hoạt đợt của hợp đồng `draft`).
     */
    private function releaseStageTriggeredInstalments(Contract $contract): void
    {
        // M9 Task 6: TriggerInstalmentsForStage cho các giai đoạn vụ việc đã đi qua.
    }
}
