<?php

namespace App\Listeners;

use App\Actions\Notification\RecordOutboundMessage;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Message;

/**
 * Cánh cửa mà mọi thư của hệ thống đi qua trên đường vào `outbound_messages` (SPEC §4.15).
 *
 * Nghe sự kiện của chính Laravel thay vì trông vào việc từng nơi gửi thư nhớ ghi nhật ký — Phán
 * quyết R1 của kế hoạch M6. Lớp này cố ý không giữ luật nào: nó dịch hai sự kiện thành hai lời
 * gọi tới `RecordOutboundMessage`.
 *
 * # Vì sao hai phương thức KHÔNG tên là `handle…`
 *
 * Laravel 13 **có** tự dò listener trong `app/Listeners`, kể cả khi `bootstrap/app.php` không
 * nhắc gì tới chuyện đó: `Application::configure()` gọi sẵn `->withEvents()`, và
 * `DiscoverEvents` nhận mọi phương thức khớp `handle*` hoặc `__invoke` có tham số là một lớp sự
 * kiện rồi tự `Event::listen()` chúng.
 *
 * Đo được, không phải suy đoán: bản đầu của lớp này đặt tên `handleMessageSending()` **và** được
 * đăng ký tường minh ở `AppServiceProvider`, kết quả là `MessageSending` có HAI listener
 * (`[RecordOutboundMail, 'handleMessageSending']` từ lời đăng ký tay và
 * `'RecordOutboundMail@handleMessageSending'` từ bộ dò), nên mỗi thư sinh ra HAI dòng nhật ký —
 * một dòng đứng mãi ở `queued` và một dòng `sent`. Đúng hình dạng lỗi mà bảng này tồn tại để
 * tránh: nhật ký nói hai điều khác nhau về cùng một lần gửi.
 *
 * Giữ lời đăng ký tường minh (`Event::subscribe()` ở `AppServiceProvider::boot()`) và đổi tên
 * hai phương thức ra khỏi `handle*`: chỗ móc vào framework nhìn thấy được bằng mắt, và bộ dò
 * không còn gì để nhặt. Người thêm một phương thức `handle…` vào lớp này sau đây sẽ dựng lại
 * đúng cái lỗi trên — nhân chứng là `tests/Feature/Mail/OutboundLedgerTest.php`, nơi mọi khẳng
 * định đi qua `sole()` và vì thế đỏ ngay khi có dòng thứ hai.
 *
 * # Chặng thứ ba nằm ở nơi khác
 *
 * Laravel 13 chỉ phát hai sự kiện thư; nó KHÔNG phát sự kiện nào khi việc gửi ném lỗi —
 * `Illuminate\Mail\Mailer::sendSymfonyMessage()` để ngoại lệ đi thẳng lên nơi gọi. Nên chặng
 * "gửi hỏng" được canh ở `App\Support\Mail\OutboundLedgerTransport`, lớp bọc quanh transport
 * thật, và nó cũng gọi đúng Action này. Hai chỗ móc, một nơi giữ luật.
 */
class RecordOutboundMail
{
    public function __construct(private readonly RecordOutboundMessage $ledger) {}

    public function recordSending(MessageSending $event): void
    {
        $this->ledger->sending($event->message);
    }

    public function recordSent(MessageSent $event): void
    {
        // `getOriginalMessage()` khai báo trả về `RawMessage`, lớp KHÔNG có header. Thực tế mọi
        // thư do Laravel gửi đều là `Email`, nhưng một `RawMessage` đi qua đây thì không có khoá
        // nhật ký nào để tra — và cũng chưa có dòng nào được mở cho nó ở `MessageSending`.
        $message = $event->sent->getOriginalMessage();

        if ($message instanceof Message) {
            $this->ledger->sent($message);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSending::class => 'recordSending',
            MessageSent::class => 'recordSent',
        ];
    }
}
