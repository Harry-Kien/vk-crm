<?php

namespace App\Support\Pwa;

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
}
