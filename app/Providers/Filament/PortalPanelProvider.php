<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Portal\Auth\PortalEmailAuthentication;
use App\Filament\Portal\Pages\Auth\Login;
use App\Filament\Portal\Pages\PushDevices;
use App\Http\Controllers\Pwa\PushSubscriptionController;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Http\Middleware\EnsurePortalAccountIsActive;
use App\Http\Middleware\RequirePortalPasswordChange;
use App\Notifications\Client\SendLoginCode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Portal khách hàng (/portal), guard `client`, màu thương hiệu theo SPEC §3.
 *
 * Đây là màn hình khách hàng của văn phòng nhìn thấy, nên nó phải trông liền một mạch với
 * luatvukhang.com: cùng màu xanh navy `--navy`, cùng bộ chữ Be Vietnam Pro, cùng logo, cùng câu
 * định vị. Một khách hàng đang lo vụ việc của mình mà mở ra thấy một phần mềm lạ dán tên văn
 * phòng sẽ ngần ngại đăng nhập — sự liền mạch ở đây là một phần của việc họ tin tưởng mà dùng.
 */
class PortalPanelProvider extends PanelProvider
{
    /**
     * SPEC §10.9 đòi tài khoản khách bị vô hiệu thấy MÀN HÌNH ĐĂNG NHẬP, nên
     * `EnsurePortalAccountIsActive` phải chạy TRƯỚC `Filament\Http\Middleware\Authenticate` —
     * chạy sau thì `canAccessPanel()` đã kịp `abort(403)` và `AnswerDeniedPanelRequestsWithNotFound`
     * đổi nó thành 404, đúng món nợ M4 giao lại.
     *
     * **Viết nó trước `Authenticate::class` trong mảng `->authMiddleware([...])` KHÔNG đủ**, và
     * đây là điều đã đo chứ không phải phỏng đoán — lần cài đầu làm đúng như vậy và tài khoản bị
     * vô hiệu vẫn nhận 404. `Router::gatherRouteMiddleware()` sắp lại danh sách theo bảng ưu
     * tiên của framework, và `SortedMiddleware::middlewareNames()` xét cả lớp cha lẫn INTERFACE,
     * nên `Filament\Http\Middleware\Authenticate` khớp mục
     * `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` trong bảng và luôn được nhấc
     * lên trước mọi middleware không có tên trong bảng — kể cả middleware đứng trước nó trong
     * mảng.
     *
     * Mốc neo vì thế là **interface** chứ không phải lớp `Illuminate\Auth\Middleware\Authenticate`:
     * bảng mặc định của Laravel 13 chứa đúng cái interface, còn
     * `Kernel::addToMiddlewarePriorityRelative()` tìm bằng so khớp chuỗi — neo nhầm vào tên lớp
     * thì nó lặng lẽ nối vào CUỐI bảng, tức không đổi gì cả (đã đo). Kiểm lại bằng
     * `bin/dev artisan route:list --path=portal -v`, và có test hành vi ở
     * `tests/Feature/Portal/LoginTest.php`.
     *
     * Đặt ở đây chứ không ở `bootstrap/app.php`: bảng ưu tiên này tồn tại chỉ vì một middleware
     * của panel `portal`, nên nó thuộc về provider của chính panel đó. `RequirePortalPasswordChange`
     * cố ý KHÔNG có mặt — nó cần đứng SAU `Authenticate` để có người dùng đã xác thực mà hỏi
     * `must_change_password`, và "không có tên trong bảng" chính là chỗ đúng của nó.
     */
    public function boot(): void
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->addToMiddlewarePriorityBefore(
                AuthenticatesRequests::class,
                EnsurePortalAccountIsActive::class,
            );
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            ->domains(array_filter([config('vkcrm.portal_domain')]))
            ->authGuard('client')
            ->authPasswordBroker('client_users')
            ->login(Login::class)
            /*
             * SPEC §8.1: mã 6 số qua email, hiệu lực **5 phút**. Mặc định của Filament là 4 —
             * `EmailAuthentication::$codeExpiryMinutes` — nên con số phải được nói ra ở đây.
             *
             * `setUpRequiredAction: null` là cố ý: trang "bắt buộc cài đặt xác thực hai bước"
             * chỉ có nghĩa khi người dùng CÓ THỂ chưa bật, mà `ClientUser::hasEmailAuthentication()`
             * trả `true` cứng. Không đăng ký nó là bớt một route không ai được phép cần tới.
             *
             * Panel này cũng KHÔNG đăng ký trang hồ sơ cá nhân (`->profile()`), vì đó là nơi
             * `DisableEmailAuthenticationAction` sống — tức là nơi duy nhất có một cái nút tắt
             * mã đăng nhập. SPEC §8.1 không cho khách tắt, nên cái nút ấy không được tồn tại.
             */
            ->multiFactorAuthentication(
                PortalEmailAuthentication::make()
                    ->codeExpiryMinutes(5)
                    ->codeNotification(SendLoginCode::class),
                setUpRequiredAction: null,
            )
            ->brandName(__('panels.portal.brand'))
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('brand/vk-mark-64.png'))
            ->font(config('vkcrm.brand.font'))
            // Không gọi ui-avatars.com (SPEC §10.2, khảo sát CSP) — lý do ở docblock của lớp.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                // Dải viết sẵn, không phải Color::hex() — xem lý do ở config/vkcrm.php.
                'primary' => config('vkcrm.brand.primary_ramp'),
                'danger' => Color::hex(config('vkcrm.brand.colors.red')),
            ])
            ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\Filament\Portal\Resources')
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\Filament\Portal\Pages')
            /*
             * KHÔNG đăng ký `Filament\Pages\Dashboard`.
             *
             * SPEC §8.2 nói màn hình khách gặp sau khi đăng nhập là DANH SÁCH HỒ SƠ, và tài liệu
             * bộ công cụ §4 cấm thẳng một trạng thái trống không kèm hướng dẫn — một dashboard
             * không widget nào chính là thứ đó. `App\Filament\Portal\Pages\MyMatters` nhận
             * đường dẫn gốc của panel (`getRoutePath()` trả `/`), nên nó là `/portal`, tức đúng
             * nơi `Filament\Auth\Pages\Login` chuyển hướng tới (`Filament::getUrl()`).
             *
             * Dòng `Dashboard::class` cũ KHÔNG chỉ thừa: nó đăng ký route SAU các trang tự dò
             * được — `discoverPages()` nối chúng vào `$panel->pages` trước — nên
             * `RouteCollection::addToCollections()` ghi đè theo khoá `[method][domain.uri]` và
             * route của `MyMatters` biến mất khỏi bảng. Hệ quả đo được: mục điều hướng của
             * `MyMatters` vẫn được dựng, `getNavigationUrl()` gọi `route()` lên một tên route
             * không tồn tại, và MỌI trang cổng đã xác thực vỡ khi vẽ thanh bên.
             */
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Portal/Widgets'), for: 'App\Filament\Portal\Widgets')
            ->widgets([])
            // Đầu danh sách middleware của panel — tức THỨ HAI trong đường ống, vì
            // `Panel::getMiddleware()` tự chèn `panel:{id}` lên trước để dựng panel hiện
            // hành. `isPersistent: true` để nó theo sang cả request cập nhật Livewire, nơi
            // nó đứng trước `Filament\Http\Middleware\Authenticate`. Phủ đến đâu và cố ý
            // KHÔNG phủ đến đâu (từ chối bên trong vòng đời component vẫn là 403): xem
            // docblock của middleware.
            ->middleware([AnswerDeniedPanelRequestsWithNotFound::class], isPersistent: true)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            /*
             * THỨ TỰ CHẠY THẬT CỦA BA MIDDLEWARE NÀY **KHÔNG** DO MẢNG NÀY QUYẾT ĐỊNH.
             *
             * `Router::gatherRouteMiddleware()` sắp lại theo bảng ưu tiên của framework, nên
             * `Filament\Http\Middleware\Authenticate` luôn bị nhấc lên trước mọi middleware
             * không có tên trong bảng đó. SPEC §10.9 đòi `EnsurePortalAccountIsActive` chạy
             * TRƯỚC nó, và điều đó được cài ở `boot()` phía trên — đọc docblock ở đó trước khi
             * đổi bất cứ thứ gì ở đây. Kiểm bằng `bin/dev artisan route:list --path=portal -v`.
             *
             * `RequirePortalPasswordChange` (SPEC §8.1) thì phải đứng SAU `Authenticate` vì nó
             * cần một người dùng đã xác thực để hỏi `must_change_password`; không có tên trong
             * bảng ưu tiên chính là chỗ đúng của nó.
             *
             * Cả ba `isPersistent: true` vì toàn bộ cổng khách hàng là Livewire: một người đang
             * mở sẵn một trang chỉ sinh request cập nhật, nên middleware không bền sẽ không chạy
             * lần nào nữa và hai điều kiện trên thành lời hứa suông.
             */
            ->authMiddleware([
                EnsurePortalAccountIsActive::class,
                Authenticate::class,
                RequirePortalPasswordChange::class,
            ], isPersistent: true)
            /*
             * M12 R8 — `POST`/`DELETE /portal/push/subscriptions` (đăng ký thiết bị nhận thông báo
             * đẩy), sau ba middleware trên: khách bị vô hiệu dừng ở `EnsurePortalAccountIsActive`,
             * khách chưa đổi mật khẩu lần đầu bị chuyển sang trang đổi mật khẩu. Không có trang hồ sơ
             * cá nhân (xem `->multiFactorAuthentication()`), nên "Thông báo trên điện thoại" là một
             * trang riêng mở từ user menu (ẩn khi máy chủ chưa có khoá VAPID).
             */
            ->authenticatedRoutes(PushSubscriptionController::routes())
            ->userMenuItems(['push-devices' => PushDevices::userMenuItem()])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('brand.theme'))
            // M12 R2 — manifest, theme-color, apple-touch-icon: hook THỨ HAI cùng tên, không gộp vào
            // `brand.theme`. Nội dung và lý do: `resources/views/pwa/head.blade.php`.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('pwa.head'))
            // M12 R8 — dải mời "Bật thông báo trên máy này?" (ẩn; `register.js` quyết khi nào hiện).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('pwa.push-invite'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => view('brand.login-tagline'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('brand.login-footer'));
    }
}
