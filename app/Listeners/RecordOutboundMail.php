<?php

namespace App\Listeners;

use App\Actions\Notification\RecordOutboundMessage;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;

/**
 * Cánh cửa mà mọi thư của hệ thống đi qua trên đường vào `outbound_messages` (SPEC §4.15).
 *
 * Nghe sự kiện của chính Laravel thay vì trông vào việc từng nơi gửi thư nhớ ghi nhật ký — Phán
 * quyết R1 của kế hoạch M6. Lớp này cố ý không giữ luật nào: nó dịch sự kiện thành một lời gọi
 * tới `RecordOutboundMessage`.
 *
 * # Vì sao phương thức KHÔNG tên là `handle…`
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
 * phương thức ra khỏi `handle*`: chỗ móc vào framework nhìn thấy được bằng mắt, và bộ dò không
 * còn gì để nhặt. Người thêm một phương thức `handle…` vào lớp này sau đây sẽ dựng lại đúng cái
 * lỗi trên — nhân chứng là `tests/Feature/Mail/OutboundLedgerTest.php`, nơi mọi khẳng định đi
 * qua `sole()` và vì thế đỏ ngay khi có dòng thứ hai.
 *
 * # M6.5 Task 12 (`notify/notify-11`) — chặng "gửi xong"/"gửi hỏng" chuyển hẳn sang transport
 *
 * Trước bản sửa này, lớp này còn nghe THÊM `MessageSent` (`recordSent()`) để đóng dòng thành
 * `sent`, và `App\Support\Mail\OutboundLedgerTransport` chỉ lo nhánh `failed`. Task 12 đòi gỡ ba
 * header `X-VKCRM-*` khỏi thư TRƯỚC KHI nó rời máy chủ (`notify/notify-11`) — và
 * `Symfony\Component\Mailer\Transport\AbstractTransport::send()` CLONE thông điệp trước khi gửi,
 * nên gỡ phải xảy ra ở `OutboundLedgerTransport::send()`, TRƯỚC khi gọi transport thật. Hệ quả:
 * đến lúc `MessageSent` được phát, thông điệp trong sự kiện đó (bọc CLONE của bản ĐÃ gỡ header)
 * không còn `X-VKCRM-Ledger-Id` để tra dòng nữa — `recordSent()` kiểu cũ sẽ luôn thất bại một
 * cách lặng lẽ, một chỗ chết không test nào bắt được (vì thư vẫn báo `sent` — do gì thì xem dưới).
 *
 * Vì vậy `OutboundLedgerTransport` giờ tự đóng dòng ở CẢ HAI nhánh (thành công lẫn thất bại),
 * dùng khoá dòng nó tự đọc RA khỏi header trước khi gỡ (`RecordOutboundMessage::
 * detachInternalHeaders()`), không cần đọc lại từ một sự kiện nào nữa. Lớp NÀY chỉ còn việc MỞ
 * dòng (`MessageSending`, chạy trước cả `OutboundLedgerTransport`, khi header còn nguyên để đọc
 * `template`/`related`) — hai chặng, một nơi mở, một nơi đóng, không còn ba lời gọi từ hai lớp
 * như trước.
 *
 * # Chặng "gửi hỏng" vẫn nằm ở nơi khác
 *
 * Laravel 13 chỉ phát hai sự kiện thư; nó KHÔNG phát sự kiện nào khi việc gửi ném lỗi —
 * `Illuminate\Mail\Mailer::sendSymfonyMessage()` để ngoại lệ đi thẳng lên nơi gọi. Nên chặng đó
 * luôn phải canh ở `App\Support\Mail\OutboundLedgerTransport`, lớp bọc quanh transport thật —
 * Task 12 chỉ giao thêm cho nó chặng "gửi xong", vốn trước đây nằm ở đây.
 *
 * M6.5 Task 11 (R2): kể từ Task 11, `Mail::to()->send()` của thư tiến độ và thư nhắc mốc thời
 * hạn luôn chạy BÊN TRONG một job/listener hàng đợi (`App\Listeners\SendStageUpdateNotification`,
 * `App\Jobs\SendDeadlineReminderMail`), không còn chạy đồng bộ trong transaction nghiệp vụ nào
 * nữa. Phương thức dưới đây vẫn không đổi gì vì việc đó — nó chưa từng biết Action gọi mình từ
 * đâu.
 */
class RecordOutboundMail
{
    public function __construct(private readonly RecordOutboundMessage $ledger) {}

    public function recordSending(MessageSending $event): void
    {
        $this->ledger->sending($event->message);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSending::class => 'recordSending',
        ];
    }
}
