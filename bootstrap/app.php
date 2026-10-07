<?php

use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Http\Middleware\SendSecurityHeaders;
use App\Mcp\Servers\CrmServer;
use App\Support\Mcp\McpEndpoint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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

        // M11 R7 — một request `/mcp` chưa xác thực KHÔNG BAO GIỜ được chuyển hướng: nó nhận 401
        // JSON kèm `WWW-Authenticate` (render ở `withExceptions` bên dưới). Mặc định của Laravel
        // (`ApplicationBuilder::withMiddleware()`) là `route('login')`, và `Authenticate` gọi nó
        // NGAY lúc ném lỗi với mọi request không `expectsJson()`, TRƯỚC khi exception handler kịp
        // chọn JSON. App không có route `login`, nên một client MCP gửi token sai mà không kèm
        // `Accept: application/json` nhận lỗi 500 thay cho 401.
        //
        // M11 Task 4 — route của Passport (màn hình đồng ý `/oauth/authorize`, "Đồng ý", "Từ chối")
        // đưa khách vãng lai về trang đăng nhập nhân sự của panel `/admin` (Filament không có route
        // `login` [PL:79]); `redirect()->guest()` cất URL `/oauth/authorize` đầy đủ tham số làm URL
        // "intended", và trang đăng nhập quay lại đó sau bước mã 2FA (AuthorizeScreenTest). Host của
        // trang đăng nhập: `McpEndpoint::staffLoginUrl()`. Cùng lời gọi này đặt chỗ chuyển hướng cho cả
        // `AuthenticateSession` (`config/passport.php`). Các đường khác giữ nguyên mặc định.
        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            $request->is(CrmServer::PATH) => null,
            $request->routeIs('passport.*') => McpEndpoint::staffLoginUrl(),
            default => route('login'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // `/mcp` (M11 R7): luôn JSON, kể cả request không có `Accept: application/json`. Không có
        // vế này, một client MCP chưa xác thực mà không gửi `Accept` bị chuyển hướng tới route
        // `login` (không tồn tại, nên thành lỗi 500) thay vì nhận 401 kèm `WWW-Authenticate`. Claude
        // chỉ hiện nút Connect khi nhận đúng 401 [DC:628].
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is(CrmServer::PATH) || $request->expectsJson(),
        );
    })->create();
