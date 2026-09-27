<?php

namespace App\Jobs;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Role;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
 * người nhận". Đánh đổi có chủ ý: cách khác (chỉ đánh dấu sau khi gửi thành công) sẽ lặp lại đúng
 * lỗi cũ — CheckDeadlines phải chờ kết quả mạng bên trong transaction.
 *
 * # Vòng sửa 1, C1 (critical): job hỏng HẲN không được để mốc mất vĩnh viễn
 *
 * Bản Task 11 gốc để nguyên hệ quả của đánh đổi trên: nếu job hỏng HẲN (hết `$tries`), không có
 * gì rút `reminders_sent` lại, nên mốc đó KHÔNG BAO GIỜ được nhắc lại ở bậc này — dòng
 * `outbound_messages` `failed` là bằng chứng duy nhất, và không ai chủ động đọc nó (chưa có
 * trang xem nhật ký thư, `spec-gap-07`). Một mốc kháng cáo `d1`/`overdue` bị lỡ vì lý do này là
 * đúng cái mà cả `CheckDeadlines` sinh ra để chống.
 *
 * `failed()` (dưới đây) sửa cả hai vế của "không bao giờ im lặng" (R3):
 *
 *  1. **Rút bậc này khỏi `reminders_sent`**, dưới `lockForUpdate` — CÂU LỆNH ĐẦU TIÊN của
 *     transaction là khoá dòng, cùng kỷ luật `CheckDeadlines`/`UpdateMatterDetails` — để lượt
 *     `CheckDeadlines` KẾ TIẾP coi mốc này như CHƯA từng được xếp hàng ở bậc đó, và xếp lại.
 *  2. **Ghi một dòng audit** `deadline_reminder_failed` (chỉ mang `deadline_id` và `tier` — SPEC
 *     §10.6, không ghi gì khác), rồi **báo trong ứng dụng** cho người phụ trách mốc, luật sư phụ
 *     trách vụ việc, và MỌI admin đang hoạt động — qua {@see ResolveStaffRecipients} (R3: chỉ báo
 *     người đang xem được vụ việc đó, và "không bao giờ im lặng" — luôn có ít nhất admin nhận).
 *
 * **Đọc lại tại thời điểm chạy, không tin payload đã cũ** (kỷ luật giống hệt
 * `RecheckClientIdentityConflicts`, cũng của M6.5): giữa lúc job được xếp hàng và lúc nó THẬT SỰ
 * chạy (có thể trễ vài phút vì `queue:work --stop-when-empty` chỉ rút mỗi phút, hoặc trễ hàng giờ
 * nếu job phải `backoff()` sau một lần hỏng), vụ việc có thể đã bị huỷ (Task 5, `CancelMatter`) và
 * người nhận có thể đã bị khoá/nghỉ việc (R7). `handle()` re-check cả hai TRƯỚC khi gửi và BỎ QUA
 * (không gửi, không ném lỗi) người/mốc không còn hợp lệ — im lặng đúng nghĩa "vốn không nên gửi",
 * không phải một lần gửi thất bại.
 *
 * # M6.5 Task 12 (R3) — re-check đi qua {@see ResolveStaffRecipients}, không chỉ lọc `is_active`
 *
 * Trước bản sửa này, `handle()` chỉ đọc lại `is_active` trên danh sách `$recipientIds` — đủ cho
 * R7 (nghỉ việc), nhưng KHÔNG đủ cho R3: vụ việc có thể đã bị siết thành `confidentiality =
 * restricted` (một Action KHÁC của M6.5) giữa lúc `CheckDeadlines` dựng payload và lúc job này
 * chạy, và một quản lý còn `is_active` trong payload đó không còn được `Gate::view()` vụ ấy nữa.
 * Payload chỉ mang ID (SPEC §10.5), không mang lại QUYẾT ĐỊNH "ai được xem" — quyết định đó phải
 * được hỏi LẠI, đúng lúc thư sắp rời tay, qua `ResolveStaffRecipients` (Task 8; NƠI DUY NHẤT giữ
 * luật R3, dùng lại chứ không viết một bản lọc thứ hai). `$recipientIds` giờ chỉ còn là "danh sách
 * ưu tiên" của `ResolveStaffRecipients::handle()` — lớp đó tự lọc `is_active` + `Gate::view()`,
 * và tự đi chuỗi dự phòng "không bao giờ im lặng" (R3) nếu KHÔNG CÒN ai trong payload hợp lệ.
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
        $matter = Matter::query()->whereKey($deadline->matter_id)->open()->first();

        if ($matter === null) {
            return;
        }

        // Re-check người nhận TẠI THỜI ĐIỂM GỬI (R3, xem docblock lớp): `$recipientIds` chỉ còn
        // là danh sách ƯU TIÊN, ResolveStaffRecipients tự lọc is_active + Gate::view() + chuỗi dự
        // phòng "không bao giờ im lặng" nếu không còn ai trong đó hợp lệ.
        $preferred = User::query()->whereKey($this->recipientIds)->get();

        $recipients = app(ResolveStaffRecipients::class)->handle($matter, $preferred->all());

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->send(new DeadlineReminder($deadline, $recipient, $this->tierKey));
        }
    }

    /**
     * Chạy đúng MỘT lần, sau khi CẢ `$tries` lần đều thất bại — xem docblock lớp, mục "Vòng sửa 1,
     * C1". `?Throwable $exception` không dùng tới: SPEC §10.5 cấm nội suy văn bản lỗi tự do vào
     * dữ liệu ghi lại (một exception tương lai không đảm bảo không vô tình mang dữ liệu nhạy cảm);
     * tên lớp/JSON của nó đã nằm trong `outbound_messages.error` do `RecordOutboundMessage::markFailed()`
     * ghi ở LẦN THỬ CUỐI, không cần lặp lại ở đây.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            /** @var Deadline|null $deadline */
            $deadline = Deadline::query()->whereKey($this->deadlineId)->lockForUpdate()->first();

            if ($deadline === null) {
                return;
            }

            $deadline->update([
                'reminders_sent' => array_values(array_diff($deadline->reminders_sent ?? [], [$this->tierKey])),
            ]);
        });

        /** @var Deadline|null $deadline */
        $deadline = Deadline::query()->withTrashed()->find($this->deadlineId);

        Audit::record('deadline_reminder_failed', $deadline, [
            'deadline_id' => $this->deadlineId,
            'tier' => $this->tierKey,
        ]);

        $admins = User::query()->where('is_active', true)->role(Role::Admin->value)->get();

        // Mốc/vụ việc không còn tồn tại là một tình huống chưa từng xảy ra thật (Deadline dùng
        // SoftDeletes, matter_id là khoá ngoại bắt buộc) — nhưng nếu có, vẫn phải báo, chỉ là
        // không còn Matter nào để hỏi Gate::view(), nên rơi thẳng về mọi admin đang hoạt động.
        $matter = $deadline?->matter()->withTrashed()->first();

        $recipients = $matter !== null
            ? app(ResolveStaffRecipients::class)->handle(
                $matter,
                collect([$deadline->responsible, $matter->leadLawyer])->merge($admins)->all(),
            )
            : $admins;

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title(__('deadlines.reminder_failed_notification.title'))
                ->body(__('deadlines.reminder_failed_notification.body', [
                    'tier' => $this->tierKey,
                    'name' => $deadline->name ?? '',
                    'code' => $matter->code ?? '',
                ]))
                ->color('danger')
                ->sendToDatabase($recipient);
        }
    }
}
