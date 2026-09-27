<?php

namespace App\Support;

/**
 * URL gốc của cổng khách hàng (SPEC §3, panel `portal`) — ĐỘC LẬP với request hiện tại.
 *
 * M6.5 Task 12 (`notify/notify-10`, `spec-gap/spec-gap-09`): `url('/portal')` (bản trước) lấy
 * scheme+host của REQUEST HIỆN TẠI. Thư báo tiến độ (`App\Mail\Client\StageUpdate`) được dựng
 * ngay sau một request Livewire ở panel `/admin` (kể cả từ Task 11, khi việc gửi đã chuyển sang
 * job hàng đợi: job đó chạy ngay sau khi transaction của request `/admin` commit, và không có gì
 * đặt lại `request()` về một giá trị "trung lập"). Khi `ADMIN_DOMAIN`/`PORTAL_DOMAIN` tách ra hai
 * tên miền riêng (SPEC §3 đòi chạy được cả hai cách), `url('/portal')` dựng ra
 * `https://<tên miền quản trị>/portal` — một liên kết 404 với khách, và lộ cả tên miền quản trị
 * vào hộp thư khách.
 *
 * `Filament::getPanel('portal')->getUrl()` KHÔNG giải quyết được: nó chỉ dùng `route('filament.
 * portal.home')` khi route đó tồn tại (panel này không đăng ký trang nào ở khoá `home`, xem
 * `PortalPanelProvider::pages([])`), nên rơi về nhánh cuối `url($this->getPath())` — CÙNG một lỗi
 * lấy theo request hiện tại (đã đo bằng tay trước khi viết lớp này, xem báo cáo).
 *
 * Vì vậy lớp này dựng URL thẳng từ CẤU HÌNH, không đụng `request()`/`url()` một chút nào:
 * `PORTAL_DOMAIN` khi có, còn không thì `APP_URL` (một tên miền, cổng và quản trị dùng chung —
 * đúng NGUYÊN VĂN giá trị mà cấu hình ấy đại diện, không phải "host đang phục vụ request này").
 */
final class PortalUrl
{
    /**
     * Đường dẫn của panel `portal`, đúng nguyên văn `->path('portal')` ở `PortalPanelProvider`.
     * Không đọc lại từ `Filament::getPanel()` (xem docblock lớp: đường đó vẫn dẫn về `url()`) —
     * hai chỗ CÙNG hằng số này là hằng số của SPEC §3, không phải một cấu hình có thể đổi tự do.
     */
    private const PATH = 'portal';

    /** URL gốc của cổng khách hàng, ví dụ `https://khachhang.luatvukhang.com/portal`. */
    public static function base(): string
    {
        $domain = config('vkcrm.portal_domain');

        if ($domain !== null) {
            return self::scheme().'://'.$domain.'/'.self::PATH;
        }

        return rtrim((string) config('app.url'), '/').'/'.self::PATH;
    }

    /** `APP_URL` là nguồn DUY NHẤT của scheme — không có gì khác đáng tin khi không có request. */
    private static function scheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';
    }
}
