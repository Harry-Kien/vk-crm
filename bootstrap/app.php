<?php

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Http\Middleware\EndDisabledStaffSessions;
use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Http\Middleware\SendSecurityHeaders;
use App\Mcp\Servers\CrmServer;
use App\Support\Mcp\McpEndpoint;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;

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
        $middleware->web(append: [RejectStaffSessionsFromBeforeReset::class, EndDisabledStaffSessions::class]);

        // Lượt quét §10.9 trên cây đã gộp M11 (rà soát cuối làn v1, vòng sửa 1, C1): `EndDisabledStaffSessions`
        // phải chạy TRƯỚC `Authenticate` ở MỌI route có cả hai. Route của Passport khai `auth:web` trong
        // danh sách middleware của route, nên khi sắp theo độ ưu tiên `Authenticate` đứng trước phần
        // nối thêm của nhóm `web`: người bị vô hiệu hoá bấm "Đồng ý" (`POST /oauth/authorize`) qua được
        // `Authenticate`, rồi mới bị đăng xuất, và nhận 403 thay cho trang đăng nhập mà docblock của
        // middleware hứa. Đặt nó vào danh sách ưu tiên ngay trước `AuthenticatesRequests` (vẫn sau
        // `StartSession`, đứng trước trong cùng danh sách). Test: `SessionCutSpec109Test`.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EndDisabledStaffSessions::class);

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

        // M11 R8 (Task 8; rà soát Task 2, m2): `TokenGuard` của Passport gọi `report()` cho MỌI bearer
        // không dùng được (`getPsrRequestViaBearerToken()`), nên mỗi request `/mcp` mang token sai từng
        // ghi một dòng log lỗi kèm stack trace — một vòng lặp vô danh lấp đầy `storage/logs`. Token sai
        // là chuyện thường của một API công khai (client nhận 401 rồi làm mới), không phải lỗi của app.
        // Lỗi OAuth của luồng `/oauth/*` không bị ảnh hưởng: Passport đổi chúng sang
        // `Laravel\Passport\Exceptions\OAuthServerException` (một `HttpResponseException`, vốn không
        // được report). Cùng một bearer bị 401 lặp lại từ một IP thì bị chặn ở
        // `ThrottleMcpAuthenticationFailures` (chỉ bearer đó, không chặn bearer khác cùng IP).
        $exceptions->dontReport(LeagueOAuthServerException::class);

        // M14 (kế hoạch R3, R9): kho tài liệu sập hay cấu hình hỏng giữa một request → trang 503
        // tiếng Việt `errors/storage-unavailable` (cả panel admin lẫn cổng khách) kèm
        // `Retry-After: 120`; không bao giờ trang 500, không chi tiết kỹ thuật. Lỗi vẫn được báo cáo
        // vào log như mọi ngoại lệ (render không thay report).
        $exceptions->render(function (DocumentStorageUnavailable|DocumentStorageMisconfigured $exception, Request $request) {
            $headers = ['Retry-After' => '120'];
            // Rà soát cuối vòng sửa 1 (I7): cấu hình hỏng không tự hết — câu riêng, không "thử lại sau ít phút".
            $misconfigured = $exception instanceof DocumentStorageMisconfigured;
            $message = $misconfigured
                ? __('storage.unavailable_page.misconfigured_'.(auth('web')->check() ? 'staff' : 'client'))
                : __('storage.exceptions.unavailable');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503, $headers)
                : response()->view('errors.storage-unavailable', ['message' => $message], 503, $headers);
        });
    })->create();
