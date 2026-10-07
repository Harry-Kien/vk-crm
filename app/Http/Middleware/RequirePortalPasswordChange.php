<?php

namespace App\Http\Middleware;

use App\Filament\Portal\Pages\Auth\ChangePassword;
use App\Models\ClientUser;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §8.1: "Lần đầu đăng nhập bắt buộc đổi mật khẩu."
 *
 * Một nút bấm dẫn tới trang đổi mật khẩu không cài được câu đó — nó chỉ mời, và ai gõ thẳng một
 * URL khác của cổng là đi vòng qua. Nên cổng đứng ở tầng middleware: khi `must_change_password`
 * còn bật, MỌI đường của panel `portal` đều dẫn về trang đổi mật khẩu, trừ đúng hai chỗ:
 *
 *  - chính trang đổi mật khẩu (không trừ thì thành vòng lặp chuyển hướng);
 *  - đường đăng xuất (một người chưa muốn đổi vẫn phải ra được — khoá luôn cả lối ra là nhốt
 *    người dùng trong một màn hình duy nhất).
 *
 * Request cập nhật Livewire của chính trang đổi mật khẩu KHÔNG cần một miễn trừ thứ ba, và đây
 * là chỗ dễ cài sai nên phải nói rõ: middleware này đăng ký `isPersistent: true`, và trên đường
 * ống bền nó chạy với một request GIẢ mang **đường dẫn của trang gốc**
 * (`PersistentMiddleware::makeFakeRequest()` thay `REQUEST_URI` bằng path trong snapshot), chứ
 * không mang đường dẫn của endpoint cập nhật. Vì vậy so sánh theo đường dẫn ở đây đúng cho cả
 * hai loại request bằng cùng một luật: request cập nhật của trang đổi mật khẩu mang đường dẫn
 * trang đổi mật khẩu và đi qua, còn request cập nhật của một trang khác mang đường dẫn trang đó
 * và bị chặn — có test cho vế thứ hai ở `tests/Feature/Portal/LoginTest.php`.
 *
 * # Trang khách đang định mở sống qua bước đổi mật khẩu (M12 Task 6, R9)
 *
 * Khách chạm một thông báo đẩy khi phiên đã hết: `/portal/ho-so/{id}` → đăng nhập → mã OTP →
 * `LoginResponse` TIÊU `url.intended` để về đúng trang hồ sơ → middleware này chặn trang đó (văn
 * phòng vừa đặt lại mật khẩu). Trước bản sửa, đổi mật khẩu xong khách về trang chủ cổng và mất
 * trang mình đang mở. Nay mỗi lần chặn một request `GET` — mở trang, hoặc request cập nhật Livewire
 * của một trang đã mở, mà request giả của đường ống bền mang phương thức và đường dẫn của TRANG —
 * URL đó được ghi vào {@see self::INTENDED_URL_KEY}; {@see ChangePassword} đưa khách về đó sau khi
 * đổi xong. Không ghi request khác `GET`: trên trang đổi mật khẩu, `register.js` vẫn gửi lượt kiểm
 * `POST …/push/subscriptions` và nó cũng bị chặn ở đây — ghi nó thì đổi mật khẩu xong khách bị đưa
 * tới một route chỉ nhận `POST`.
 *
 * Khoá riêng, không phải `url.intended`: hai panel chung một phiên, và `url.intended` có thể đang
 * giữ một URL `/admin` do panel kia ghi. Khoá này chỉ có một nơi ghi — chính middleware này, trên
 * route của panel `portal` — nên giá trị của nó luôn là một trang cổng khách mà chính khách này vừa
 * mở.
 */
class RequirePortalPasswordChange
{
    /** Trang cổng khách mà khách đang định mở khi bị chặn để đổi mật khẩu — {@see ChangePassword} đọc. */
    public const INTENDED_URL_KEY = 'portal.password_change.intended_url';

    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::guard('client')->user();

        if (! ($user instanceof ClientUser) || ! $user->must_change_password) {
            return $next($request);
        }

        $changePasswordUrl = ChangePassword::getUrl(panel: 'portal');

        if (in_array($request->path(), $this->allowedPaths($changePasswordUrl), true)) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            // Request giả của đường ống bền là `duplicate()` của request thật nên mang theo phiên của
            // nó — đo bằng ca cập nhật Livewire của `tests/Feature/Portal/DeepLinkSignInTest.php`.
            $request->session()->put(self::INTENDED_URL_KEY, $request->fullUrl());
        }

        return redirect()->to($changePasswordUrl);
    }

    /**
     * @return array<int, string>
     */
    private function allowedPaths(string $changePasswordUrl): array
    {
        $portal = Filament::getPanel('portal');

        return array_map(
            $this->pathOf(...),
            array_filter([$changePasswordUrl, $portal->getLogoutUrl()]),
        );
    }

    /**
     * `Request::path()` trả đường dẫn KHÔNG có dấu gạch chéo đầu ("portal/change-password"), nên
     * mọi URL đem so sánh phải được rút về cùng hình dạng đó.
     */
    private function pathOf(string $url): string
    {
        return trim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
