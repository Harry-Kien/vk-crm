<?php

namespace Tests\Support;

use Minishlink\WebPush\VAPID;
use RuntimeException;

/**
 * Khoá cho test thông báo đẩy (M12 R7) — SINH trong tiến trình test, không bao giờ là khoá thật và
 * không nằm trong repo dưới dạng giá trị: `.env` của máy dev có thể mang khoá thật do
 * `artisan webpush:vapid` ghi vào, và một khoá dev chép vào test là một khoá dev lộ ra GitHub.
 *
 * - {@see self::vapid()}: cặp VAPID của máy chủ (định dạng mà `webpush:vapid` ghi vào `.env`),
 *   sinh bằng chính hàm của thư viện, một lần cho mỗi tiến trình.
 * - {@see self::subscription()}: cặp `p256dh`/`auth` mà TRÌNH DUYỆT gửi lên lúc đăng ký — điểm
 *   P-256 không nén (65 byte, mở đầu 0x04) và 16 byte ngẫu nhiên, base64url. Phải là một điểm thật
 *   trên đường cong: thư viện mã hoá payload (aes128gcm, RFC 8291) bằng khoá này trước khi gửi.
 */
final class WebPushTestKeys
{
    /** @var array{public: string, private: string}|null */
    private static ?array $vapid = null;

    /** @return array{public: string, private: string} */
    public static function vapid(): array
    {
        if (self::$vapid === null) {
            $keys = VAPID::createVapidKeys();
            self::$vapid = ['public' => $keys['publicKey'], 'private' => $keys['privateKey']];
        }

        return self::$vapid;
    }

    /**
     * Cấu hình `webpush.vapid` đầy đủ và hợp lệ, để `config([...])` trong test.
     *
     * @return array<string, string>
     */
    public static function config(): array
    {
        $keys = self::vapid();

        return [
            'webpush.vapid.subject' => 'mailto:kiem-thu@example.test',
            'webpush.vapid.public_key' => $keys['public'],
            'webpush.vapid.private_key' => $keys['private'],
        ];
    }

    /** @return array{p256dh: string, auth: string} */
    public static function subscription(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('openssl không sinh được khoá P-256 cho test.');
        }

        $ec = openssl_pkey_get_details($key)['ec'];
        $point = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return [
            'p256dh' => self::base64Url($point),
            'auth' => self::base64Url(random_bytes(16)),
        ];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
