<?php

namespace App\Jobs;

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
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
 *     trách vụ việc, và {@see ResolveStaffRecipients::supervisorsFor()} (vòng sửa 1, M1 — không
 *     còn "mọi admin đang hoạt động" không điều kiện, xem docblock của hàm đó).
 *
 * # Vòng sửa 1 (ruling "re-derive the audience at send time") — bỏ hẳn `$recipientIds`
 *
 * Bản Task 12 gốc vẫn mang một danh sách "ưu tiên" (`$recipientIds`) từ lúc dispatch, và chỉ RE-
 * CHECK danh sách đó qua `ResolveStaffRecipients` lúc chạy. Vẫn còn một lỗ: danh sách đó là một
 * ẢNH CHỤP tại thời điểm `CheckDeadlines` chạy — nếu người phụ trách MỐC bị thay đổi, hay một
 * người MỚI đủ điều kiện xuất hiện (ví dụ luật sư phụ trách vụ được đổi qua `ReassignMatter`)
 * GIỮA lúc dispatch và lúc job chạy, ảnh chụp đó không hề biết. `handle()` giờ gọi THẲNG
 * {@see CheckDeadlines::recipientsFor()} — ĐÚNG hàm mà `CheckDeadlines::processOne()` dùng để
 * quyết định có dispatch hay không — để tính lại TOÀN BỘ đối tượng nhận thư từ đầu, đúng lúc thư
 * sắp rời tay, không tin bất kỳ ảnh chụp nào. Payload giờ chỉ còn `deadlineId` + `tierKey` (SPEC
 * §10.5), không còn gì khác để mà tin nhầm.
 *
 * # Vòng sửa 1 (ruling "no duplicate reminders on retry")
 *
 * `handle()` gửi TỪNG người một trong vòng `foreach`; nếu người thứ hai làm transport ném lỗi,
 * ngoại lệ thoát khỏi `handle()` và Laravel THẢ LẠI (`release()`) toàn bộ job — lần thử tiếp theo
 * chạy lại `handle()` TỪ ĐẦU, tính lại TOÀN BỘ danh sách người nhận (đúng ý ở trên) và LẶP LẠI
 * vòng `foreach`, kể cả người ĐẦU TIÊN đã nhận thành công ở lượt trước. {@see self::
 * alreadyDelivered()} hỏi thẳng nhật ký `outbound_messages` (SPEC §4.15) — nguồn sự thật duy nhất
 * về "đã tới nơi chưa" theo TỪNG người nhận, CÙNG HÌNH DẠNG với
 * `NotifyClientOfStageUpdate::alreadyDelivered()` — trước khi gửi lại, nên người đã nhận không
 * nhận thêm bản thứ hai chỉ vì người khác trong cùng lượt từng hỏng.
 *
 * **Vì sao lọc thêm theo `payload->subject`, khác `NotifyClientOfStageUpdate::alreadyDelivered()`
 * (chỉ lọc theo `related`+`recipient`+`status`).** Thư tiến độ (`client.stage_update`) chỉ có ĐÚNG
 * MỘT lần gửi khả dĩ cho mỗi `StageLog` — không có khái niệm "bậc". Thư nhắc mốc thì CÓ: cùng một
 * `Deadline` (nên cùng `related_type`/`related_id`) được nhắc NHIỀU LẦN qua đời nó — `d14`, `d7`,
 * `d3`, `d1`, `overdue` — và một quản lý/luật sư có thể hợp lệ ở NHIỀU bậc. Lọc CHỈ theo
 * `related`+`recipient`+`status = sent` (đúng hình dạng thư tiến độ) sẽ coi MỌI lần nhắc TRƯỚC ĐÓ
 * (một `d7` đã gửi thật, thành công, tuần trước) là "đã gửi", và bậc `d1` MỚI của TUẦN NÀY sẽ
 * KHÔNG BAO GIỜ tới tay — im lặng đúng cái mà cả tác vụ này sinh ra để chống, một hình dạng khác
 * của "không bao giờ im lặng" (R3) bị vi phạm. Không có cột `tier` riêng ở `outbound_messages`
 * (SPEC §4.15 không có cột đó), nhưng tiêu đề thư (M6.5 Task 12, `deadlines/F3`) đã mang ĐÚNG số
 * ngày còn lại THẬT — ổn định trong SUỐT một lượt job (kể cả các lần thử lại của `backoff()`, tối
 * đa ~1 giờ, `today()` không đổi), và khác NHAU giữa hai lượt job của hai bậc khác nhau (số ngày
 * còn lại luôn khác, vì `due_date` cố định còn "hôm nay" đã trôi). Tiêu đề vì vậy là khoá phân
 * biệt bậc DUY NHẤT không cần thêm cột nào.
 */
class SendDeadlineReminderMail implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    public function __construct(
        public readonly int $deadlineId,
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

        // Tính lại TOÀN BỘ đối tượng nhận thư TẠI THỜI ĐIỂM GỬI, đúng hàm mà CheckDeadlines dùng
        // để quyết định dispatch — xem docblock lớp, mục "re-derive the audience at send time".
        $recipients = app(CheckDeadlines::class)->recipientsFor($deadline, $this->tierKey);

        foreach ($recipients as $recipient) {
            $mail = new DeadlineReminder($deadline, $recipient, $this->tierKey);

            // "Không gửi trùng khi thử lại" — xem docblock lớp, mục "no duplicate reminders on
            // retry". Bỏ qua NGƯỜI NÀY, không phải cả lượt: người khác trong cùng bậc có thể vẫn
            // chưa nhận được.
            if ($this->alreadyDelivered($deadline, $recipient, $mail->envelope()->subject)) {
                continue;
            }

            Mail::to($recipient->email)->send($mail);
        }
    }

    /**
     * Cùng hình dạng {@see NotifyClientOfStageUpdate::alreadyDelivered()},
     * cộng một điều kiện lọc theo bậc (`payload->subject`) — xem docblock lớp cho lý do cần thêm
     * điều kiện đó ở đây mà bên kia không cần.
     */
    private function alreadyDelivered(Deadline $deadline, User $recipient, string $subject): bool
    {
        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $deadline->getMorphClass())
            ->where('related_id', $deadline->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->where('payload->subject', $subject)
            ->exists();
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

        // Mốc/vụ việc không còn tồn tại là một tình huống chưa từng xảy ra thật (Deadline dùng
        // SoftDeletes, matter_id là khoá ngoại bắt buộc) — nhưng nếu có, vẫn phải báo, chỉ là
        // không còn Matter nào để hỏi Gate::view()/supervisorsFor(), nên rơi thẳng về mọi admin
        // đang hoạt động (lưới an toàn cuối cùng, không phải luật thường ngày).
        $matter = $deadline?->matter()->withTrashed()->first();

        $resolver = app(ResolveStaffRecipients::class);

        $recipients = $matter !== null
            ? $resolver->handle(
                $matter,
                collect([$deadline->responsible, $matter->leadLawyer])
                    ->merge($resolver->supervisorsFor($matter))
                    ->all(),
            )
            : User::query()->where('is_active', true)->role(Role::Admin->value)->get();

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
