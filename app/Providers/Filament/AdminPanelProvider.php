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
 * liệu: logo thật của văn phòng, bộ chữ Be Vietnam Pro lấy đúng từ luatvukhang.com, tên pháp lý
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
            /*
             * Task 20 (SPEC §10.6, phát hiện "nhân sự không có chỗ nào tự đổi mật khẩu"):
             * trang hồ sơ cá nhân MẶC ĐỊNH của Filament (`Filament\Auth\Pages\EditProfile`), không
             * ghi đè. Nó đã có sẵn đúng thứ cần: tên, email, mật khẩu mới + xác nhận, và một ô
             * "mật khẩu hiện tại" chỉ hiện/bắt buộc khi mật khẩu hoặc email đổi
             * (`EditProfile::getCurrentPasswordFormComponent()` — `->currentPassword()` tự xác
             * thực bằng `Hash::check()` trên guard hiện hành).
             *
             * KHÔNG bật `->multiFactorAuthentication()` cho panel này (khác `PortalPanelProvider`),
             * nên `EditProfile::getMultiFactorAuthenticationContentComponent()` trả về `null` —
             * trang không vẽ khối 2FA nào, và vì vậy không có nút tắt 2FA nào để lo: M8 sẽ bật
             * 2FA bắt buộc cho nhân sự, và quyết định của chủ nhiệm là không dựng gì giả định 2FA
             * tắt được trước khi M8 tới.
             */
            ->profile()
            ->brandName(__('panels.admin.brand'))
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('brand/vk-mark-64.png'))
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
            /*
             * Thông báo trong hệ thống, CHỈ trên panel nội bộ. Không bao giờ bật cho cổng khách:
             * khay thông báo của Filament là một bề mặt của nhân sự, và một khách hàng không bao
             * giờ được cầm nó — kể cả khi hôm nay chưa có thông báo nào được gửi cho họ. Có test
             * khẳng định panel `portal` không bật thứ này.
             */
            ->databaseNotifications()
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
