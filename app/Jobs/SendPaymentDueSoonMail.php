<?php

namespace App\Jobs;

use App\Actions\Schedule\RemindClientsOfUpcomingInstalments;
use App\Mail\Client\PaymentReminder;
use App\Models\Instalment;
use App\Support\Billing\ClientPaymentReminders;
use App\Support\Billing\ClientPaymentSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư báo trước hạn `client.payment_reminder.due_soon` (làn nt Task 2) — job mà
 * {@see RemindClientsOfUpcomingInstalments} xếp `->afterCommit()` mỗi sáng.
 *
 * **Payload chỉ mang id đợt + ngày hạn** (SPEC §10.5). Lúc chạy đọc lại TẤT CẢ, theo thứ tự:
 *  1. **công tắc còn bật** — văn phòng tắt giữa lúc xếp và lúc gửi thì thư đã xếp KHÔNG đi (phán quyết
 *     của brief: tắt công tắc có hiệu lực với cả thư đang chờ trong hàng đợi);
 *  2. đợt VẪN khớp {@see RemindClientsOfUpcomingInstalments::candidates()} với số ngày báo trước HIỆN
 *     HÀNH — chưa thu đủ, chưa miễn/huỷ, hợp đồng còn `active`, vụ chưa xoá mềm, hạn chưa qua;
 *  3. `due_date` hiện tại BẰNG ngày job mang — khác nghĩa là đợt đã được dời hạn: thư này nói một ngày
 *     cũ, bỏ; lượt sáng kế tiếp báo cho ngày mới dưới khoá mới;
 *  4. người nhận tính lại ({@see ClientPaymentReminders::recipients()}).
 *
 * **Không gửi trùng:** mỗi người hỏi {@see ClientPaymentReminders::alreadyDelivered()} (khoá
 * `due-soon:{đợt}@{ngày hạn}`) ngay trước thư của mình — job chạy lại hay hai job cùng đợt trong hàng
 * đợi không thành hai thư. Một người hỏng không chặn người sau; ngoại lệ đầu tiên ném lại sau khi thử
 * hết, để hàng đợi thử lại (`$tries`, `backoff()`).
 *
 * **Không `failed()`:** cùng lý do `SendInstalmentOverdueMail` — hỏng hẳn thì dòng `failed` nằm trong sổ
 * thư, và lượt 08:15 hôm sau gửi lại khi đợt còn trong cửa sổ báo trước (chỉ dòng `sent` chặn). Đợt đã
 * tới hạn mà thư vẫn chưa tới được khách thì khối "Lộ trình thanh toán" trên cổng vẫn hiện đợt đó.
 *
 * Không transaction, không khoá: job chỉ đọc; `Mail::` chạy ngoài mọi `DB::transaction` (R2).
 */
class SendPaymentDueSoonMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $instalmentId,
        public readonly string $dueDate,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $settings = ClientPaymentSettings::current();

        if (! $settings->isEnabled()) {
            return;
        }

        /** @var Instalment|null $instalment */
        $instalment = RemindClientsOfUpcomingInstalments::candidates($settings->daysBeforeDue())
            ->whereKey($this->instalmentId)
            ->first();

        if ($instalment === null || $instalment->due_date->toDateString() !== $this->dueDate) {
            return;
        }

        $matter = $instalment->contract->matter;
        $key = ClientPaymentReminders::dueSoonKey($instalment, $this->dueDate);
        $failure = null;

        foreach (ClientPaymentReminders::recipients($matter) as $recipient) {
            if (ClientPaymentReminders::alreadyDelivered(PaymentReminder::KIND_DUE_SOON, $key, $recipient)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(
                    ClientPaymentReminders::mailFor(PaymentReminder::KIND_DUE_SOON, $instalment, $matter, $recipient, $key),
                );
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
