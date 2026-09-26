<?php

namespace App\Jobs;

use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * M6.5 Task 11 (`deadlines/F1`, `notify/notify-2`, `e2e/F3`) — việc gửi thư nhắc mốc thời hạn tách
 * KHỎI `CheckDeadlines::handle()`, đúng phán quyết R2 ("mọi thư đi qua hàng đợi, sau khi commit,
 * không bao giờ nằm trong transaction").
 *
 * **Trước Task 11.** `CheckDeadlines` gọi `Mail::to()->send()` ĐỒNG BỘ, NGAY BÊN TRONG
 * `DB::transaction()` của từng mốc. Một transport hỏng (SMTP chết, hộp thư một quản lý bị từ
 * chối) ném `TransportException` xuyên qua transaction: (1) transaction rollback xoá luôn dòng
 * `outbound_messages` vừa ghi — cả dòng `failed` của thư hỏng lẫn dòng `sent` của thư đã thật sự
 * tới người nhận trước đó trong CÙNG mốc; (2) ngoại lệ thoát khỏi vòng lặp `foreach`, nên mọi mốc
 * xếp sau (theo `due_date`, tức mốc gấp hơn) không bao giờ được xét trong lượt chạy đó.
 *
 * **Sau Task 11.** `CheckDeadlines` chỉ còn khoá mốc, tính bậc, ghi `reminders_sent` — TOÀN BỘ
 * bên trong transaction, không có gì gọi ra ngoài mạng — rồi dispatch job NÀY `afterCommit()`.
 * Job chỉ mang ID (SPEC §10.5: không mang định danh thô nào vào payload hàng đợi — `$recipientIds`
 * là khoá số, không phải tên hay số điện thoại), tự đọc lại mọi thứ từ CSDL lúc nó THẬT SỰ chạy.
 * Nhờ vậy: một transport hỏng chỉ làm HỎNG ĐÚNG MỘT lần gửi (dòng `outbound_messages` của nó ghi
 * `failed` — do `OutboundLedgerTransport`, một câu `update()` không nằm trong transaction nào của
 * job này, nên không có gì để rollback); các mốc khác không hề bị đụng tới, vì `CheckDeadlines` đã
 * đánh dấu `reminders_sent` VÀ commit XONG trước khi job này thậm chí được nhắc tới.
 *
 * **Vì sao `reminders_sent` được đánh dấu TRƯỚC khi job này chạy, không phải sau khi gửi thành
 * công.** Đây là chỗ "chỉ đánh dấu khi thư đã được xếp hàng" của brief: chống gửi trùng bây giờ
 * nghĩa là "mốc này đã có một job xếp hàng đi gửi nó", không phải "mốc này đã thật sự tới hộp thư
 * người nhận". Hệ quả cần biết: nếu job này thất bại HẲN (hết `$tries`), `reminders_sent` KHÔNG
 * tự rút lại — mốc đó không được nhắc lại ở bậc này nữa, dòng `outbound_messages` `failed` là bằng
 * chứng duy nhất còn lại. Đánh đổi có chủ ý: cách khác (chỉ đánh dấu sau khi gửi thành công) sẽ
 * lặp lại đúng lỗi cũ — CheckDeadlines phải chờ kết quả mạng bên trong transaction.
 *
 * **Đọc lại tại thời điểm chạy, không tin payload đã cũ** (kỷ luật giống hệt
 * `RecheckClientIdentityConflicts`, cũng của M6.5): giữa lúc job được xếp hàng và lúc nó THẬT SỰ
 * chạy (có thể trễ vài phút vì `queue:work --stop-when-empty` chỉ rút mỗi phút, hoặc trễ hàng giờ
 * nếu job phải `backoff()` sau một lần hỏng), vụ việc có thể đã bị huỷ (Task 5, `CancelMatter`) và
 * người nhận có thể đã bị khoá/nghỉ việc (R7). `handle()` re-check cả hai TRƯỚC khi gửi và BỎ QUA
 * (không gửi, không ném lỗi) người/mốc không còn hợp lệ — im lặng đúng nghĩa "vốn không nên gửi",
 * không phải một lần gửi thất bại.
 */
class SendDeadlineReminderMail implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    /**
     * @param  array<int, int>  $recipientIds  Khoá `users.id` — KHÔNG mang email hay tên vào payload hàng đợi.
     */
    public function __construct(
        public readonly int $deadlineId,
        public readonly array $recipientIds,
        public readonly string $tierKey,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $deadline = Deadline::query()->find($this->deadlineId);

        // Mốc đã bị gỡ (R14, xoá mềm kèm lý do) hoặc đã đánh dấu xong giữa lúc chờ tới lượt job.
        if ($deadline === null || $deadline->is_completed) {
            return;
        }

        // Vụ việc đã bị huỷ (CancelMatter, Task 5) hoặc đã đóng (R8) giữa lúc job xếp hàng và lúc
        // nó chạy — cùng định nghĩa DUY NHẤT `Matter::scopeOpen()` mà CheckDeadlines đã dùng để
        // dựng danh sách ứng viên, không viết lại nó lần nữa ở đây.
        if (! Matter::query()->whereKey($deadline->matter_id)->open()->exists()) {
            return;
        }

        // Re-check người nhận: `is_active` có thể đã đổi (nghỉ việc, R7) từ lúc CheckDeadlines
        // tính `recipientsFor()` tới lúc job này thật sự chạy. `SoftDeletes` mặc định của User đã
        // tự loại người đã xoá mềm khỏi truy vấn dưới đây.
        $recipients = User::query()
            ->whereKey($this->recipientIds)
            ->where('is_active', true)
            ->get();

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(new DeadlineReminder($deadline, $recipient, $this->tierKey));
        }
    }
}
