<?php

namespace App\Support\Pwa;

use App\Actions\Pwa\BuildManifest;

/**
 * Tên tệp biểu tượng của hai app trên điện thoại (kế hoạch M12, phán quyết R3) — MỘT chỗ, đọc
 * bởi manifest ({@see BuildManifest}), thẻ `<head>` (`resources/views/pwa/head.blade.php`),
 * công cụ sinh ảnh (`tools/brand/make-logo.php`) và test (`tests/Feature/Pwa/IconsTest.php`).
 *
 * Đường dẫn tương đối với `public/` (đưa vào `asset()` để có URL). Mọi tệp là PNG tĩnh đã commit;
 * không ảnh nào sinh lúc chạy ứng dụng.
 *
 *  - {@see self::ANY}: con dấu nền trong suốt, cùng họ với `brand/vk-mark-*.png`, mục đích `any`
 *    trong manifest. Chrome đòi 192 và 512 cho tiêu chí cài đặt.
 *  - {@see self::maskable()}: 512×512, con dấu trong vùng an toàn 80% trên nền đặc của panel —
 *    Android cắt ảnh theo hình của launcher.
 *  - {@see self::appleTouch()}: 180×180, ĐỤC hoàn toàn — iOS tô đen phần trong suốt.
 *
 * Maskable và `apple-touch-icon` là RIÊNG cho từng panel (nền theo
 * `config('vkcrm.pwa.icon_background')`). Kế hoạch chỉ nêu một tệp `app-180.png`; tách hai tệp
 * vì iOS không dùng biểu tượng maskable, nên trên iPhone `apple-touch-icon` là thứ DUY NHẤT để
 * một nhân sự cài cả hai app phân biệt được chúng bằng mắt — đúng lý do R3 tách nền maskable.
 */
final class AppIcons
{
    /** Biểu tượng mục đích `any`, theo cạnh (px). */
    public const ANY = [
        192 => 'brand/vk-mark-192.png',
        512 => 'brand/vk-mark-512.png',
    ];

    public const MASKABLE_SIZE = 512;

    public const APPLE_TOUCH_SIZE = 180;

    public static function maskable(string $panel): string
    {
        return 'brand/app-'.self::panel($panel).'-maskable-'.self::MASKABLE_SIZE.'.png';
    }

    public static function appleTouch(string $panel): string
    {
        return 'brand/app-'.self::panel($panel).'-'.self::APPLE_TOUCH_SIZE.'.png';
    }

    private static function panel(string $panel): string
    {
        if (! in_array($panel, PwaPanels::IDS, true)) {
            throw new \InvalidArgumentException("Panel [{$panel}] không có app trên điện thoại.");
        }

        return $panel;
    }
}
