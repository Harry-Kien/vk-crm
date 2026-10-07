<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Pwa\PushSubscriptionController;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * M8 R2 (2FA bắt buộc cho nhân sự) cho route `/admin/…` mà Filament KHÔNG tự phủ: route đăng ký qua
 * `->authenticatedRoutes()` của panel — hôm nay `POST`/`DELETE /admin/push/subscriptions`
 * ({@see PushSubscriptionController}, M12 Task 5).
 *
 * Cổng 2FA của Filament (`isRequired: true`) gắn {@see EnsureMultiFactorAuthenticationIsEnabled} vào
 * middleware của TỪNG TRANG (`Filament\Pages\Concerns\HasRoutes::getRouteMiddleware()`), không vào
 * nhóm `authMiddleware` — nên thiếu lớp này, một nhân sự chưa cài 2FA (mới, hoặc vừa bị "Đặt lại
 * 2FA") vẫn đăng ký được thiết bị nhận push của nhân sự.
 *
 * Vì sao không gắn thẳng {@see EnsureMultiFactorAuthenticationIsEnabled}: nó trả lời bằng
 * `redirect()->guest()`, mà với một request không phải GET (hoặc đòi JSON) thì `guest()` ghi
 * `url()->previous()` — tức Referer — vào `url.intended`. `register.js` gửi lượt kiểm `sync=1` từ
 * mọi trang đã đăng nhập, kể cả chính trang "cài 2FA bắt buộc": đường dẫn sâu mà người đó đang trên
 * đường tới (một thông báo, một liên kết trong thư) bị ghi đè bằng trang cài đặt. Ở đây từ chối
 * KHÔNG đụng phiên: `abort(403)`, mà `AnswerDeniedPanelRequestsWithNotFound` (middleware của panel)
 * đổi thành 404 như mọi lời từ chối khác của panel.
 *
 * Đứng SAU `Filament\Http\Middleware\Authenticate` (thứ tự nhóm route: middleware panel →
 * `authMiddleware` → middleware của route), nên luôn có người dùng để hỏi.
 */
class RefuseStaffWithoutTwoFactor
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        abort_unless($user !== null && MultiFactorChallenge::make()->hasEnabledProviders($user), 403);

        return $next($request);
    }
}
