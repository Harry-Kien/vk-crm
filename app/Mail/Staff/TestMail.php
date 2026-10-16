<?php

namespace App\Mail\Staff;

use App\Mail\BrandedMailable;
use App\Models\User;
use App\Support\OfficeProfile;
use App\Support\Settings\MailStatus;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Thư thử (làn st) — nút "Gửi thư thử" của trang Cài đặt › Thư điện tử, tới email của CHÍNH người
 * bấm, để quản trị viên kiểm chứng máy chủ gửi thư sau khi đổi cấu hình. Đi qua đúng đường của mọi
 * thư khác: hàng đợi (`App\Jobs\SendTestMail`), Reply-To mặc định của {@see BrandedMailable}, và sổ
 * thư `outbound_messages` (mẫu {@see self::TEMPLATE}). Không mang dữ liệu hồ sơ nào: chỉ tên người
 * nhận, thời điểm gửi và đường thư đi ({@see MailStatus::transportLabel()} — không mật khẩu).
 *
 * Không gửi lại từ sổ thư (`ResendTargets::NOT_RESENDABLE`): bấm lại nút là đủ.
 */
class TestMail extends BrandedMailable
{
    public const TEMPLATE = 'staff.test_mail';

    public function __construct(public User $recipient) {}

    protected function template(): string
    {
        return self::TEMPLATE;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('settings.mail.email.subject'));
    }

    /** Đọc thông tin văn phòng và đường thư LÚC RENDER, không lúc xếp hàng — xem docblock `OfficeProfile`. */
    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.test-mail',
            text: 'emails.staff.test-mail-text',
            with: [
                'recipientName' => $this->recipient->name,
                'sentAt' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'transport' => MailStatus::transportLabel(),
                'office' => OfficeProfile::current()->legalName(),
            ],
        );
    }
}
