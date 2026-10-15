<?php

namespace App\Actions\Billing;

use App\Actions\Billing\Concerns\LocksBillingRows;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\CommunicationType;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Exceptions\ClientReminderRefused;
use App\Jobs\SendPaymentOverdueReminderMail;
use App\Models\ClientUser;
use App\Models\CommunicationLog;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ClientPaymentReminders;
use App\Support\Billing\ClientPaymentSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Nút "Gửi thư nhắc" trên dòng đợt QUÁ HẠN của tab "Hợp đồng và thanh toán" (làn nt Task 2): người quản
 * lý hợp đồng của vụ, sau khi xem trước đúng nội dung thư, gửi MỘT thư nhắc tới các tài khoản cổng của
 * khách. Quá hạn không bao giờ TỰ gửi gì cho khách (nhắc nội bộ 08:00 `RemindOverdueInstalments` giữ
 * nguyên); đây là đường duy nhất, và nó là quyết định của một người có tên.
 *
 * Trong MỘT transaction theo chuỗi khoá duy nhất ({@see LocksBillingRows}: `matters` → `contracts` → đúng
 * hàng `instalments`; câu đầu tiên là lần đọc có khoá):
 *  1. **Quyền:** `InstalmentPolicy::remindClient` (cùng trục miễn/dời hạn — `contract.manage` cộng thấy
 *     tiền của vụ; kế toán không) — từ chối bằng `AuthorizationException`, một câu chung (SPEC §10.10).
 *  2. **Công tắc** "Nhắc lịch thanh toán cho khách" bật ({@see ClientReminderRefused::switchedOff()}).
 *  3. Hợp đồng `active` và đợt đang QUÁ HẠN theo đúng định nghĩa của hệ thống (`Instalment::state()` —
 *     `pending`, hạn trước hôm nay, còn phải trả > 0) ({@see ClientReminderRefused::notOverdue()}).
 *  4. **Một lần mỗi đợt mỗi ngày:** đã có dòng `client_payment_reminder_sent` của đợt này hôm nay thì từ
 *     chối, nêu giờ và người đã gửi ({@see ClientReminderRefused::alreadySentToday()}). Đọc SAU khoá
 *     `matters`, nên hai lần bấm cùng lúc xếp hàng ở khoá và lần thứ hai thấy dòng của lần thứ nhất.
 *  5. **Người nhận** ({@see ClientPaymentReminders::recipients()}: R12 + cổng còn cho xem vụ); không ai
 *     thì từ chối ({@see ClientReminderRefused::noRecipients()}) — không ghi gì.
 *  6. Ghi **sổ liên lạc của vụ** (một dòng `communication_logs` loại thư điện tử, người ghi là người bấm,
 *     lúc bấm, nội dung CHỈ nêu địa chỉ người nhận; nội bộ — không bao giờ ra cổng) và **nhật ký**
 *     `client_payment_reminder_sent` trên đợt (người gây ra, ngày hạn, số còn phải trả, `recipients` là
 *     địa chỉ thư nhận). Hai dòng này là "ai gửi, lúc nào, gửi cho ai" của lần bấm; kết quả TỪNG thư nằm
 *     ở sổ thư (tab "Thư đã gửi"). Tên đợt, số tiền và hạn KHÔNG vào sổ liên lạc: tab "Liên lạc" mở cho
 *     mọi người xem được vụ (kể cả trợ lý trong đội, không có `billing.view`), còn dòng nhật ký tiền chỉ
 *     hiện với người có `billing.view`.
 *
 * Sau khi transaction commit (không bao giờ bên trong — luật của {@see LocksBillingRows}: transaction tiền
 * có thể chạy lại): xếp {@see SendPaymentOverdueReminderMail} `->afterCommit()` (R2) với id đợt, ngày hạn,
 * ngày bấm và id người bấm. Job tính lại mọi thứ lúc chạy — công tắc vừa tắt, khách vừa trả đủ, hay đợt
 * vừa được dời hạn thì thư không đi. Dưới hàng đợi `sync`, lỗi của transport được `report()` chứ không
 * thành trang lỗi: lần bấm đã được ghi, sổ thư mang dòng `failed`.
 */
class RemindClientOfOverdueInstalment
{
    use LocksBillingRows;
    use ReadsWithoutPortalScope;

    /**
     * @return Collection<int, ClientUser> những tài khoản đã được xếp thư
     */
    public function handle(User $actor, Instalment $instalment): Collection
    {
        [$dueDate, $recipients] = $this->inInstalmentTransaction((int) $instalment->getKey(), function (Matter $lockedMatter, Contract $lockedContract, Instalment $lockedInstalment) use ($actor): array {
            Gate::forUser($actor)->authorize('remindClient', $lockedInstalment);

            if (! ClientPaymentSettings::enabled()) {
                throw ClientReminderRefused::switchedOff();
            }

            $lockedInstalment->setRelation('contract', $lockedContract);

            if ($lockedContract->status !== ContractStatus::Active || $lockedInstalment->state() !== InstalmentState::Overdue) {
                throw ClientReminderRefused::notOverdue();
            }

            $already = ClientPaymentReminders::sentToday($lockedInstalment);

            if ($already !== null) {
                throw ClientReminderRefused::alreadySentToday(
                    $already->created_at->format('H:i'),
                    ClientPaymentReminders::senderName($already),
                );
            }

            $recipients = ClientPaymentReminders::recipients($lockedMatter);

            if ($recipients->isEmpty()) {
                throw ClientReminderRefused::noRecipients();
            }

            $this->record($actor, $lockedMatter, $lockedInstalment, $recipients);

            return [$lockedInstalment->due_date->toDateString(), $recipients];
        });

        try {
            SendPaymentOverdueReminderMail::dispatch(
                (int) $instalment->getKey(),
                $dueDate,
                today()->toDateString(),
                (int) $actor->getKey(),
            )->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }

        return $recipients;
    }

    /** @param  Collection<int, ClientUser>  $recipients */
    private function record(User $actor, Matter $matter, Instalment $instalment, Collection $recipients): void
    {
        $addresses = $recipients->pluck('email')->sort()->values()->all();
        $outstanding = $instalment->outstanding();
        $due = $instalment->due_date;

        $log = new CommunicationLog([
            'matter_id' => $matter->getKey(),
            'type' => CommunicationType::Email,
            'occurred_at' => now(),
            'duration_minutes' => null,
            // Cột NOT NULL `string(200)`; tên khách hàng là `clients.name` `string(200)` — luôn vừa.
            'counterpart' => (string) $matter->client()->withoutGlobalScopes()->value('name'),
            // Chỉ "gửi cho ai" (ai gửi, lúc nào là `created_by`, `occurred_at`): tab "Liên lạc" mở cho mọi
            // người xem được vụ, kể cả trợ lý không có `billing.view` — không tên đợt, số tiền hay hạn ở đây.
            'summary' => __('client_payments.remind_action.communication_summary', [
                'recipients' => implode(', ', $addresses),
            ]),
            'is_visible_to_client' => false,
        ]);

        $log->blameOn($actor)->save();

        Audit::record(ClientPaymentReminders::SENT_EVENT, $instalment, [
            'matter_id' => $matter->getKey(),
            'communication_log_id' => $log->getKey(),
            'due_date' => $due->toDateString(),
            'outstanding' => $outstanding,
            'recipients' => $addresses,
        ], $actor);
    }
}
