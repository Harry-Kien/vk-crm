<?php

namespace App\Mail;

/**
 * Tên ba header mà nhật ký thư đi ra (`outbound_messages`, SPEC §4.15) đi theo.
 *
 * Chúng nằm ở một lớp riêng vì có tới bốn nơi phải nói về cùng một chuỗi ký tự — `BrandedMailable`
 * đặt, `App\Notifications\Client\SendLoginCode` đặt (thư mã đăng nhập là một Notification chứ
 * không phải Mailable), `App\Actions\Notification\RecordOutboundMessage` đọc, và bộ test đối
 * chiếu. Một chuỗi gõ tay ở bốn chỗ là một chuỗi sẽ gõ sai ở chỗ thứ năm, và hậu quả của lần gõ
 * sai đó không phải một lỗi: nó là một dòng nhật ký lặng lẽ ghi `template = undeclared`.
 */
final class OutboundHeaders
{
    /** Tên mẫu thư theo SPEC §9, ví dụ `client.otp`. */
    public const TEMPLATE = 'X-VKCRM-Template';

    /** Bản ghi mà thư nói về, dạng `{morph alias}:{id}` — ví dụ `stage_log:12`. */
    public const RELATED = 'X-VKCRM-Related';

    /**
     * Khoá của dòng `outbound_messages` vừa mở cho thư này.
     *
     * Header chứ không phải một bảng tra trong bộ nhớ, và lý do đo được chứ không phải sở thích:
     * `Symfony\Component\Mailer\Transport\AbstractTransport::send()` **clone** thông điệp trước
     * khi gửi, nên đối tượng `Email` đi kèm sự kiện `MessageSent` KHÔNG còn là đối tượng đã đi
     * kèm `MessageSending` (`spl_object_id` của hai bên khác nhau). Header thì theo bản sao.
     */
    public const LEDGER_ID = 'X-VKCRM-Ledger-Id';

    /**
     * Bậc nhắc (`d14`/`d7`/`d3`/`d1`/`overdue`) — CHỈ `App\Mail\Staff\DeadlineReminder` đặt (vòng
     * sửa 2, `task-12-fix2-findings.md`, I1).
     *
     * **Vì sao cần header này mà không dùng lại `payload->subject` sẵn có.** Tiêu đề thư mang số
     * NGÀY CÒN LẠI THẬT (M6.5 Task 12, `deadlines/F3`), không phải bậc — nhưng MỘT bậc có thể trải
     * qua NHIỀU giá trị "số ngày thật" khác nhau (`tierFor()`: bậc `d1` áp dụng cho CẢ `daysLeft=1`
     * lẫn `daysLeft=0`; bậc `overdue` áp dụng cho MỌI `daysLeft<0`, một số luôn tăng theo từng
     * ngày). Khoá chống gửi trùng theo `payload->subject` (bản vòng sửa 1) vì vậy KHÔNG nhận ra
     * hai lần gửi CÙNG một bậc, khác ngày, là "đã gửi bậc này rồi" — với bậc `overdue`, sai số này
     * lặp lại MỖI NGÀY MÃI MÃI, vì tiêu đề không bao giờ trùng chính nó. Header này mang ĐÚNG bậc,
     * ổn định bất kể ngày nào trong đời của bậc đó — xem `App\Jobs\SendDeadlineReminderMail::
     * alreadyDelivered()`.
     */
    public const LEDGER_TIER = 'X-VKCRM-Ledger-Tier';
}
