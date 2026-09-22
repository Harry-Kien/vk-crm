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
}
