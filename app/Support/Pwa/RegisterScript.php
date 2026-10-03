<?php

namespace App\Support\Pwa;

use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Support\Push\PushSession;
use App\Support\Push\VapidKeys;
use Filament\Facades\Filament;

/**
 * URL của script đăng ký `public/pwa/register.js` (kế hoạch M12, phán quyết R5), kèm `?v=` là 12
 * ký tự hex đầu của sha256 nội dung tệp.
 *
 * Vì sao phải có `?v=`: cả hai mẫu máy chủ web (`tools/deploy/nginx.conf.example`, khối
 * `location ~* \.(?:css|js|…)$`; `apache-vhost.conf.example`, `<FilesMatch>`) gửi
 * `Cache-Control: public, max-age=31536000, immutable` cho mọi `.js` tĩnh — trình duyệt giữ tệp
 * một năm KHÔNG hỏi lại. Không có tham số đổi theo nội dung thì một bản sửa của `register.js` không
 * bao giờ tới điện thoại đã cài. Băm đọc lúc render trang (một lần đọc tệp nhỏ); không cache, để
 * một lần triển khai không phải nhớ xoá gì.
 *
 * Đọc qua `public_path()` — test thay thư mục `public` bằng `app()->usePublicPath()` để đổi tệp mà
 * không đụng tệp thật (`tests/Feature/Pwa/RegisterScriptTest.php`).
 */
final class RegisterScript
{
    public const FILE = 'pwa/register.js';

    public static function url(): string
    {
        return asset(self::FILE).'?v='.substr((string) hash_file('sha256', public_path(self::FILE)), 0, 12);
    }

    /**
     * M12 Task 5 (R8) — ba thuộc tính `data-*` của phần push, chỉ trên trang ĐÃ ĐĂNG NHẬP của panel
     * hiện hành và chỉ khi máy chủ có khoá VAPID ({@see VapidKeys::configured()}); mảng rỗng ở mọi chỗ
     * khác — trang đăng nhập, khách vãng lai, máy chủ chưa bật push (R7: không nút, không lượt kiểm).
     *
     *  - `push-key`: khoá CÔNG KHAI VAPID — `pushManager.subscribe()` cần nó, và script so nó với khoá
     *    của đăng ký đang có (R7: đổi khoá thì mời bật lại).
     *  - `push-url`: `POST`/`DELETE …/push/subscriptions` của panel này ({@see PushSubscriptionController}).
     *  - `push-check`: `1` khi lượt kiểm `sync=1` CHƯA chạy trong phiên máy chủ này
     *    ({@see PushSession::checkedKey()}), `0` khi đã chạy.
     *
     * Không thuộc tính nào là dữ liệu của người dùng: không tên, không endpoint (endpoint trong phiên
     * chỉ được so ở máy chủ).
     *
     * @return array<string, string> khoá là phần sau `data-`
     */
    public static function pushData(string $panel): array
    {
        $key = VapidKeys::publicKey();

        if ($key === null || ! Filament::auth()->check()) {
            return [];
        }

        return [
            'push-key' => $key,
            'push-url' => Filament::getPanel($panel)->route('push.subscriptions.store'),
            'push-check' => session()->has(PushSession::checkedKey(Filament::getAuthGuard())) ? '0' : '1',
        ];
    }
}
