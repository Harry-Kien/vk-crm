<?php

namespace App\Actions\Billing;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\ContractEnteredByColleague;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\Notification;

/**
 * Làn kt (2026-10-15, SPEC §5 "Bổ sung 2026-10-15"): khi một người KHÔNG phải luật sư phụ trách của vụ
 * — kế toán qua trang "Hợp đồng", hay trưởng phòng/quản trị viên — nhập ({@see DraftContract}), kích
 * hoạt ({@see ActivateContract}) hay nhập-và-kích-hoạt ({@see EnterSignedContract}) hợp đồng của vụ,
 * luật sư phụ trách nhận MỘT dòng chuông {@see ContractEnteredByColleague} để kiểm lại con số.
 *
 * Làn fa3 (2026-10-16, mục 2 + 5): cùng chuông, cùng người nhận cho ba việc nữa trên hợp đồng của vụ —
 * đưa một hợp đồng kích hoạt nhầm về bản nháp ({@see RevertContractToDraft}, `REVERTED`), sửa bản nháp
 * ({@see UpdateDraftContract}, `DRAFT_UPDATED`) và xoá bản nháp ({@see DeleteDraftContract},
 * `DRAFT_DELETED`). Hợp đồng vừa xoá không còn đợt để đếm, nên người gọi truyền số đợt đã xoá
 * (`$instalmentCount`); mọi việc khác đếm đợt hiện có.
 *
 * - **Gọi SAU khi transaction tiền đã commit** — các Action trên gọi lớp này sau `moneyTransaction()`,
 *   không bên trong nó: một lần chạy lại transaction (xung đột khoá, `LocksBillingRows`) không gửi hai
 *   lần, và một lần rollback không để lại chuông về một hợp đồng không tồn tại.
 * - **Người nhận (gộp integ-4, mục D — sửa khoảng hở m7 của làn kt):** luật sư phụ trách, khi họ còn
 *   nhận được chuông về tiền của vụ (`ResolveStaffRecipients::qualifiesForBilling()`: đang hoạt động, chưa
 *   xoá mềm, thấy tiền của vụ) VÀ không phải chính người nhập. Không thì MỌI người quản lý của vụ
 *   (`ResolveStaffRecipients::supervisorsFor()`: trưởng phòng; quản trị viên với vụ hạn chế) còn thấy tiền
 *   của vụ (cùng cổng `qualifiesForBilling()` — chuông mang số tiền) TRỪ người nhập. Không ai nhận chuông về việc chính mình vừa làm. Luật sư phụ trách tự nhập hợp đồng của vụ mình
 *   (việc hằng ngày) thì KHÔNG ai nhận — báo mọi trưởng phòng mỗi lần như vậy chỉ là tiếng ồn; người quản
 *   lý chỉ là người nhận dự phòng khi luật sư phụ trách không nhận được. Người nhập là trưởng phòng của một
 *   vụ mất người phụ trách thì các trưởng phòng khác nhận. Trước bản sửa, chuỗi dự phòng của `forBilling()` trả ĐÚNG MỘT người,
 *   và khi người đó lại là người nhập thì không ai nhận gì. Rỗng khi luật sư phụ trách tự nhập vụ mình
 *   (có chủ đích), hay khi người nhập là người quản lý DUY NHẤT của vụ — nhật ký hoạt động vẫn ghi người
 *   nhập (`Audit::record(..., $actor)` ở Action).
 * - Vụ `restricted`: kế toán không bao giờ tới đây (cổng tiền đã từ chối trong Action); quản trị viên
 *   nhập thì luật sư phụ trách — người duy nhất ngoài quản trị viên thấy tiền của vụ — nhận chuông, và
 *   khi họ không còn hoạt động thì các quản trị viên khác nhận, không bao giờ trưởng phòng.
 *
 * Nhật ký hoạt động ghi người nhập ở chính Action (`Audit::record(..., $actor)`), không ở đây.
 */
class NotifyLeadLawyerOfContractEntry
{
    use ReadsWithoutPortalScope;

    public const DRAFTED = 'drafted';

    public const ACTIVATED = 'activated';

    public const ENTERED = 'entered';

    /** Làn fa3: hợp đồng kích hoạt nhầm vừa được đưa về bản nháp ({@see RevertContractToDraft}). */
    public const REVERTED = 'reverted';

    /** Làn fa3: bản nháp vừa được sửa ({@see UpdateDraftContract}). */
    public const DRAFT_UPDATED = 'draft_updated';

    /** Làn fa3: bản nháp vừa bị xoá ({@see DeleteDraftContract}) — người gọi truyền số đợt đã xoá. */
    public const DRAFT_DELETED = 'draft_deleted';

    public function __construct(private readonly ResolveStaffRecipients $recipients) {}

    /** @param  int|null  $instalmentCount  số đợt để nói trong chuông; `null` = đếm đợt hiện có của hợp đồng */
    public function handle(User $actor, Contract $contract, string $event, ?int $instalmentCount = null): void
    {
        $matter = $this->scopelessly(Matter::query())->find($contract->matter_id);

        // Vụ vừa bị huỷ (xoá mềm) giữa lúc commit và lúc báo: không còn ai để báo về nó.
        if ($matter === null) {
            return;
        }

        $lead = $matter->leadLawyer;

        // Luật sư phụ trách tự nhập hợp đồng của chính vụ mình: việc hằng ngày, không có "đồng nghiệp nhập
        // hộ" nào cần kiểm lại — không ai nhận chuông (phán quyết controller khi gộp integ-4).
        if ($lead instanceof User && $lead->is($actor)) {
            return;
        }

        $recipients = $lead instanceof User && $this->recipients->qualifiesForBilling($lead, $matter)
            ? collect([$lead])
            : $this->recipients->supervisorsFor($matter)
                ->reject(fn (User $user): bool => $user->is($actor))
                ->filter(fn (User $user): bool => $this->recipients->qualifiesForBilling($user, $matter))
                ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        // Vai trò (quyền thật của người nhập), không chức danh: hai thứ trùng nhau khi tài khoản được
        // dựng qua `assignRoleFromPosition()`, nhưng câu "Kế toán X đã nhập…" nói về quyền đã dùng.
        $role = Role::tryFrom((string) $actor->getRoleNames()->first());
        $who = trim(($role?->label() ?? '').' '.$actor->name);

        Notification::send($recipients, new ContractEnteredByColleague($event, [
            'who' => $who,
            'contract' => $contract->code,
            'matter' => $matter->code,
            'total' => Money::format($contract->total_amount),
            'count' => $instalmentCount ?? $contract->instalments()->count(),
            'date' => $contract->signed_at?->format('d/m/Y') ?? '—',
        ], (int) $matter->getKey(), (int) $contract->getKey()));
    }
}
