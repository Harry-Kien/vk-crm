<?php

namespace App\Jobs;

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Actions\Notification\RecordOutboundMessage;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Push\SendPushAlert;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\OutboundStatus;
use App\Enums\PushTopic;
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
use Spatie\Activitylog\Models\Activity;
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
 * **Vì sao lọc thêm theo `payload->tier`, khác `NotifyClientOfStageUpdate::alreadyDelivered()`
 * (chỉ lọc theo `related`+`recipient`+`status`).** Thư tiến độ (`client.stage_update`) chỉ có ĐÚNG
 * MỘT lần gửi khả dĩ cho mỗi `StageLog` — không có khái niệm "bậc". Thư nhắc mốc thì CÓ: cùng một
 * `Deadline` (nên cùng `related_type`/`related_id`) được nhắc NHIỀU LẦN qua đời nó — `d14`, `d7`,
 * `d3`, `d1`, `overdue` — và một quản lý/luật sư có thể hợp lệ ở NHIỀU bậc. Lọc CHỈ theo
 * `related`+`recipient`+`status = sent` (đúng hình dạng thư tiến độ) sẽ coi MỌI lần nhắc TRƯỚC ĐÓ
 * (một `d7` đã gửi thật, thành công, tuần trước) là "đã gửi", và bậc `d1` MỚI của TUẦN NÀY sẽ
 * KHÔNG BAO GIỜ tới tay — im lặng đúng cái mà cả tác vụ này sinh ra để chống, một hình dạng khác
 * của "không bao giờ im lặng" (R3) bị vi phạm.
 *
 * # Vòng sửa 2 (I1, Important) — khoá theo BẬC (`payload->tier`), KHÔNG theo tiêu đề
 *
 * Bản vòng sửa 1 lọc theo `payload->subject`, với lý lẽ "tiêu đề mang số ngày còn lại thật, nên
 * khác nhau giữa hai bậc". Đúng, nhưng CHƯA ĐỦ: SAI ở chỗ MỘT bậc có thể mang NHIỀU tiêu đề khác
 * nhau theo NGÀY — `tierFor()` chọn bậc `d1` cho CẢ `daysLeft = 1` LẪN `daysLeft = 0` (`$daysLeft
 * <= 1`), và bậc `overdue` cho MỌI `daysLeft < 0` (một con số luôn tăng theo từng ngày quá hạn).
 * Kịch bản lỗi thật (đã tái hiện, xem báo cáo): (1) `failed()` rút một bậc khỏi `reminders_sent`
 * sau khi MỘT người nhận trong lượt đó dội ngược hẳn; (2) `CheckDeadlines` của ngày HÔM SAU xếp
 * lại ĐÚNG bậc đó (mốc vẫn còn nằm trong cùng khung ngày của bậc — ví dụ `d1` hôm qua ở
 * `daysLeft=1`, hôm nay `daysLeft=0`, VẪN là bậc `d1`); (3) tiêu đề HÔM NAY khác tiêu đề HÔM QUA
 * ("Hết hạn hôm nay" so với "Còn 1 ngày"), nên khoá theo subject KHÔNG nhận ra người đã nhận hôm
 * qua là "đã gửi bậc này rồi" — họ nhận thêm một thư CHO CÙNG bậc. Với bậc `overdue`, lỗi này lặp
 * lại MỖI NGÀY MÃI MÃI, vì tiêu đề không bao giờ trùng chính nó.
 *
 * Không có cột `tier` riêng ở `outbound_messages` (SPEC §4.15 không có cột đó), nên
 * `App\Mail\Staff\DeadlineReminder` mang bậc qua một header nội bộ
 * (`App\Mail\OutboundHeaders::LEDGER_TIER`) — {@see RecordOutboundMessage::
 * sending()} chép vào `payload['tier']`, và {@see RecordOutboundMessage::
 * detachInternalHeaders()} gỡ nó khỏi thông điệp trước khi thư rời máy chủ, CÙNG luật với
 * `Template`/`Related`/`Ledger-Id` (`notify/notify-11`).
 *
 * # M6.5 Task 14, fix round 1 (C1, Critical) — khoá là BẬC + NGÀY ĐẾN HẠN
 *
 * Khoá chỉ theo bậc đúng cho MỘT ngày đến hạn, và sai ngay khi ngày đó đổi. `UpdateDeadline`
 * (nút "Sửa", Task 14) dọn khỏi `reminders_sent` những bậc mà ngày MỚI chưa tới, để hoãn phiên
 * toà thì được nhắc lại. CheckDeadlines đánh dấu lại bậc đó và xếp job này — nhưng khoá chỉ theo
 * bậc thấy `d1` (hay `overdue`, hay bất kỳ bậc nào đã THẬT SỰ gửi) cho phiên toà CŨ, và bỏ qua mọi
 * người nhận: dữ liệu nói "đã gửi", không ai nhận thư.
 *
 * Nay job mang `$dueDate` — ảnh chụp `due_date` lúc CheckDeadlines xếp hàng — và khoá là
 * {@see DeadlineReminder::ledgerTier()} (`d1@2026-10-01`), cùng chuỗi mà thư ghi vào header
 * `LEDGER_TIER`. Vẫn giữ nguyên chống trùng khi thử lại và qua ranh giới ngày của vòng sửa 2: cùng
 * một ngày đến hạn, `d1` ở còn 1 ngày và còn 0 ngày, `overdue` mọi ngày quá hạn đều ra CÙNG khoá.
 *
 * **Dòng nhật ký cũ chỉ mang bậc trần (`d1`, ghi trước bản sửa này).** Nó không biết mình nói về
 * ngày nào, nên:
 *
 *  - Nó VẪN chặn đúng một job CŨ đang chờ thử lại — job xếp hàng trước bản sửa, deserialize ra
 *    `$dueDate = null`. Chỉ loại job đó mới có thể đã ghi dòng trần; nó thử lại CÙNG lần nhắc, nên
 *    dòng trần của nó là "đã gửi" thật. Ảnh chụp ngày của nó là `due_date` hiện tại của mốc.
 *  - Nó KHÔNG chặn một job MỚI (có `$dueDate`) — job đó do một lượt CheckDeadlines sau bản sửa xếp,
 *    và dòng trần có thể nói về một ngày đến hạn đã bị hoãn. Chặn nhầm là im lặng; không chặn thì
 *    tệ nhất một người nhận thêm MỘT thư trùng (chỉ khi một `failed()` rút bậc đúng qua lúc triển
 *    khai). Giữa hai cái sai đó, R3 chọn "nói ra".
 *
 * # M12 — thông báo đẩy `staff.deadline_reminder` (kế hoạch M12 R10–R12; phán quyết (d) của controller)
 *
 * Nơi nối là JOB này, không phải `CheckDeadlines`: thư thật sự đi ở đây, với người nhận tính lại lúc
 * gửi; nối ở `CheckDeadlines` là gọi push bên trong transaction của mốc, với một tập người nhận KHÁC
 * tập thư. Sau vòng thư và TRƯỚC lần ném lại lỗi, {@see SendPushAlert} nhận đúng những người mà lượt
 * này vừa gửi thư được (`$mailed`) — không người đã có dòng `sent` từ lượt trước
 * ({@see self::alreadyDelivered()}), không người vừa hỏng thư — cùng bậc `$tierKey` (câu chữ, và
 * `urgency = high` ở `d1`/quá hạn, R11). Không luật người nhận thứ hai (`recipientsFor()` vẫn là nơi
 * duy nhất), không trí nhớ chống trùng mới: "mỗi bậc một push" đúng vì mỗi bậc chỉ được xếp một lần
 * (`reminders_sent`) và lượt thử lại bỏ qua người đã nhận (sổ thư). `SendPushAlert` không ném vì lỗi
 * lúc chạy, nên push hỏng không làm job hỏng hay thư thử lại.
 *
 * Push của một lượt được xếp SAU CẢ vòng thư, không ngay sau thư của từng người: worker bị giết giữa
 * vòng thư (quá `timeout`) thì những người đã nhận thư trong lượt đó không có push, và lượt thử lại
 * bỏ qua họ (đã có dòng `sent`) — không có push bù. Push là tiện ích, thư mới là chứng cứ (R12).
 * `failed()` (bậc hỏng hẳn) không đẩy gì: chuông trong hệ thống là kênh báo lỗi của nó.
 */
class SendDeadlineReminderMail implements ShouldQueue
{
    use Queueable;

    /** Một lần hỏng thoáng qua (SMTP chết tạm) không cần báo động ngay; xem `backoff()`. */
    public int $tries = 5;

    /**
     * @param  string|null  $dueDate  `due_date` (`Y-m-d`) của mốc lúc CheckDeadlines xếp job;
     *                                `null` chỉ ở job xếp trước fix round 1 — xem docblock lớp.
     */
    public function __construct(
        public readonly int $deadlineId,
        public readonly string $tierKey,
        public readonly ?string $dueDate = null,
    ) {}

    /**
     * Ảnh chụp `due_date`, hoặc `null` cho một job xếp hàng trước fix round 1 — và là đường DUY NHẤT
     * đọc `$dueDate` (Task 14 fix round 2, Important).
     *
     * `= null` ở constructor là mặc định của THAM SỐ, không phải của thuộc tính readonly có kiểu.
     * Payload serialize trước 5098fd6 không có khoá `dueDate`, và `unserialize()` không chạy
     * constructor, nên thuộc tính ở trạng thái CHƯA KHỞI TẠO: `$this->dueDate === null` ném Error,
     * job hỏng đủ `$tries`, `failed()` rút bậc, lượt sau gửi lại cho người đã nhận. `isset()` không
     * ném trên một thuộc tính chưa khởi tạo — nó trả `false`, đúng nghĩa "không có ảnh chụp".
     */
    private function dueDateSnapshot(): ?string
    {
        return isset($this->dueDate) ? $this->dueDate : null;
    }

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

        // Ngày đến hạn mà lời nhắc này nói tới — xem docblock lớp, mục "fix round 1 (C1)".
        $aboutDueDate = $this->dueDateSnapshot() ?? $deadline->due_date->toDateString();

        $failure = null;
        $mailed = collect();

        foreach ($recipients as $recipient) {
            // "Không gửi trùng khi thử lại" — xem docblock lớp, mục "Vòng sửa 2 (I1)". Bỏ qua
            // NGƯỜI NÀY, không phải cả lượt: người khác trong cùng bậc có thể vẫn chưa nhận được.
            if ($this->alreadyDelivered($deadline, $recipient, $aboutDueDate)) {
                continue;
            }

            // Final review X5 (B-I1): một người nhận hỏng không được chặn những người sau — nhất là
            // thư leo thang tới trưởng phòng khi hộp thư của chính luật sư phụ trách bị từ chối.
            // Cùng hình dạng `NotifyClientOfStageUpdate::handle()`: giữ ngoại lệ ĐẦU TIÊN, thử hết,
            // rồi ném lại để hàng đợi vẫn thấy job hỏng (thử lại, rồi `failed()`).
            try {
                Mail::to($recipient->email)->send(new DeadlineReminder($deadline, $recipient, $this->tierKey, $aboutDueDate));
                $mailed->push($recipient);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        // M12: push cho đúng những người lượt này vừa gửi thư được, ở đúng bậc — xem docblock lớp.
        app(SendPushAlert::class)->handle($mailed, PushTopic::StaffDeadlineReminder, $deadline, $this->tierKey);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Cùng hình dạng {@see NotifyClientOfStageUpdate::alreadyDelivered()}, cộng một điều kiện lọc
     * theo BẬC + NGÀY ĐẾN HẠN (`payload->tier`) — xem docblock lớp, mục "Vòng sửa 2 (I1)" và
     * "fix round 1 (C1)", cho lý do cần thêm điều kiện đó ở đây mà bên kia không cần.
     */
    private function alreadyDelivered(Deadline $deadline, User $recipient, string $aboutDueDate): bool
    {
        $keys = [DeadlineReminder::ledgerTier($this->tierKey, $aboutDueDate)];

        // Dòng trần (trước fix round 1) chỉ tính cho job CŨ không có ảnh chụp ngày — docblock lớp.
        if ($this->dueDateSnapshot() === null) {
            $keys[] = $this->tierKey;
        }

        return OutboundMessage::query()
            ->withoutGlobalScopes()
            ->where('related_type', $deadline->getMorphClass())
            ->where('related_id', $deadline->getKey())
            ->where('recipient', $recipient->email)
            ->where('status', OutboundStatus::Sent)
            ->whereIn('payload->tier', $keys)
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

        // Final review wave 2, I-2: ngày đến hạn mà lời nhắc này nói tới — cùng khoá bậc@ngày của
        // sổ thư. Hỏi "hôm nay đã báo hỏng cho đúng (mốc, bậc, ngày đến hạn) chưa" TRƯỚC khi ghi
        // dòng của lần này.
        $aboutDueDate = $this->dueDateSnapshot() ?? $deadline?->due_date?->toDateString();
        $alreadyReportedToday = $aboutDueDate !== null
            && self::failedForGoodToday($this->deadlineId, $this->tierKey, $aboutDueDate);

        Audit::record('deadline_reminder_failed', $deadline, [
            'deadline_id' => $this->deadlineId,
            'tier' => $this->tierKey,
            'due_date' => $aboutDueDate,
        ]);

        // Dòng nhật ký vẫn ghi cho MỖI lần hỏng; chuông thì một lần mỗi (mốc, bậc, ngày đến hạn,
        // ngày) — xem docblock `failedForGoodToday()`.
        if ($alreadyReportedToday) {
            return;
        }

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

    /**
     * Bậc `$tier` của mốc này, cho ngày đến hạn `$dueDate`, đã hỏng HẲN trong NGÀY HÔM NAY chưa —
     * final review wave 2, I-2. Đọc dòng `deadline_reminder_failed` mà {@see self::failed()} ghi.
     *
     * Hai nơi hỏi, một định nghĩa: `CheckDeadlines` không xếp lại bậc đó trong ngày (từ khi
     * `deadlines.check` chạy mỗi 30 phút, rút bậc rồi xếp lại ngay là 7–8 vòng hỏng mỗi ngày, mỗi
     * vòng năm lần thử SMTP và một chuông cho mọi người nhận), và `failed()` không rung chuông lần
     * hai trong ngày. Lượt đầu của ngày hôm sau thử lại đúng một lần.
     */
    public static function failedForGoodToday(int $deadlineId, string $tier, string $dueDate): bool
    {
        return Activity::query()
            ->where('event', 'deadline_reminder_failed')
            ->where('subject_type', (new Deadline)->getMorphClass())
            ->where('subject_id', $deadlineId)
            ->where('properties->tier', $tier)
            ->where('properties->due_date', $dueDate)
            ->where('created_at', '>=', today()->startOfDay())
            ->exists();
    }
}
