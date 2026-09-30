<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindOverdueInstalments;
use App\Enums\OutboundStatus;
use App\Mail\Staff\InstalmentOverdue;
use App\Models\Instalment;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\Billing\AccountantBillingRow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Gửi thư nhắc đợt thanh toán quá hạn (`staff.instalment_overdue`) cho nhân sự — job hàng đợi mà
 * {@see RemindOverdueInstalments} xếp, theo khuôn `SendDeadlineReminderMail` của M6.5.
 *
 * **Payload chỉ mang id đợt + NGÀY ĐẾN HẠN mà lời nhắc nói tới** (SPEC §10.5: không mang tiền, tên
 * khách, tiêu đề vụ việc, người nhận). Mọi thứ khác đọc lại LÚC CHẠY, vì giữa lúc xếp job và lúc
 * nó chạy (hàng đợi trễ, hay `backoff()` sau một lần hỏng) sự thật có thể đã đổi — cùng ruling
 * "re-derive the audience at send time" và "queued mail jobs must re-check before sending":
 *  - đợt phải VẪN khớp {@see RemindOverdueInstalments::candidates()} — chưa thu đủ, miễn, huỷ; hợp
 *    đồng còn `active`; vụ việc chưa xoá mềm; ngày đến hạn vẫn còn trong quá khứ. Không khớp thì
 *    thoát im lặng: không ném lỗi (thư của một đợt đã thu đủ không phải một lỗi), không gửi.
 *  - `due_date` hiện tại phải BẰNG ngày job mang. Khác nghĩa là ai đó (phụ lục, đối chiếu giai
 *    đoạn) đã dời hạn: lời nhắc này nói về một "đợt quá hạn" cũ. Bỏ qua — lượt chạy kế tiếp của tác
 *    vụ thấy ngày mới và xếp một job mới mang ngày mới. Gửi thư nói ngày MỚI dưới khoá của ngày CŨ
 *    làm khoá chống trùng nói dối.
 *  - người nhận tính lại bằng {@see ResolveStaffRecipients::forBilling()}: một kế toán vào làm sau
 *    khi job được xếp vẫn nhận; một người bị vô hiệu hoá thì không.
 *
 * **Chống gửi trùng — {@see self::alreadyReminded()}, MỘT định nghĩa, theo TỪNG người nhận.**
 * Sổ thư `outbound_messages` (M6 R3) là trí nhớ: một dòng `sent` của (mẫu, đợt, người nhận, ngày
 * đến hạn) trong 7 ngày lịch gần nhất chặn thư mới. Kiểm TẠI ĐÂY, lúc gửi, không chỉ lúc xếp hàng:
 * hai job cho cùng một đợt cùng nằm trong hàng đợi (lượt chạy của hai ngày liền kề, hay một lượt
 * chạy tay) không thành hai thư. Cũng nhờ đó, khi `handle()` được thử lại sau một lần hỏng giữa
 * chừng, người ĐÃ nhận không nhận lại (ruling "no duplicate reminders on retry").
 *
 * **Khoá mang ngày đến hạn** (bài học M6.5 Task 14, C1): header nội bộ `X-VKCRM-Ledger-Tier` =
 * `overdue@<due_date>`, chép vào `payload->tier`. Nếu khoá chỉ theo 7 ngày, một phụ lục dời hạn sang
 * một ngày khác (vẫn đã qua) sẽ bị nuốt lời nhắc "ngày đầu tiên quá hạn" của lần mới; khoá mang
 * ngày thì hai ngày đến hạn khác nhau không chặn nhau.
 *
 * **Một người nhận hỏng không chặn người sau** (Final review X5, B-I1): mỗi người một `try`, giữ
 * ngoại lệ ĐẦU TIÊN, thử hết, rồi ném lại để hàng đợi thấy job hỏng và thử lại (`$tries`,
 * `backoff()`). Dòng `failed` do transport ghi là bằng chứng; chỉ dòng `sent` mới chặn, nên cả
 * lượt thử lại lẫn lượt chạy ngày mai đều gửi lại cho người hỏng.
 *
 * **Không có `failed()`.** Khác `SendDeadlineReminderMail` (một mốc kháng cáo bị lỡ là trách nhiệm
 * nghề nghiệp, nên hỏng hẳn phải bật chuông), một lời nhắc công nợ hỏng hẳn không mất gì: tác vụ
 * chạy lại mỗi ngày, đợt vẫn quá hạn thì ngày mai lại thử, và dòng `failed` nằm trong nhật ký thư.
 * Cũng vì thế không có rút-đánh-dấu-khỏi-cột nào cần hoàn lại: không có cột.
 *
 * **Không transaction, không khoá:** job chỉ đọc; `Mail::` chạy ngoài mọi `DB::transaction`
 * (M6.5 R2). Thứ tự khoá dự án (`matters` → `contracts` → `instalments` → `payments`) không áp dụng.
 */
class SendInstalmentOverdueMail implements ShouldQueue
{
    use Queueable;

    /** Nhịp nhắc lại: một dòng `sent` trong vòng bấy nhiêu NGÀY LỊCH (kể cả hôm nay) chặn thư mới. */
    public const REPEAT_EVERY_DAYS = 7;

    /** Thử tối đa năm lần, giãn dần — cùng `SendDeadlineReminderMail`. */
    public int $tries = 5;

    public function __construct(
        public readonly int $instalmentId,
        public readonly string $dueDate,
    ) {}

    /** @return list<int> Giây giữa các lần thử. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $instalment = RemindOverdueInstalments::candidates()->whereKey($this->instalmentId)->first();

        // Đã thu đủ / miễn / huỷ, hợp đồng hết hiệu lực, vụ xoá mềm, hay hết quá hạn giữa lúc chờ.
        if ($instalment === null) {
            return;
        }

        // Ngày đến hạn đã đổi: lời nhắc này thuộc về một "đợt quá hạn" cũ — xem docblock lớp.
        if ($instalment->due_date->toDateString() !== $this->dueDate) {
            return;
        }

        $matter = $instalment->contract->matter;
        $resolver = app(ResolveStaffRecipients::class);

        // Tính lại TOÀN BỘ người nhận tại thời điểm gửi.
        $recipients = $resolver->forBilling($matter, $resolver->billingAudienceFor($matter)->all());

        $row = AccountantBillingRow::fromInstalment(
            $instalment,
            (int) $instalment->getAttribute('collected_amount'),
            (int) $instalment->getAttribute('outstanding_amount'),
            $instalment->state(),
        );

        $failure = null;

        foreach ($recipients as $recipient) {
            // Bỏ qua NGƯỜI NÀY, không phải cả lượt: người khác có thể vẫn chưa nhận được.
            if (self::alreadyReminded($instalment, $recipient, $this->dueDate)) {
                continue;
            }

            try {
                Mail::to($recipient->email)->send(new InstalmentOverdue($instalment, $row, $recipient));
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * `$recipient` đã được nhắc về đợt này (cho đúng ngày đến hạn `$dueDate`) trong
     * {@see self::REPEAT_EVERY_DAYS} ngày lịch gần nhất chưa — MỘT định nghĩa, dùng ở tác vụ (xếp
     * job) và ở job (gửi).
     *
     * Chỉ dòng `status = sent` tính (M6 R3): dòng `queued` là thư chưa đi, dòng `failed` là thư
     * chưa tới — cả hai phải được gửi lại. So theo NGÀY lịch (giờ ứng dụng), không theo giờ: gửi lúc
     * 23:30 ngày 1 vẫn chặn tới hết ngày 7 và không chặn ngày 8 dù chưa đủ 168 giờ. Cửa sổ là
     * `hôm nay − 6 ngày` → mốc 00:00 đó; ngày gửi cách hôm nay ≥ 7 ngày lịch thì nằm ngoài.
     */
    public static function alreadyReminded(Instalment $instalment, User $recipient, string $dueDate): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('template', InstalmentOverdue::TEMPLATE)
            ->where('related_type', $instalment->getMorphClass())
            ->where('related_id', $instalment->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('payload->tier', InstalmentOverdue::ledgerTier($dueDate))
            ->where('sent_at', '>=', today()->subDays(self::REPEAT_EVERY_DAYS - 1)->startOfDay())
            ->exists();
    }
}
