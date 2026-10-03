<?php

namespace App\Actions\Pwa;

use App\Support\Pwa\PwaPanels;

/**
 * Trang ngoại tuyến của app trên điện thoại cho MỘT panel (kế hoạch M12, phán quyết R4) —
 * `GET /{admin,portal}/offline`, rồi được service worker cài vào bộ đệm lúc `install` và trả thay
 * cho một lần điều hướng gặp LỖI MẠNG.
 *
 * Trang TĨNH và công khai: chỉ đọc config (tên văn phòng, hotline) và `$path` của panel; không đọc
 * người đang đăng nhập, không phiên, không script (`resources/views/pwa/offline.blade.php`). Lý do
 * chặt như vậy: bản trong bộ đệm nằm trên điện thoại tới lần VERSION kế tiếp và hiện ra ở mọi URL
 * mà người dùng đang mở lúc mất mạng.
 *
 * "Thử lại" trỏ về `start_url` của app (`$path`, {@see PwaPanels::path()}), không về URL đang mở:
 * trang này được trả TẠI URL gặp lỗi, nên một liên kết tương đối sẽ tải lại đúng URL đó; về
 * `start_url` thì luật quyền của trang đích chạy lại từ đầu.
 *
 * Dùng chung bởi controller (nội dung phục vụ) và {@see BuildServiceWorker} (băm vào VERSION, để
 * một lần đổi hotline hay câu chữ thay bản đã cài trên điện thoại).
 */
final class RenderOfflinePage
{
    /** Logo trên trang, đường dẫn dưới `public/`; cũng nằm trong danh sách cài sẵn của worker. */
    public const LOGO = 'brand/vk-mark-96.png';

    public function handle(string $panel, string $path): string
    {
        $hotline = (string) config('vkcrm.brand.hotline');

        return view('pwa.offline', [
            'title' => __("pwa.{$panel}.name", ['firm' => config('vkcrm.brand.short_name')]),
            'firm' => (string) config('vkcrm.brand.short_name'),
            'hotline' => $hotline,
            'tel' => (string) preg_replace('/[^0-9+]/', '', $hotline),
            'startUrl' => $path,
            'logo' => asset(self::LOGO),
        ])->render();
    }
}
