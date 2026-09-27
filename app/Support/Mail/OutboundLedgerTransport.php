<?php

namespace App\Support\Mail;

use App\Actions\Notification\RecordOutboundMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Lớp bọc quanh transport thật, để một lần gửi HỎNG cũng để lại dấu vết trong `outbound_messages`.
 *
 * **Vì sao phải có lớp này thay vì một listener nữa:** Laravel 13 phát đúng hai sự kiện thư,
 * `MessageSending` và `MessageSent`. Không có sự kiện nào cho lần gửi thất bại —
 * `Illuminate\Mail\Mailer::sendSymfonyMessage()` gọi `$this->transport->send()` trong một
 * `try { … } finally { }` rỗng và để ngoại lệ đi thẳng lên nơi gọi. Symfony thì CÓ
 * `FailedMessageEvent`, nhưng nó chỉ được phát khi transport được dựng kèm một
 * `EventDispatcherInterface`, và `MailManager` của Laravel dựng mọi transport không có dispatcher
 * nào. Nên chỗ duy nhất nhìn thấy được ngoại lệ mà không phải sửa từng nơi gửi thư là ở đây.
 *
 * Nếu không có lớp này, một thư gửi hỏng để lại một dòng đứng mãi ở `queued` và không có lý do —
 * tức đúng câu trả lời sai cho câu hỏi "tôi không nhận được thông báo".
 *
 * Ngoại lệ được NÉM TIẾP nguyên vẹn: nhật ký là chứng cứ, không phải một cái bẫy nuốt lỗi.
 *
 * # M6.5 Task 12 (`notify/notify-11`) — cũng là nơi gỡ header `X-VKCRM-*` trước khi thư rời máy chủ
 *
 * `BrandedMailable` gắn `X-VKCRM-Template`/`X-VKCRM-Related`, và `RecordOutboundMessage::sending()`
 * (chạy ở `MessageSending`, TRƯỚC lớp này) gắn thêm `X-VKCRM-Ledger-Id`. Ba header ấy đi THEO thư
 * tới tận hộp thư khách nếu không ai gỡ — lộ id tuần tự nội bộ (dòng nhật ký, và qua header
 * `Related`, id của bản ghi thư nói về) cho bất kỳ ai xem "Show original".
 *
 * Đây là NƠI DUY NHẤT gỡ được: chỗ khác (ví dụ một listener nghe `MessageSent`) đọc thông điệp
 * đã bị `Symfony\Component\Mailer\Transport\AbstractTransport::send()` CLONE — cùng cơ chế mà
 * docblock `OutboundHeaders::LEDGER_ID` đã ghi — nên gỡ trên thông điệp gốc trước khi gọi
 * `$this->inner->send()` là cách DUY NHẤT đảm bảo bản đi ra dây mạng không còn header nào, bất kể
 * transport bên trong là gì (SMTP, `array`, `log`, ...).
 *
 * `RecordOutboundMessage::detachInternalHeaders()` trả về khoá dòng đã đọc TRƯỚC KHI gỡ (từ
 * `Ledger-Id`) — lớp này giữ nó trong một biến cục bộ để đóng dòng ở CẢ HAI nhánh dưới đây, thay
 * vì để `App\Listeners\RecordOutboundMail` nghe `MessageSent` như trước Task 12 (bản đó đọc lại
 * header từ thông điệp đã bị clone và đã bị gỡ — luôn thất bại tra dòng, một chỗ chết lặng lẽ).
 */
class OutboundLedgerTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly RecordOutboundMessage $ledger,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // `RawMessage` trần không có header nào để tra khoá dòng nhật ký (không có dòng nào để
        // đóng/đánh dấu — `MessageSending` cũng chưa từng mở dòng nào cho nó).
        $ledgerId = $message instanceof Message
            ? $this->ledger->detachInternalHeaders($message)
            : null;

        try {
            $sent = $this->inner->send($message, $envelope);

            if ($ledgerId !== null) {
                $this->ledger->markSent($ledgerId);
            }

            return $sent;
        } catch (Throwable $error) {
            if ($ledgerId !== null) {
                $this->ledger->markFailed($ledgerId, $error);
            }

            throw $error;
        }
    }

    /** Transport thật bên trong — bộ test đọc thư đã gửi qua `ArrayTransport::messages()`. */
    public function innerTransport(): TransportInterface
    {
        return $this->inner;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
