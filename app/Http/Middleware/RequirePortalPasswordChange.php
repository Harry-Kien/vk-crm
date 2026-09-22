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
 */
class RequirePortalPasswordChange
{
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
