<?php

use App\Http\Controllers\Pwa\ManifestController;
use App\Support\Pwa\PwaPanels;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M12 — tài nguyên công khai của app trên điện thoại (kế hoạch M12, phán quyết R2)
|--------------------------------------------------------------------------
|
| Đăng ký ở `bootstrap/app.php` (`then:`), NGOÀI nhóm `web` và NGOÀI middleware của panel:
|
|  - trình duyệt tải manifest KHÔNG kèm cookie. Qua `StartSession` với `SESSION_DRIVER=database`
|    (`.env.example`), mỗi lần tải sẽ đẻ một dòng `sessions` mồ côi — có test đếm dòng;
|  - đây là tài nguyên công khai: không tên khách, không dữ liệu phiên. Middleware TOÀN CỤC vẫn
|    chạy (`SendSecurityHeaders`, `EnforceHttps`, `TrustProxies`…) — đúng ý; chỉ phần có phiên
|    (cookie, `StartSession`, CSRF, `Authenticate`) không có mặt;
|  - vì ngoài middleware của panel, `RestrictAdminIpAllowlist` (M8 R7) không chặn manifest của
|    app nội bộ. Có chủ đích: manifest không chứa gì riêng tư, và một máy ngoài danh sách IP vẫn
|    nhận 404 ở mọi TRANG của panel (câu hỏi 2 cho chủ văn phòng trong kế hoạch) — có test.
|
| **Tên miền theo khuôn vòng lặp của Filament** (`vendor/filament/filament/routes/web.php`):
| `Panel::getDomains()` — tức `config('vkcrm.admin_domain')`/`config('vkcrm.portal_domain')` mà hai
| provider đặt bằng `->domains(array_filter([…]))` — rỗng thì route không gắn tên miền, có thì route
| gắn đúng tên miền đó. Tiền tố là `Panel::getPath()`. Task 3 thêm `sw.js` và trang ngoại tuyến vào
| cùng nhóm.
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

    foreach ((empty($domains) ? [null] : $domains) as $domain) {
        Route::domain($domain)
            ->prefix($panel->getPath())
            ->name("pwa.{$panelId}.")
            ->group(function () use ($panelId): void {
                Route::get('manifest.webmanifest', ManifestController::class)
                    ->defaults('panel', $panelId)
                    ->name('manifest');
            });
    }
}
