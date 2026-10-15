<?php

namespace App\Support\Billing;

use App\Actions\Billing\RemindClientOfOverdueInstalment;
use App\Actions\Notification\ResolveClientRecipients;
use App\Actions\Schedule\RemindClientsOfUpcomingInstalments;
use App\Enums\OutboundStatus;
use App\Jobs\SendPaymentDueSoonMail;
use App\Jobs\SendPaymentOverdueReminderMail;
use App\Mail\Client\PaymentReminder;
use App\Models\ClientUser;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Phần DÙNG CHUNG của hai thư nhắc tiền cho khách (làn nt Task 2) — một định nghĩa cho lịch, hai job,
 * Action của nút "Gửi thư nhắc" và hộp thoại xem trước, để "ai nhận", "đã gửi chưa" và "thư nói gì"
 * không nằm hai chỗ:
 *
 *  - {@see self::recipients()} — R12 rồi "cổng còn cho xem vụ" (hai bước của cả sáu thư khách-về-một-vụ,
 *    `ResolveClientRecipients::onPortal()`): thư mang mã hồ sơ, nên không tới người cổng từ chối cho xem
 *    vụ (vụ văn phòng giữ ngoài cổng, vụ đã hết hạn tra cứu) — kể cả với vụ hạn chế.
 *  - {@see self::dueSoonKey()} / {@see self::overdueKey()} — khoá chống trùng trong sổ thư
 *    (`payload.tier`, header `X-VKCRM-Ledger-Tier`): báo trước hạn theo đợt + NGÀY HẠN (đổi số ngày báo
 *    trước không thành thư thứ hai; "Dời hạn" sang ngày khác thì khách được báo cho ngày mới); nhắc quá
 *    hạn theo đợt + NGÀY BẤM GỬI (một lần mỗi ngày — và lần thử lại của cùng một lần bấm không gửi trùng).
 *  - {@see self::alreadyDelivered()} — chỉ dòng `sent` chặn (M6 R3): dòng `failed` là thư chưa tới.
 *  - {@see self::mailFor()} — dựng ĐÚNG thư sẽ gửi; job gửi nó, hộp thoại xem trước in bản chữ của nó.
 *  - {@see self::sentToday()} — dòng nhật ký `client_payment_reminder_sent` của đợt này trong HÔM NAY
 *    (giờ ứng dụng): mốc của giới hạn "một lần mỗi đợt mỗi ngày", ghi cùng transaction với lần bấm.
 *
 * Nơi dùng: {@see RemindClientsOfUpcomingInstalments}, {@see SendPaymentDueSoonMail},
 * {@see RemindClientOfOverdueInstalment}, {@see SendPaymentOverdueReminderMail} và
 * `BillingRelationManager::remindClientAction()`.
 */
final class ClientPaymentReminders
{
    /** Sự kiện nhật ký của một lần bấm "Gửi thư nhắc" — cũng là mốc của giới hạn một lần mỗi ngày. */
    public const SENT_EVENT = 'client_payment_reminder_sent';

    /** @return Collection<int, ClientUser> */
    public static function recipients(Matter $matter): Collection
    {
        $resolver = app(ResolveClientRecipients::class);

        return $resolver->onPortal($matter, $resolver->recipientsFor((int) $matter->client_id));
    }

    public static function dueSoonKey(Instalment $instalment, string $dueDate): string
    {
        return 'due-soon:'.$instalment->getKey().'@'.$dueDate;
    }

    public static function overdueKey(Instalment $instalment, string $requestedOn): string
    {
        return 'overdue:'.$instalment->getKey().'@'.$requestedOn;
    }

    /** Đã có dòng `sent` của mẫu `$kind`, khoá `$key`, tới đúng địa chỉ này chưa. */
    public static function alreadyDelivered(string $kind, string $key, ClientUser $recipient): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', PaymentReminder::templateFor($kind))
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('payload->tier', $key)
            ->exists();
    }

    /**
     * Thư `$kind` về đợt `$instalment` (đã nạp `contract`) của vụ `$matter` cho `$recipient`. Số tiền
     * là phần CÒN PHẢI TRẢ của đợt lúc dựng ({@see Instalment::outstanding()} — một định nghĩa "còn
     * nợ"), thông tin chuyển khoản đọc lúc dựng ({@see ClientPaymentSettings::transferAccount()}, `null`
     * khi thiếu — thư bỏ phần đó).
     */
    public static function mailFor(string $kind, Instalment $instalment, Matter $matter, ClientUser $recipient, string $key): PaymentReminder
    {
        return new PaymentReminder(
            $kind,
            $instalment,
            $matter,
            $recipient,
            $instalment->outstanding(),
            ClientPaymentSettings::current()->transferAccount(),
            $key,
        );
    }

    /** Lần bấm "Gửi thư nhắc" của đợt này trong hôm nay, mới nhất trước — `null` khi chưa có. */
    public static function sentToday(Instalment $instalment): ?Activity
    {
        /** @var Activity|null */
        return Activity::query()
            ->where('event', self::SENT_EVENT)
            ->where('subject_type', $instalment->getMorphClass())
            ->where('subject_id', $instalment->getKey())
            ->where('created_at', '>=', today()->startOfDay())
            ->latest('id')
            ->first();
    }

    /** Tên người đã bấm gửi của một dòng {@see self::sentToday()} (nhân sự đã xoá thì chuỗi trống). */
    public static function senderName(Activity $activity): string
    {
        $causer = $activity->causer;

        return $causer instanceof User ? (string) $causer->name : '';
    }
}
