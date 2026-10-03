<?php

use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\OfflinePageController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use App\Support\Pwa\PwaPanels;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M12 — tài nguyên công khai của app trên điện thoại (kế hoạch M12, phán quyết R2)
|--------------------------------------------------------------------------
|
| Đăng ký ở `bootstrap/app.php` (`then:`), NGOÀI nhóm `web` và NGOÀI chồng middleware có phiên của
| panel:
|
|  - trình duyệt tải manifest KHÔNG kèm cookie. Qua `StartSession` với `SESSION_DRIVER=database`
|    (`.env.example`), mỗi lần tải sẽ đẻ một dòng `sessions` mồ côi — có test đếm dòng;
|  - đây là tài nguyên công khai: không tên khách, không dữ liệu phiên. Middleware TOÀN CỤC vẫn
|    chạy (`SendSecurityHeaders`, `EnforceHttps`, `TrustProxies`…) — đúng ý; chỉ phần có phiên
|    (cookie, `StartSession`, CSRF, `Authenticate`) không có mặt.
|
| **Giới hạn IP của admin (M8 R7) VẪN phủ nhóm này.** Đứng ngoài panel là vì PHIÊN, không phải để
| né allowlist: nhóm route PWA của một panel mang `RestrictAdminIpAllowlist` khi và chỉ khi chính
| panel đó mang nó trong `getMiddleware()` — hôm nay là `admin`, không phải `portal`. Middleware đó
| chỉ đọc `$request->ip()` (sau `TrustProxies` toàn cục) và không đụng phiên, nên vẫn không cookie,
| không dòng `sessions`. Lý do: kế hoạch M8 đòi MỌI request vào `/admin` từ IP ngoài
| `ADMIN_IP_ALLOWLIST` nhận 404, để máy lạ không biết `/admin` tồn tại — manifest nội bộ mang tên
| "… — Nội bộ" và `scope` `/admin`, tự nó đã chỉ đường vào app nội bộ. Không mất chức năng: app nội
| bộ chỉ cài được từ IP trong danh sách (trang panel 404 ở nơi khác). Ngoài văn phòng, lần trình
| duyệt tải lại manifest hay `sw.js` nhận 404; theo đặc tả Service Worker (thuật toán Update), một
| response không `ok` làm hỏng lượt cập nhật đó và giữ nguyên bản đã cài — điều này chưa đo trên
| máy thật. Cổng khách không có allowlist nên manifest của cổng tới mọi IP. Có test cả hai chiều
| (`tests/Feature/Pwa/ManifestTest.php`).
|
| **Tên miền theo khuôn vòng lặp của Filament** (`vendor/filament/filament/routes/web.php`):
| `Panel::getDomains()` — tức `config('vkcrm.admin_domain')`/`config('vkcrm.portal_domain')` mà hai
| provider đặt bằng `->domains(array_filter([…]))` — rỗng thì route không gắn tên miền, có thì route
| gắn đúng tên miền đó. Tiền tố là `Panel::getPath()`. Task 3 thêm `sw.js` và trang ngoại tuyến vào
| cùng nhóm, nên chúng nhận cùng giới hạn IP với manifest.
|
| Khác Filament ở một chỗ: tên route LUÔN là `pwa.{panel}.manifest`, không chèn tên miền vào giữa
| như Filament làm khi một panel có từ hai tên miền trở lên. Provider chỉ cho MỘT tên miền mỗi panel
| (một biến môi trường), nên nhánh đó không bao giờ chạy và không test được; thẻ `<head>`
| (`resources/views/pwa/head.blade.php`) tra đúng một tên. Ngày một panel có hai tên miền thì phải
| sửa cả hai nơi.
*/

foreach (PwaPanels::IDS as $panelId) {
    $panel = Filament::getPanel($panelId);
    $domains = $panel->getDomains();

    $ipGate = in_array(RestrictAdminIpAllowlist::class, $panel->getMiddleware(), true)
        ? [RestrictAdminIpAllowlist::class]
        : [];

    foreach ((empty($domains) ? [null] : $domains) as $domain) {
        Route::domain($domain)
            ->prefix($panel->getPath())
            ->name("pwa.{$panelId}.")
            ->middleware($ipGate)
            ->group(function () use ($panelId): void {
                Route::get('manifest.webmanifest', ManifestController::class)
                    ->defaults('panel', $panelId)
                    ->name('manifest');

                Route::get('sw.js', ServiceWorkerController::class)
                    ->defaults('panel', $panelId)
                    ->name('sw');

                Route::get('offline', OfflinePageController::class)
                    ->defaults('panel', $panelId)
                    ->name('offline');
            });
    }
}
