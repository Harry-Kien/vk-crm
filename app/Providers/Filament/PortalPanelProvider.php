<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            ->domains(array_filter([config('vkcrm.portal_domain')]))
            ->authGuard('client')
            ->authPasswordBroker('client_users')
            ->login()
            ->brandName(__('panels.portal.brand'))
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('2.6rem')
            ->favicon(asset('brand/vk-mark-64.png'))
            ->font(config('vkcrm.brand.font'))
            ->colors([
                // Dải viết sẵn, không phải Color::hex() — xem lý do ở config/vkcrm.php.
                'primary' => config('vkcrm.brand.primary_ramp'),
                'danger' => Color::hex(config('vkcrm.brand.colors.red')),
            ])
            ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\Filament\Portal\Resources')
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\Filament\Portal\Pages')
            ->pages([
                Dashboard::class,
            ])
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
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('brand.theme'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => view('brand.login-tagline'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('brand.login-footer'));
    }
}
