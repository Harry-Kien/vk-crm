<?php

namespace App\Support\Settings;

use App\Support\OfficeProfile;

/**
 * Trạng thái gửi thư của máy chủ, đọc-chỉ (làn st) — cho thẻ "Thư điện tử" của trang tổng "Cài đặt"
 * và trang `App\Filament\Admin\Pages\Settings\MailSettingsPage`. Đọc cấu hình `mail.*` đang chạy
 * (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_*` của `.env`); KHÔNG BAO GIỜ đọc ra mật khẩu hay tên đăng
 * nhập SMTP — không hàm nào ở đây trả chúng.
 *
 * "Đã cấu hình gửi thật" = bộ gửi không phải `log`/`array` (hai bộ chỉ ghi lại, không gửi đi đâu) và,
 * với `smtp`, có máy chủ. Mọi bộ gửi khác (`ses`, `postmark`, `resend`, `sendmail`, `failover`…) được
 * coi là gửi thật và chỉ nêu tên — nút "Gửi thư thử" là cách kiểm chứng.
 */
final class MailStatus
{
    /** Bộ gửi không gửi thư đi đâu cả. */
    public const NOT_DELIVERING = ['log', 'array'];

    public static function mailer(): string
    {
        return (string) config('mail.default');
    }

    public static function delivers(): bool
    {
        $mailer = self::mailer();

        if (in_array($mailer, self::NOT_DELIVERING, true)) {
            return false;
        }

        return $mailer !== 'smtp' || filled(config('mail.mailers.smtp.host'));
    }

    /** Một câu tiếng Việt nói thư đi bằng đường nào — không mật khẩu, không tên đăng nhập. */
    public static function transportLabel(): string
    {
        $mailer = self::mailer();

        return match ($mailer) {
            'smtp' => __('settings.mail.transport.smtp', [
                'host' => (string) config('mail.mailers.smtp.host'),
                'port' => (string) config('mail.mailers.smtp.port'),
            ]),
            'log', 'array' => __('settings.mail.transport.'.$mailer),
            default => __('settings.mail.transport.other', ['mailer' => $mailer]),
        };
    }

    public static function fromAddress(): ?string
    {
        $address = config('mail.from.address');

        return filled($address) ? (string) $address : null;
    }

    public static function fromName(): ?string
    {
        $name = config('mail.from.name');

        return filled($name) ? (string) $name : null;
    }

    /** Địa chỉ nhận khi người nhận bấm "Trả lời" — "Email liên hệ" của trang Thông tin văn phòng. */
    public static function replyTo(): ?string
    {
        return OfficeProfile::current()->replyTo();
    }
}
