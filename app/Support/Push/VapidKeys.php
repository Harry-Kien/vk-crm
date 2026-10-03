<?php

namespace App\Support\Push;

use App\Actions\Deployment\RunPreflight;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * M12 R7 — "thiếu khoá thì push TẮT êm": MỘT định nghĩa của "máy chủ này đã có khoá VAPID dùng
 * được", cho mọi nơi cần hỏi — hôm nay `vkcrm:preflight` (dòng VAPID, {@see RunPreflight}); theo
 * kế hoạch còn nút "Bật" và lượt kiểm `sync=1` của Task 5, việc xếp job của Task 7. Không nơi nào tự
 * đọc `config('webpush.vapid…')` để quyết điều này.
 *
 * Đã cấu hình khi và chỉ khi:
 *  - ba biến `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` không trống — `blank()` coi
 *    chuỗi rỗng là trống: dòng `KEY=` của `.env.example` (CI chạy `cp .env.example .env`) là một
 *    biến TỒN TẠI với chuỗi rỗng, không phải "không khai báo";
 *  - cặp khoá qua được `Minishlink\WebPush\VAPID::validate()` — đúng hàm thư viện chạy lúc dựng
 *    `WebPush` (khoá công khai 65 byte, khoá riêng 32 byte, base64url). Không qua thì dựng kênh ném
 *    `ErrorException` ở mọi job; tắt êm + dòng VÀNG tốt hơn một hàng đợi đầy job hỏng;
 *  - `VAPID_SUBJECT` là `mailto:<địa chỉ>` hoặc `https://…` (RFC 8292 §2.1; máy chủ push của Apple
 *    từ chối khi thiếu). Gói tự lấp `url('/')` khi trống — ở đây trống là chưa cấu hình, vì kế hoạch
 *    đòi hộp thư có người đọc của văn phòng.
 *
 * KHÔNG kiểm hai khoá có cùng một cặp hay không: lệch cặp chỉ lộ ra khi máy chủ push từ chối chữ ký
 * (401/403) lúc gửi thật.
 */
final class VapidKeys
{
    /** Biến `.env` ứng với từng khoá con của `webpush.vapid`, theo thứ tự in ra. */
    private const VARIABLES = [
        'VAPID_PUBLIC_KEY' => 'public_key',
        'VAPID_PRIVATE_KEY' => 'private_key',
        'VAPID_SUBJECT' => 'subject',
    ];

    public static function configured(): bool
    {
        return self::missing() === [] && self::keysAreValid() && self::subjectIsValid();
    }

    /**
     * Khoá CÔNG KHAI (base64url, 65 byte) mà trình duyệt cần cho `pushManager.subscribe()` — `null`
     * khi chưa cấu hình đủ ({@see self::configured()}). Khoá công khai đúng như tên gọi: in ra
     * `data-push-key` của thẻ `register.js` (M12 Task 5) là việc của nó; khoá riêng thì không bao
     * giờ rời máy chủ.
     */
    public static function publicKey(): ?string
    {
        return self::configured() ? (string) config('webpush.vapid.public_key') : null;
    }

    /**
     * Tên các biến đang trống (null, chuỗi rỗng, chỉ khoảng trắng).
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $missing = [];

        foreach (self::VARIABLES as $variable => $key) {
            if (blank(config('webpush.vapid.'.$key))) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    public static function keysAreValid(): bool
    {
        try {
            VAPID::validate([
                'subject' => 'mailto:kiem-tra@localhost',
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public static function subjectIsValid(): bool
    {
        $subject = (string) config('webpush.vapid.subject');

        return preg_match('/^mailto:[^@\s]+@[^@\s]+$/', $subject) === 1
            || preg_match('#^https://[^\s/]+#', $subject) === 1;
    }
}
