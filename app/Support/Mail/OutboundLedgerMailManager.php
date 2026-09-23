<?php

namespace App\Support\Mail;

use App\Actions\Notification\RecordOutboundMessage;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * `MailManager` của Laravel, với đúng một thay đổi: mọi transport nó dựng ra đều được bọc bằng
 * `OutboundLedgerTransport`.
 *
 * Chọn điểm móc này chứ không chọn `Mail::extend('smtp', …)` vì hai lý do kiểm chứng được
 * ngay trong mã framework:
 *
 *  - `Mail::extend()` gắn theo TÊN DRIVER (`smtp`, `array`, `log`, `failover`, …). Một danh sách
 *    tên viết tay là một danh sách sẽ thiếu tên thứ mười một, và chỗ thiếu ấy không báo lỗi — nó
 *    chỉ lặng lẽ không ghi nhật ký cho mailer đó. Bọc ở `createSymfonyTransport()` thì driver nào
 *    cũng đi qua, kể cả driver do một gói khác hay một test đăng ký lúc chạy.
 *  - `createSymfonyTransport()` là `public` trên `MailManager` và được gọi cho mọi mailer được
 *    phân giải, nên đây là một điểm mở rộng có thật của framework, không phải một khe hở.
 *
 * **Điều đã biết trước, để người bật `failover` không phải tự phát hiện:** `createFailoverTransport()`
 * và `createRoundrobinTransport()` dựng transport con bằng cách gọi lại chính
 * `createSymfonyTransport()`, nên mỗi transport con cũng được bọc. Với một cấu hình `failover`,
 * một lần hỏng ở máy chủ thứ nhất rồi gửi được ở máy chủ thứ hai sẽ để lại một dòng `sent` mà
 * cột `error` vẫn còn lời của lần hỏng — đọc đúng theo nghĩa "đã có một lần trượt", nhưng phải
 * biết trước mới đọc ra. Dự án hôm nay chỉ có một máy chủ SMTP (SPEC §2), nên tình huống này
 * chưa xảy ra; ngày bật `failover` thì đây là chỗ phải quyết định lại.
 *
 * Đăng ký ở `AppServiceProvider::register()` bằng `extend('mail.manager', …)` chứ không bằng
 * `singleton()`: `Illuminate\Mail\MailServiceProvider` là một provider HOÃN, nên nó đăng ký lại
 * `mail.manager` vào lúc ai đó phân giải `mailer` hay `Markdown::class` và sẽ ghi đè một
 * `singleton()` đặt sớm. Bộ extender của container thì sống sót qua lần đăng ký lại đó.
 */
class OutboundLedgerMailManager extends MailManager
{
    public function createSymfonyTransport(array $config): TransportInterface
    {
        return new OutboundLedgerTransport(
            parent::createSymfonyTransport($config),
            $this->app->make(RecordOutboundMessage::class),
        );
    }
}
