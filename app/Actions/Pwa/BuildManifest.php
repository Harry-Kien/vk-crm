<?php

namespace App\Actions\Pwa;

use App\Support\Pwa\AppIcons;
use App\Support\Pwa\PwaPanels;

/**
 * Manifest của app trên điện thoại cho MỘT panel (kế hoạch M12, phán quyết R2): hai app, một máy
 * chủ — `/admin` (nội bộ) và `/portal` (khách hàng) cài thành hai biểu tượng riêng.
 *
 * **`id`, `scope`, `start_url` đều là `$path`** — path của URL panel, không có dấu `/` cuối, do
 * nơi gọi lấy từ {@see PwaPanels::path()} (Action không hỏi Filament —
 * `tests/Feature/ArchitectureTest.php`). Hai `id` khác nhau để Chrome coi đây là hai app trên cùng
 * origin (phán quyết tạm 3 của Task 1); lý do không có dấu `/` cuối ở docblock `PwaPanels`.
 *
 * **Màu đọc từ `config('vkcrm.brand.colors')`** (navy cho `theme_color`, paper cho
 * `background_color`) — không viết mã màu lần thứ hai. Tên qua `lang/vi/pwa.php`, ghép tên ngắn
 * của văn phòng từ `config('vkcrm.brand.short_name')`. Biểu tượng: hai bản `any` (192, 512) và
 * bản maskable RIÊNG của panel ({@see AppIcons}).
 *
 * Manifest là tài nguyên CÔNG KHAI, được trình duyệt tải không kèm cookie: không tên khách, không
 * dữ liệu phiên, và Action này không đọc người đang đăng nhập.
 */
final class BuildManifest
{
    /** @return array<string, mixed> */
    public function handle(string $panel, string $path): array
    {
        $firm = ['firm' => config('vkcrm.brand.short_name')];

        return [
            'id' => $path,
            'name' => __("pwa.{$panel}.name", $firm),
            'short_name' => __("pwa.{$panel}.short_name", $firm),
            'lang' => 'vi',
            'dir' => 'ltr',
            'start_url' => $path,
            'scope' => $path,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => config('vkcrm.brand.colors.navy'),
            'background_color' => config('vkcrm.brand.colors.paper'),
            'icons' => [
                ...collect(AppIcons::ANY)->map(fn (string $file, int $size): array => $this->icon($file, $size, 'any'))->values(),
                $this->icon(AppIcons::maskable($panel), AppIcons::MASKABLE_SIZE, 'maskable'),
            ],
        ];
    }

    /** @return array{src: string, sizes: string, type: string, purpose: string} */
    private function icon(string $file, int $size, string $purpose): array
    {
        return [
            'src' => asset($file),
            'sizes' => "{$size}x{$size}",
            'type' => 'image/png',
            'purpose' => $purpose,
        ];
    }
}
