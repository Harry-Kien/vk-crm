<?php

use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Http\Middleware\SendSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // M12 R2 — manifest (Task 3: `sw.js`, trang ngoại tuyến) của hai app trên điện thoại: NGOÀI
        // nhóm `web`, tức không cookie, không phiên. Lý do ở docblock `routes/pwa.php`.
        then: function (): void {
            Route::group([], base_path('routes/pwa.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Proxy được tin KHÔNG khai báo ở đây, và chỗ này ghi ra lý do để lần sau không ai đi
         * tìm nó ở đây nữa.
         *
         * `$middleware->trustProxies(at: ...)` gọi `TrustProxies::at()`, một thuộc tính TĨNH
         * được đọc trước cả `config('trustedproxy.proxies')`. Nhưng callback này chạy trong
         * `afterResolving(HttpKernel::class)` — tức lúc Kernel được dựng, TRƯỚC khi
         * `LoadEnvironmentVariables` và `LoadConfiguration` chạy trong `$kernel->bootstrap()` —
         * nên `env()` và `config()` ở đây còn rỗng và một giá trị lấy từ biến môi trường sẽ luôn
         * là `null`.
         *
         * `TrustProxies` vốn đã nằm trong danh sách middleware toàn cục mặc định của Laravel 13
         * (`Middleware::getGlobalMiddleware()`), và nó đọc `config('trustedproxy.proxies')` ở
         * thời điểm xử lý request. Nên nơi cấu hình đúng là `config/trustedproxy.php` — đọc
         * docblock ở đó trước khi đổi bất cứ thứ gì, SPEC §10.3 phụ thuộc vào nó.
         */

        // SPEC §10.2 — toàn cục, ĐẦU danh sách, để phủ cả hai panel, nhóm `web`, trang lỗi và cả
        // phản hồi do middleware toàn cục khác dựng (400, 503 bảo trì, 413); lý do ở docblock.
        $middleware->prepend(SendSecurityHeaders::class);

        // SPEC §10 mục 1 (kế hoạch M8 Task 1) — toàn cục, CUỐI danh sách mặc định: `append()`
        // đặt nó SAU `TrustProxies`, bắt buộc vì `$request->secure()` chỉ đọc đúng
        // `X-Forwarded-Proto` sau khi proxy đã được xác nhận là đáng tin — lý do đầy đủ ở
        // docblock của middleware.
        $middleware->append(EnforceHttps::class);

        // R2 (kế hoạch M8 Task 2, vòng sửa 1) — nhóm `web`, SAU `StartSession`: phiên nhân sự có
        // trước lần "Đặt lại 2FA" gần nhất bị đăng xuất. Nhóm này phủ request cập nhật Livewire và
        // các route ngoài panel; route trang của panel `admin` (không dùng nhóm `web`) đăng ký
        // riêng ở `AdminPanelProvider`. Lý do tồn tại: docblock của middleware.
        $middleware->web(append: [RejectStaffSessionsFromBeforeReset::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
