<?php

namespace App\Notifications\Client;

use App\Mail\OutboundHeaders;
use App\Support\OfficeProfile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;
use Symfony\Component\Mime\Email;

/**
 * Thư mang mã đăng nhập cổng khách hàng — mẫu `client.otp` của SPEC §9.
 *
 * Thay cho `Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication`, được cắm
 * vào bằng `EmailAuthentication::codeNotification()` ở `PortalPanelProvider`. Hai lý do thay,
 * cả hai đều đo được trên bản đang cài chứ không phải sở thích:
 *
 *  1. **Bản của Filament `implements ShouldQueue`.** Dự án chạy `QUEUE_CONNECTION=database`
 *     (`.env`), nên một thư xếp hàng sẽ nằm trong bảng `jobs` tới khi có worker chạy qua — trên
 *     shared hosting của SPEC §2 thì đó là một lần cron, tức tới một phút. Mã chỉ sống 5 phút
 *     (SPEC §8.1) và khách đang ngồi nhìn màn hình chờ nó, nên thư này gửi THẲNG, không xếp
 *     hàng. Đây là lý do lớp này cố ý KHÔNG `implements ShouldQueue`.
 *  2. Nội dung phải là tiếng Việt của văn phòng, có xưng hô và có số điện thoại để khách gọi khi
 *     không phải họ vừa đăng nhập.
 *
 * **Phần M6 Task 1 đã lấp, thay cho ghi chú "để sau" mà M5 từng đặt ở đây:** SPEC §9 đòi mọi mẫu
 * email dùng CHUNG một layout có logo và chân trang công ty, và đòi mỗi lần gửi để lại một dòng
 * `outbound_messages` (SPEC §4.15). Cả hai nay đã có:
 *
 *  - thư render bằng `->view()` trên `emails.layout` và `emails.layout-text` (hai bản, HTML và
 *    văn bản thuần), thay cho layout markdown mặc định của Laravel;
 *  - header `X-VKCRM-Template` được đặt thẳng ở đây bằng `withSymfonyMessage()`, vì thư này là
 *    một Notification chứ không phải một Mailable nên nó không đi qua `App\Mail\BrandedMailable`.
 *    Thiếu header ấy thì thư VẪN được ghi nhật ký, chỉ là với `template = undeclared`: cánh cửa
 *    không thủng, nhưng dòng nhật ký mất ngữ cảnh.
 *
 * Việc KHÔNG xếp hàng thì không đổi, và đó là chủ ý — xem hai điểm ở trên.
 */
class SendLoginCode extends Notification
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $code,
        public readonly int $codeExpiryMinutes,
    ) {}

    /**
     * Mã là một bí mật sống 5 phút và mở được một tài khoản khách — nên nó không được là một
     * thuộc tính công khai.
     *
     * `#[SensitiveParameter]` chỉ che **khung gọi hàm khởi tạo** trong stack trace; nó không nói
     * gì về đối tượng sau khi đã dựng xong. Một `var_dump($notification)` khi gỡ lỗi, một
     * `json_encode()` của một payload có nó, hay một thư viện nhật ký serialize ngữ cảnh — cả ba
     * đều in thẳng mã ra nếu nó là `public string $code`. `private readonly` đóng hai đường sau,
     * `__debugInfo()` đóng đường đầu.
     *
     * Nơi cần mã thật vẫn lấy được bằng `code()` — mục đích ở đây là không để nó RƠI RA những
     * chỗ không ai định in nó, chứ không phải giấu nó khỏi mã gọi.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'code' => '[đã ẩn]',
            'codeExpiryMinutes' => $this->codeExpiryMinutes,
        ];
    }

    /** Mã 6 số vừa gửi đi. */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Đọc thông tin văn phòng lúc dựng thư — xem docblock `OfficeProfile`. */
    public function toMail(object $notifiable): MailMessage
    {
        $office = OfficeProfile::current();

        return (new MailMessage)
            ->subject(__('portal.email.otp.subject'))
            ->view(['emails.client.otp', 'emails.client.otp-text'], [
                'name' => $notifiable->name ?? '',
                'code' => $this->code,
                'codeExpiryMinutes' => $this->codeExpiryMinutes,
                'hotline' => $office->hotline(),
                'office' => $office->legalName(),
            ])
            ->withSymfonyMessage(fn (Email $message) => $message->getHeaders()
                ->addTextHeader(OutboundHeaders::TEMPLATE, 'client.otp'));
    }
}
