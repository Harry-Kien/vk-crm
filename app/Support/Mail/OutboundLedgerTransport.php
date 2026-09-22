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
 */
class OutboundLedgerTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly RecordOutboundMessage $ledger,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $error) {
            // `RawMessage` trần không có header nào để tra khoá dòng nhật ký, nên không có dòng
            // nào để đánh dấu; `MessageSending` cũng chưa mở dòng nào cho nó.
            if ($message instanceof Message) {
                $this->ledger->failed($message, $error);
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
