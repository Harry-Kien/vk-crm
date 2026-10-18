<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Billing\Concerns\ValidatesBillingInput;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Exceptions\ContractNotRevertible;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * "Đưa về bản nháp" (làn fa3, 2026-10-16, mục 2 — phán quyết controller): một hợp đồng kích hoạt NHẦM
 * (ngày ký, thuế suất, tổng, lịch thu gõ sai) về lại `draft`, để sửa bằng "Sửa bản nháp" rồi kích hoạt
 * lại — thay cho đường cũ "huỷ hợp đồng rồi nhập lại" (đốt số HD, thêm dòng "Hợp đồng đã huỷ trong kỳ",
 * hợp đồng nhập lại chỉ ghi được phần còn phải thu). Phụ lục không sửa được `signed_at` hay
 * `vat_rate_percent`, nên đây là đường DUY NHẤT sửa hai trường đó.
 *
 * Chỉ khi trên hợp đồng CHƯA CÓ GÌ xảy ra ngoài việc kích hoạt — kiểm trên các hàng ĐÃ KHOÁ:
 *  1. Khoá theo thứ tự DUY NHẤT của mọi Action tiền ({@see LocksBillingRows}): `matters` (câu đầu tiên
 *     của transaction), `contracts`, rồi mọi đợt của hợp đồng (`instalments`), rồi tổng khoản thu chưa
 *     huỷ của chúng (`payments`, {@see LocksBillingRows::lockedCollectedAmounts()}), rồi bản scan
 *     `contract_amendments` — đúng chỗ của nó, sau chuỗi.
 *  2. **Quyền:** `ContractPolicy::revertToDraft` qua `Gate::forUser($actor)` — CÙNG điều kiện với
 *     `activate`: ai kích hoạt được thì đưa về được (`contract.manage` hoặc `contract.enter`, trên vụ người
 *     đó thấy tiền).
 *  3. Chỉ từ `active` ({@see ContractStatusConflict::notActiveForRevert()} — hợp đồng nháp, đã hoàn tất hay đã huỷ
 *     không có gì để đưa về).
 *  3a. Fix round 1 (m5): hồ sơ CHƯA KẾT THÚC ({@see ContractNotRevertible::matterClosed()}, đọc trên hàng `matters`
 *     đã khoá): hợp đồng của một hồ sơ đã đóng là sổ sách đã chốt — công nợ của nó ("Đã kết thúc còn công
 *     nợ") và thư nhắc không được lặng lẽ biến mất; sửa con số thì bằng phụ lục, hoặc huỷ hợp đồng.
 *  4. Lý do bắt buộc (cùng độ dài tối thiểu với mọi lý do tiền, `ValidatesBillingInput::validatedReason()`).
 *  5. Không còn khoản thu CHƯA HUỶ nào ({@see ContractNotRevertible::hasPayments()}): tiền đã về gắn với
 *     hợp đồng đã ký; kế toán huỷ khoản thu (ghi lý do) trước. Khoản thu ĐÃ HUỶ không chặn — nó ở lại
 *     trên đợt của nó làm lịch sử (`UpdateDraftContract` giữ đợt đó khi sửa bản nháp).
 *  6. Chưa có phụ lục nào ({@see ContractNotRevertible::hasAmendments()}).
 *  7. Không đợt nào đang không `pending` (đã miễn; đã thu đủ thì bước 5 đã chặn) và không đợt nào TỪNG
 *     được miễn, bỏ miễn hay dời hạn — đọc từ nhật ký của chính các đợt đó
 *     ({@see ContractNotRevertible::hasCorrections()}): đó là quyết định đã ghi trên hợp đồng đã ký.
 *
 * Ghi: `status = draft`, `signed_at` và `activated_by` về rỗng; mọi đợt `on_signing` và `stage` mất hạn
 * và dấu kích hoạt (`due_date`, `triggered_at`, `triggered_by_stage_log_id` về rỗng — hạn của chúng tính từ
 * ngày ký hay lần chạm giai đoạn, sẽ tính lại ở lần kích hoạt sau); đợt `due_date` giữ ngày cố định của nó.
 * Giá trị, thuế suất, ghi chú và lịch thu không đổi — sửa chúng là việc của "Sửa bản nháp".
 * `Audit::record('contract_reverted_to_draft', …, $actor)` bên trong transaction, mang lý do, ngày ký và
 * người kích hoạt cũ.
 *
 * Cổng khách: hợp đồng nháp không ra cổng (`ContractPolicy::view` của khách chỉ nhận `active`/`completed`),
 * nên lộ trình sai thôi hiện ngay khi commit.
 *
 * **Sau commit:** luật sư phụ trách nhận một dòng chuông qua {@see NotifyLeadLawyerOfContractEntry}
 * (`REVERTED`) — cùng quy ước của chuông nhập hợp đồng: không báo chính người làm; luật sư phụ trách tự làm
 * trên vụ mình thì không ai. Chuông không chép lý do (nằm ở nhật ký).
 */
class RevertContractToDraft
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;
    use ValidatesBillingInput;

    /** Sự kiện nhật ký của một đợt cho thấy hợp đồng đã được xử lý sau khi ký (bước 7). */
    public const CORRECTION_EVENTS = ['instalment_waived', 'instalment_unwaived', 'instalment_rescheduled'];

    public function __construct(private NotifyLeadLawyerOfContractEntry $notifier) {}

    public function handle(User $actor, Contract $contract, string $reason): Contract
    {
        $reverted = $this->inContractTransaction(
            (int) $contract->getKey(),
            fn (Matter $lockedMatter, Contract $locked): Contract => $this->revertLocked($actor, $lockedMatter, $locked, $reason),
        );

        $this->notifier->handle($actor, $reverted, NotifyLeadLawyerOfContractEntry::REVERTED);

        return $reverted;
    }

    private function revertLocked(User $actor, Matter $lockedMatter, Contract $locked, string $reason): Contract
    {
        Gate::forUser($actor)->authorize('revertToDraft', $locked);

        if ($locked->status !== ContractStatus::Active) {
            throw ContractStatusConflict::notActiveForRevert($locked);
        }

        if ($lockedMatter->isClosed()) {
            throw ContractNotRevertible::matterClosed($locked, $lockedMatter);
        }

        $reason = $this->validatedReason($reason);

        $instalments = $this->scopelessly(Instalment::query())
            ->where('contract_id', $locked->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        $collected = array_sum($this->lockedCollectedAmounts($instalments->modelKeys()));

        if ($collected > 0) {
            throw ContractNotRevertible::hasPayments($locked, $collected);
        }

        $hasAmendment = $this->scopelessly(ContractAmendment::query())
            ->where('contract_id', $locked->id)
            ->lockForUpdate()
            ->exists();

        if ($hasAmendment) {
            throw ContractNotRevertible::hasAmendments($locked);
        }

        $this->refuseIfCorrected($locked, $instalments);

        $previous = [
            'signed_at' => $locked->signed_at?->toDateString(),
            'activated_by' => $locked->activated_by,
        ];

        $locked->fill([
            'status' => ContractStatus::Draft,
            'signed_at' => null,
            'activated_by' => null,
        ]);
        $locked->blameOn($actor)->save();

        $reset = 0;

        foreach ($instalments as $instalment) {
            if ($instalment->trigger_type === InstalmentTrigger::DueDate) {
                continue;
            }

            $instalment->fill([
                'due_date' => null,
                'triggered_at' => null,
                'triggered_by_stage_log_id' => null,
            ]);

            if ($instalment->isDirty()) {
                $instalment->blameOn($actor)->save();
                $reset++;
            }
        }

        Audit::record('contract_reverted_to_draft', $locked, [
            'code' => $locked->code,
            'reason' => $reason,
            'previous_signed_at' => $previous['signed_at'],
            'previous_activated_by' => $previous['activated_by'],
            'total_amount' => $locked->total_amount,
            'instalments_reset' => $reset,
        ], $actor);

        return $locked->refresh();
    }

    /**
     * Bước 7 của docblock lớp: một đợt không còn `pending`, hay một dòng nhật ký miễn / bỏ miễn / dời hạn
     * trên chính các đợt của hợp đồng này. Nhật ký đọc thường sau khi đã khoá `matters` → `contracts` →
     * `instalments`: mọi Action ghi các dòng đó khoá cùng chuỗi trước.
     *
     * @param  iterable<Instalment>  $instalments
     */
    private function refuseIfCorrected(Contract $locked, iterable $instalments): void
    {
        $byId = [];

        foreach ($instalments as $instalment) {
            if ($instalment->status !== InstalmentStatus::Pending) {
                throw ContractNotRevertible::hasCorrections($locked, $instalment->name);
            }

            $byId[$instalment->getKey()] = $instalment;
        }

        if ($byId === []) {
            return;
        }

        $corrected = Activity::query()
            ->where('subject_type', (new Instalment)->getMorphClass())
            ->whereIn('subject_id', array_keys($byId))
            ->whereIn('event', self::CORRECTION_EVENTS)
            ->orderBy('id')
            ->value('subject_id');

        if ($corrected !== null) {
            throw ContractNotRevertible::hasCorrections($locked, $byId[(int) $corrected]->name);
        }
    }
}
