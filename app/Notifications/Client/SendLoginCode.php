<?php

namespace App\Notifications\Client;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

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
 * **Phần thuộc về M6, nói ra chứ không để trống:** SPEC §9 đòi mọi mẫu email dùng CHUNG một
 * layout có logo và chân trang công ty, và đòi mỗi lần gửi để lại một dòng `outbound_messages`
 * (SPEC §4.15). Cả hai là việc của M6, cùng lúc với chín mẫu còn lại — M5 chỉ có duy nhất mẫu
 * này. Hôm nay thư dùng layout markdown mặc định của Laravel; khi M6 dựng layout chung thì chỗ
 * sửa là `resources/views/emails/client/otp.blade.php`, không phải lớp này.
 */
class SendLoginCode extends Notification
{
    public function __construct(
        #[SensitiveParameter]
        public string $code,
        public int $codeExpiryMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('portal.email.otp.subject'))
            ->markdown('emails.client.otp', [
                'name' => $notifiable->name ?? '',
                'code' => $this->code,
                'codeExpiryMinutes' => $this->codeExpiryMinutes,
                'hotline' => config('vkcrm.brand.hotline'),
                'office' => config('vkcrm.brand.legal_name'),
            ]);
    }
}
