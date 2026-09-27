<?php

namespace App\Support;

use Filament\Facades\Filament;

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
 * `Filament::getPanel('portal')->getUrl()` KHÔNG giải quyết được cho việc DỰNG LIÊN KẾT: nó chỉ
 * dùng `route('filament.portal.home')` khi route đó tồn tại (panel này không đăng ký trang nào ở
 * khoá `home`, xem `PortalPanelProvider::pages([])`), nên rơi về nhánh cuối `url($this->getPath())`
 * — CÙNG một lỗi lấy theo request hiện tại (đã đo bằng tay trước khi viết lớp này, xem báo cáo).
 *
 * Vì vậy lớp này dựng URL thẳng từ CẤU HÌNH, không đụng `request()`/`url()` một chút nào:
 * `PORTAL_DOMAIN` khi có, còn không thì `APP_URL` (một tên miền, cổng và quản trị dùng chung —
 * đúng NGUYÊN VĂN giá trị mà cấu hình ấy đại diện, không phải "host đang phục vụ request này").
 *
 * **Đường dẫn (vòng sửa 1, minor): `Filament::getPanel('portal')->getPath()`, không phải một
 * hằng số gõ tay.** `Panel::getPath()` (khác `getUrl()`) chỉ đọc thẳng thuộc tính `$path` đã đặt
 * bằng `->path('portal')` ở `PortalPanelProvider` — một getter THUẦN, không đụng `request()`/
 * `url()` chút nào (đã đọc mã nguồn `Filament\Panel`/`HasRoutes` để xác nhận, không suy đoán).
 * Gọi thẳng nó thay vì lặp lại chuỗi `'portal'` ở một hằng số riêng: một chỗ đổi đường dẫn panel
 * (hiếm, nhưng SPEC §3 không cấm) mà quên đổi hằng số này sẽ để liên kết trong thư trỏ sai, một
 * lỗi không test cấu hình panel nào bắt được vì hai nơi ĐỘC LẬP với nhau.
 */
final class PortalUrl
{
    /** URL gốc của cổng khách hàng, ví dụ `https://khachhang.luatvukhang.com/portal`. */
    public static function base(): string
    {
        return self::root().'/'.self::path();
    }

    /**
     * URL của một tệp tĩnh (ví dụ logo thư) phục vụ từ CÙNG gốc với cổng khách hàng — vòng sửa 1
     * (minor): `asset()` của Laravel dựng URL theo REQUEST HIỆN TẠI (hay `APP_URL` khi không có
     * request nào, ví dụ trong `queue:work`), cùng lỗi mà {@see self::base()} đã sửa cho liên kết
     * cổng — một thư cho KHÁCH dựng dưới `queue:work` sau một request `/admin` có thể mang logo
     * trỏ vào tên miền QUẢN TRỊ nếu `APP_URL` được đặt là tên miền đó. Dùng CHUNG gốc với
     * `base()` (tên miền cổng khi tách riêng, không phải tên miền đang phục vụ request nào).
     */
    public static function asset(string $path): string
    {
        return self::root().'/'.ltrim($path, '/');
    }

    /** Scheme + tên miền, KHÔNG kèm đường dẫn — dùng chung bởi {@see self::base()} và {@see self::asset()}. */
    private static function root(): string
    {
        $domain = config('vkcrm.portal_domain');

        if ($domain !== null) {
            return self::scheme().'://'.$domain;
        }

        return rtrim((string) config('app.url'), '/');
    }

    private static function path(): string
    {
        return trim(Filament::getPanel('portal')->getPath(), '/');
    }

    /** `APP_URL` là nguồn DUY NHẤT của scheme — không có gì khác đáng tin khi không có request. */
    private static function scheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'https';
    }
}
