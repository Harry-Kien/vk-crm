<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Auth\StaffAppAuthentication;
use App\Filament\Admin\Pages\Auth\EditProfile;
use App\Filament\Admin\Pages\Auth\Login;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Http\Middleware\RestrictAdminIpAllowlist;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
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
            /*
             * M8 Task 3 (SPEC §10.3): trang đăng nhập riêng — 5 lần / 15 phút theo email VÀ IP, cho
             * cả bước mật khẩu lẫn bước mã. `->login()` trần của Filament chỉ giới hạn 5 lần / 60
             * giây theo IP. Xem docblock `App\Filament\Admin\Pages\Auth\Login`.
             */
            ->login(Login::class)
            /*
             * Task 20 (SPEC §10.6, phát hiện "nhân sự không có chỗ nào tự đổi mật khẩu"): trang hồ
             * sơ cá nhân, kế thừa gần như nguyên bản của Filament — tên, mật khẩu mới + xác nhận,
             * và một ô "mật khẩu hiện tại" chỉ hiện/bắt buộc khi mật khẩu hoặc email đổi
             * (`EditProfile::getCurrentPasswordFormComponent()` — `->currentPassword()` tự xác
             * thực bằng `Hash::check()` trên guard hiện hành).
             *
             * Fix round 1 (ruling "the staff profile page"): `App\Filament\Admin\Pages\Auth\EditProfile`
             * — MỘT lớp con nhỏ, không còn dùng thẳng `Filament\Auth\Pages\EditProfile` — khoá ô
             * email thành chỉ đọc. Đọc docblock của lớp đó cho lý do đầy đủ (bản mặc định của
             * Filament cho nhân sự tự đổi email đăng nhập mà không xác minh lại).
             *
             * M8 Task 2 (R2, §10 mục 7): `->multiFactorAuthentication()` bên dưới bật khối 2FA
             * trên đúng trang này (`EditProfile::getMultiFactorAuthenticationContentComponent()`
             * của lớp cha giờ vẽ nó). Không có nút tắt: `App\Filament\Admin\Auth\StaffAppAuthentication`
             * bỏ `DisableAppAuthenticationAction` — đọc docblock lớp đó cho lý do đầy đủ.
             */
            ->profile(EditProfile::class)
            /*
             * R2 — 2FA ứng dụng (TOTP), BẮT BUỘC, không tắt được. `recoverable()` bật mã khôi
             * phục (in ra đúng một lần lúc cài, hành vi mặc định của Filament — không viết lại).
             * `isRequired: true` gắn `EnsureMultiFactorAuthenticationIsEnabled` vào middleware
             * CỦA TỪNG TRANG (`Filament\Pages\Concerns\HasRoutes::getRouteMiddleware()`), nên một
             * người chưa cài (nhân sự mới, hay vừa bị "Đặt lại 2FA" — {@see
             * \App\Actions\User\ResetStaffTwoFactor}) luôn bị chuyển sang trang cài đặt bắt buộc
             * ở LẦN TẢI TRANG ĐẦY ĐỦ kế tiếp, trên MỌI trang của panel này.
             *
             * `->brandName()` = tên thương hiệu ngắn (không phải tên pháp lý đầy đủ) — chuỗi hiện
             * trong app xác thực (Google Authenticator, Authy…) cạnh tên tài khoản
             * (`getAppAuthenticationHolderName()` = email), nên một chuỗi dài như
             * `panels.admin.brand` ("Vũ Khang · Hệ thống nội bộ") sẽ bị hầu hết app xác thực cắt
             * bớt trên một dòng hẹp.
             */
            ->multiFactorAuthentication(
                [StaffAppAuthentication::make()->recoverable()->brandName(config('vkcrm.brand.short_name'))],
                isRequired: true,
            )
            ->brandName(__('panels.admin.brand'))
            ->brandLogo(fn () => view('brand.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(asset('brand/vk-mark-64.png'))
            ->font(config('vkcrm.brand.font'))
            // Không gọi ui-avatars.com (SPEC §10.2, khảo sát CSP) — lý do ở docblock của lớp.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
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
            // Đầu danh sách middleware của panel — tức THỨ HAI/BA trong đường ống, vì
            // `Panel::getMiddleware()` tự chèn `panel:{id}` lên trước để dựng panel hiện
            // hành. `isPersistent: true` để cả hai theo sang cả request cập nhật Livewire, nơi
            // chúng đứng trước `Filament\Http\Middleware\Authenticate`. `RestrictAdminIpAllowlist`
            // (R7) đứng TRƯỚC `AnswerDeniedPanelRequestsWithNotFound`: một IP ngoài danh sách
            // không cần đi xa hơn cổng mạng để nhận 404. Phủ đến đâu và cố ý KHÔNG phủ đến đâu
            // (từ chối bên trong vòng đời component vẫn là 403): xem docblock của từng middleware.
            ->middleware([RestrictAdminIpAllowlist::class, AnswerDeniedPanelRequestsWithNotFound::class], isPersistent: true)
            /*
             * R2, khoảng trống "sẽ cắn" (brief Task 2, mục 4d): `EnsureMultiFactorAuthenticationIsEnabled`
             * (đăng ký ở TỪNG trang qua `isRequired: true` phía trên) chỉ chặn LẦN TẢI TRANG ĐẦY
             * ĐỦ — nó KHÔNG phải middleware BỀN của Livewire theo mặc định của Filament
             * (`Filament\FilamentServiceProvider::packageBooted()` liệt kê `Authenticate`,
             * `AuthenticateSession`, … nhưng KHÔNG có middleware 2FA này). Đã đọc lại
             * `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::applyPersistentMiddleware()`
             * để xác nhận: một request cập nhật Livewire dựng lại route GỐC (nơi component được
             * mount lần đầu, ví dụ `/admin/matters/5/edit`) từ `snapshot.memo.path`, gom middleware
             * CỦA CHÍNH route đó, rồi LỌC xuống còn những middleware có mặt trong danh sách BỀN
             * TOÀN CỤC — middleware của trang (đã có `EnsureMultiFactorAuthenticationIsEnabled` từ
             * `isRequired: true`) bị lọc mất vì danh sách toàn cục không có tên nó.
             *
             * Hệ quả nếu không có dòng dưới đây, và đây KHÔNG phải chuyện lý thuyết: admin bị "Đặt
             * lại 2FA" (secret bị xoá) trong khi một tab trình duyệt của họ đang mở sẵn một trang
             * admin (snapshot còn hiệu lực) — trang đó vẫn cập nhật được qua Livewire bình thường,
             * bỏ qua đúng cánh cổng `isRequired: true` vừa nói ở trên.
             *
             * `persistentMiddleware()` (KHÔNG phải `middleware(..., isPersistent: true)`) — chỉ
             * ghi vào danh sách BỀN toàn cục của Livewire (`Livewire::addPersistentMiddleware()`),
             * KHÔNG thêm vào `$this->middleware` của panel. Thêm vào `$this->middleware` (chạy ở
             * ĐẦU đường ống, trước `Authenticate`) sẽ vỡ: `Filament::auth()->user()` là `null` cho
             * khách vãng lai, và `MultiFactorChallenge::make()->hasEnabledProviders(null)` ném lỗi
             * thay vì cho qua trang đăng nhập. Route "cài đặt bắt buộc" tự nó AN TOÀN với cách
             * đăng ký này: nó được đăng ký RIÊNG (`vendor/filament/filament/routes/web.php`, không
             * qua `HasRoutes::routes()`), nên middleware của TỪNG TRANG không áp vào route đó —
             * không có vòng lặp chuyển hướng về chính nó. Có test hành vi thật (request cập nhật
             * Livewire thật, không phải `Livewire::test()`) ở
             * `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php`, cùng khuôn
             * `AdminIpAllowlistTest`.
             */
            ->persistentMiddleware([EnsureMultiFactorAuthenticationIsEnabled::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // R2, vòng sửa 1: route trang panel không dùng nhóm `web`, nên đăng ký riêng ở đây —
                // SAU StartSession, TRƯỚC Authenticate (đăng xuất trước khi cổng 2FA kịp chạy).
                RejectStaffSessionsFromBeforeReset::class,
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
