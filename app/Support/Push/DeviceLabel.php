<?php

namespace App\Support\Push;

/**
 * M12 R8 — nhãn thiết bị rút từ User-Agent lúc bấm "Bật trên máy này" ("iPhone · Safari"), để trang
 * "Thông báo trên điện thoại" cho người dùng nhận ra máy nào là máy nào, và để audit
 * `push_device_added`/`push_device_removed` có thứ để ghi mà KHÔNG ghi endpoint.
 *
 * Chỉ là một nhãn để đọc, không phải một phép nhận dạng: User-Agent do trình duyệt tự khai, nên
 * không quyết định gì theo nó. Tên hệ điều hành/trình duyệt là danh từ riêng, viết nguyên; câu
 * tiếng Việt ("Thiết bị không rõ", "Ứng dụng đã cài") qua `lang/vi/push.php`.
 *
 * Thứ tự so khớp có nghĩa: User-Agent của iPhone chứa "like Mac OS X" (xét iPhone trước Mac), của
 * Android chứa "Linux" (Android trước Linux), của Edge/Opera/Samsung chứa "Chrome/" và của mọi
 * trình duyệt Chromium chứa "Safari/" (xét tên riêng trước). App đã "Thêm vào Màn hình chính" trên
 * iPhone/iPad gửi User-Agent WebKit KHÔNG có "Safari/" — đó là chỗ duy nhất iOS cho nhận push.
 */
final class DeviceLabel
{
    /** Độ dài cột `push_subscriptions.device_label` (migration `…_000002_…`). */
    public const MAX_LENGTH = 100;

    public static function fromUserAgent(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS'), str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            in_array($os, ['iPhone', 'iPad'], true) && str_contains($ua, 'AppleWebKit') => __('push.devices.installed_app'),
            default => null,
        };

        $parts = array_values(array_filter([$os, $browser]));
        $label = $parts === [] ? __('push.devices.unknown_device') : implode(' · ', $parts);

        return mb_substr($label, 0, self::MAX_LENGTH);
    }
}
