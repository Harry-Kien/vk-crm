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
 * - **Gọi SAU khi transaction tiền đã commit** — ba Action trên gọi lớp này sau `moneyTransaction()`,
 *   không bên trong nó: một lần chạy lại transaction (xung đột khoá, `LocksBillingRows`) không gửi hai
 *   lần, và một lần rollback không để lại chuông về một hợp đồng không tồn tại.
 * - **Người nhận:** `ResolveStaffRecipients::forBilling($matter, [luật sư phụ trách])` — luật sư phụ
 *   trách khi họ còn hoạt động và còn thấy tiền của vụ; không thì chuỗi dự phòng R3 của chính lớp đó
 *   (một trưởng phòng, rồi một quản trị viên thấy tiền của vụ). Người vừa làm việc đó bị bỏ khỏi danh
 *   sách: không ai nhận chuông về việc chính mình vừa làm — nên luật sư phụ trách tự nhập thì không ai
 *   nhận gì (một điều kiện, không phải hai: phép thử ngược cho thấy một lần hỏi "người nhập có phải
 *   luật sư phụ trách không" đứng trước nó không đổi được kết quả nào).
 * - **Khoảng hở đã biết (đợt polish, m7 — chỉ ghi lại, chưa sửa):** chuỗi dự phòng trả ĐÚNG MỘT người
 *   (trưởng phòng đầu tiên thấy tiền của vụ, không có thì quản trị viên đầu tiên). Khi luật sư phụ
 *   trách đã nghỉ/không còn thấy tiền VÀ người nhập lại chính là người đó, bước bỏ người nhập để lại
 *   danh sách RỖNG: không ai nhận chuông — chuỗi không đi tiếp tới người kế. Hiếm (người dự phòng tự
 *   nhập hợp đồng của một vụ mất người phụ trách) và không mất dữ liệu (nhật ký hoạt động vẫn ghi người
 *   nhập). Sửa đúng cần một phương thức của `ResolveStaffRecipients` (dự phòng trừ một người), tệp của
 *   làn tk — để sau khi gộp hai làn.
 * - Vụ `restricted`: kế toán không bao giờ tới đây (cổng tiền đã từ chối trong Action); quản trị viên
 *   nhập thì luật sư phụ trách — người duy nhất ngoài quản trị viên thấy tiền của vụ — nhận chuông.
 *
 * Nhật ký hoạt động ghi người nhập ở chính Action (`Audit::record(..., $actor)`), không ở đây.
 */
class NotifyLeadLawyerOfContractEntry
{
    use ReadsWithoutPortalScope;

    public const DRAFTED = 'drafted';

    public const ACTIVATED = 'activated';

    public const ENTERED = 'entered';

    public function __construct(private readonly ResolveStaffRecipients $recipients) {}

    public function handle(User $actor, Contract $contract, string $event): void
    {
        $matter = $this->scopelessly(Matter::query())->find($contract->matter_id);

        // Vụ vừa bị huỷ (xoá mềm) giữa lúc commit và lúc báo: không còn ai để báo về nó.
        if ($matter === null) {
            return;
        }

        $recipients = $this->recipients
            ->forBilling($matter, [$matter->leadLawyer])
            ->reject(fn (User $user): bool => $user->is($actor))
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
            'count' => $contract->instalments()->count(),
            'date' => $contract->signed_at?->format('d/m/Y') ?? '—',
        ], (int) $matter->getKey(), (int) $contract->getKey()));
    }
}
