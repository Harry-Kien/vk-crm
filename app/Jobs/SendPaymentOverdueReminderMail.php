<?php

namespace App\Jobs;

use App\Actions\Billing\RemindClientOfOverdueInstalment;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindOverdueInstalments;
use App\Enums\Role;
use App\Mail\Client\PaymentReminder;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ClientPaymentReminders;
use App\Support\Billing\ClientPaymentSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư nhắc đợt quá hạn `client.payment_reminder.overdue` (làn nt Task 2) — job mà
 * {@see RemindClientOfOverdueInstalment} xếp `->afterCommit()` khi người quản lý hợp đồng bấm "Gửi thư
 * nhắc" trên dòng đợt đó.
 *
 * **Payload:** id đợt, ngày hạn lúc bấm, NGÀY bấm (khoá chống trùng `overdue:{đợt}@{ngày bấm}`) và id
 * người bấm (để báo lại khi thư hỏng hẳn) — không tiền, không tên, không địa chỉ (SPEC §10.5).
 *
 * **Lúc chạy đọc lại tất cả:**
 *  1. **công tắc còn bật** — tắt giữa lúc bấm và lúc gửi thì thư KHÔNG đi (phán quyết của brief);
 *  2. đợt VẪN quá hạn — {@see RemindOverdueInstalments::candidates()}, đúng một định nghĩa "quá hạn" của
 *     hệ thống cộng "vụ chưa xoá mềm": khách vừa trả đủ, đợt vừa được miễn, hợp đồng vừa huỷ thì thôi;
 *  3. `due_date` hiện tại BẰNG ngày lúc bấm — đợt vừa được "Dời hạn" thì lời nhắc nói một ngày cũ, bỏ;
 *  4. người nhận tính lại ({@see ClientPaymentReminders::recipients()}).
 *
 * **Không gửi trùng khi thử lại:** mỗi người hỏi {@see ClientPaymentReminders::alreadyDelivered()} ngay
 * trước thư của mình. Một người hỏng không chặn người sau; ngoại lệ đầu tiên ném lại để hàng đợi thử lại.
 *
 * **Hỏng hẳn** ({@see self::failed()}): dòng nhật ký `client_payment_reminder_failed` trên đợt và chuông
 * cho NGƯỜI ĐÃ BẤM — họ đã thấy "đã xếp hàng gửi" và tin thư đã đi. Người nhận chuông qua
 * {@see ResolveStaffRecipients::forBilling()} (R3: người bấm nếu còn hoạt động và còn thấy tiền của vụ,
 * không thì chuỗi dự phòng; vụ hạn chế không lộ mã cho người không xem được). Bấm lại được vào ngày hôm
 * sau (một lần mỗi đợt mỗi ngày).
 */
class SendPaymentOverdueReminderMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $instalmentId,
        public readonly string $dueDate,
        public readonly string $requestedOn,
        public readonly int $actorId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if (! ClientPaymentSettings::enabled()) {
            return;
        }

        /** @var Instalment|null $instalment */
        $instalment = RemindOverdueInstalments::candidates()->whereKey($this->instalmentId)->first();

        if ($instalment === null || $instalment->due_date->toDateString() !== $this->dueDate) {
            return;
        }

        $matter = $instalment->contract->matter;
        $key = ClientPaymentReminders::overdueKey($instalment, $this->requestedOn);
        $failure = null;

        foreach (ClientPaymentReminders::recipients($matter) as $recipient) {
            if (ClientPaymentReminders::alreadyDelivered(PaymentReminder::KIND_OVERDUE, $key, $recipient)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(
                    ClientPaymentReminders::mailFor(PaymentReminder::KIND_OVERDUE, $instalment, $matter, $recipient, $key),
                );
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function failed(?Throwable $exception): void
    {
        /** @var Instalment|null $instalment */
        $instalment = Instalment::query()->withoutGlobalScopes()->find($this->instalmentId);
        $matterId = $instalment?->contract()->withoutGlobalScopes()->value('matter_id');
        /** @var Matter|null $matter */
        $matter = $matterId === null ? null : Matter::query()->withoutGlobalScopes()->find($matterId);
        /** @var User|null $actor */
        $actor = User::query()->find($this->actorId);

        Audit::record('client_payment_reminder_failed', $instalment, [
            'matter_id' => $matterId,
            'requested_on' => $this->requestedOn,
            'requested_by' => $this->actorId,
        ]);

        $resolver = app(ResolveStaffRecipients::class);
        $recipients = $matter !== null
            ? $resolver->forBilling($matter, [$actor])
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(__('client_payments.remind_action.failed_notification.title'))
                ->body(__('client_payments.remind_action.failed_notification.body', [
                    'code' => e($matter->code ?? ''),
                ]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }
}
