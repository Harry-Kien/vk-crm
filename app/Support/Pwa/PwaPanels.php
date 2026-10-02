<?php

namespace App\Support\Pwa;

use Filament\Facades\Filament;

/**
 * Hai panel có app trên điện thoại và đường dẫn (scope) của mỗi app — kế hoạch M12, phán quyết R2.
 *
 * {@see self::path()} là PATH của URL panel, không có dấu `/` cuối: `/admin`, `/portal`. Lấy từ
 * `Filament::getPanel($panel)->getUrl()` rồi rút phần path bằng `parse_url` — không viết cứng. Với
 * panel `portal` (không có route `home`), `getUrl()` rơi về `url($this->getPath())` nên HOST đi theo
 * request hiện tại (docblock `App\Support\PortalUrl`); chỉ phần path được dùng nên điều đó không
 * ảnh hưởng. Scope có dấu `/` cuối thì Chromium bỏ scope (vì `start_url` `/admin` nằm ngoài
 * `/admin/`) và rơi về cả origin — app nội bộ khi đó "nuốt" luôn `/portal` (đo ở
 * `docs/research/2026-10-01-pwa-khao-sat.md` mục 2.3).
 *
 * Nằm ở `Support` chứ không trong Action vì nó hỏi Filament (`tests/Feature/ArchitectureTest.php`:
 * `App\Actions` không phụ thuộc Filament). Task 3 dùng lại cho scope của `sw.js` và header
 * `Service-Worker-Allowed`.
 */
final class PwaPanels
{
    /** `Filament\Panel::getId()` của hai panel có app trên điện thoại. */
    public const IDS = ['admin', 'portal'];

    public static function path(string $panel): string
    {
        if (! in_array($panel, self::IDS, true)) {
            throw new \InvalidArgumentException("Panel [{$panel}] không có app trên điện thoại.");
        }

        return '/'.trim((string) parse_url((string) Filament::getPanel($panel)->getUrl(), PHP_URL_PATH), '/');
    }
}
