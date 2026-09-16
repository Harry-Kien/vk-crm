<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel nội bộ (/admin), guard `web`, màu xám trung tính theo SPEC §3.
 *
 * **Nhận diện thương hiệu ở đây là có chủ đích và có giới hạn.** SPEC §3 quy định panel nội bộ
 * dùng màu xám trung tính, và điều đó vẫn đúng: đây là màn hình nhân sự nhìn tám tiếng mỗi ngày,
 * dày đặc bảng và số, nên màu chủ đạo phải lùi lại để trạng thái hồ sơ (vàng/đỏ quá hạn, mức xung
 * đột) là thứ duy nhất bật lên. Thương hiệu được mang vào bằng những thứ KHÔNG tranh chỗ với dữ
 * liệu: logo, bộ chữ Be Vietnam Pro và favicon lấy đúng từ luatvukhang.com, tên pháp lý đầy đủ
 * của văn phòng ở trang đăng nhập. Màu đỏ thương hiệu chỉ dùng cho `danger` — nơi nó vốn đã phải
 * là màu cảnh báo.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->domains(array_filter([config('vkcrm.admin_domain')]))
            ->authGuard('web')
            ->authPasswordBroker('users')
            ->login()
            ->brandName(__('panels.admin.brand'))
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('2.1rem')
            ->favicon(asset('brand/favicon.svg'))
            ->font(config('vkcrm.brand.font'))
            ->colors([
                'primary' => Color::Slate,
                'gray' => Color::Zinc,
                'danger' => Color::hex(config('vkcrm.brand.colors.red')),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
