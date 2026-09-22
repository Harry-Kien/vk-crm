<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §10.9: khi `client_users.is_active = false`, mọi phiên đang mở phải bị vô hiệu **ngay ở
 * request kế tiếp**, không đợi hết hạn phiên.
 *
 * # Món nợ M4 giao lại, và chỗ nó được trả
 *
 * Hôm qua điều kiện này đã được giữ — nhưng bằng `ClientUser::canAccessPanel()` trả `false`, tức
 * bằng `abort(403)` của `Filament\Http\Middleware\Authenticate`, mà `AnswerDeniedPanelRequestsWithNotFound`
 * đổi thành **404**. Đúng về an toàn, sai về con người: rà soát M4 ghi thẳng rằng "câu trả lời
 * nhân đạo cho họ là màn hình đăng nhập", và giao việc sửa cho M5.
 *
 * Nên middleware này đứng **trước** `Authenticate` trong `authMiddleware` của panel `portal`. Nó
 * gặp tài khoản bị vô hiệu trước khi Filament kịp từ chối, và trả lời bằng đúng thứ một người
 * đang không vào được cần thấy: trang đăng nhập, kèm một câu nói phải làm gì tiếp.
 *
 * Nó KHÔNG thay `canAccessPanel()` — cổng đó vẫn còn và vẫn từ chối. Hai lớp, và lớp này chỉ
 * đến trước để đổi hình dạng câu trả lời, chứ không phải để trở thành lớp duy nhất.
 *
 * # Câu nói ra cố ý không nói vì sao
 *
 * `portal.inactive` chỉ nói tài khoản hiện chưa đăng nhập được và cho số điện thoại văn phòng.
 * Lý do một tài khoản bị khoá có thể là hồ sơ đã kết thúc, có thể là một việc nội bộ, có thể là
 * chuyện chỉ nên nói riêng — đó là một cuộc điện thoại, không phải một dòng chữ trên màn hình
 * đăng nhập. Nó cũng giữ đúng SPEC §10.10: câu này không cho biết gì thêm về bản ghi nào cả.
 *
 * # Ba việc, đúng thứ tự
 *
 * `logout()` bỏ người dùng khỏi guard; `invalidate()` vứt cả phiên (nếu không, dữ liệu phiên cũ
 * — kể cả mã đăng nhập một lần đã băm — còn nguyên); `regenerateToken()` cấp token CSRF mới cho
 * phiên mới, không có nó thì form đăng nhập ngay sau đó nhận 419. Thông báo được đẩy vào phiên
 * **sau** `invalidate()`, vì `invalidate()` xoá sạch những gì đã đẩy trước đó.
 *
 * # Vì sao KHÔNG có `trashed()` ở đây
 *
 * Câu hỏi "tài khoản này đã bị xoá mềm chưa" cố ý không được hỏi, và nó được nói ra vì người đọc
 * tiếp theo sẽ đi tìm nó: `Auth::guard('client')->user()` tra qua `EloquentUserProvider::newModelQuery()`,
 * thứ dựng truy vấn từ `$model->newQuery()` và vì vậy MANG THEO `SoftDeletingScope`. Một
 * `ClientUser` đã xoá mềm không bao giờ được trả về, nên `$user` ở đây đã là `null` và request
 * đi tiếp tới `Authenticate` như một người chưa đăng nhập. Thêm một `trashed()` ở đây là thêm
 * một điều kiện không bao giờ đúng — và một điều kiện không bao giờ đúng trông y hệt một lớp
 * bảo vệ, nên nó tệ hơn là không có.
 *
 * # Phủ cả request cập nhật Livewire
 *
 * Đăng ký kèm `isPersistent: true` ở `PortalPanelProvider`. Toàn bộ cổng khách hàng là Livewire,
 * nên một người đang mở sẵn một trang và chỉ bấm quanh trong đó sẽ không tải trang đầy đủ nào
 * nữa — không phủ đường cập nhật thì "ngay ở request kế tiếp" của SPEC §10.9 là một lời hứa
 * suông. Chuyển hướng ĐI QUA được đường đó: `Livewire\Drawer\Utils::applyMiddleware()` kiểm tra
 * riêng `RedirectResponse` và `abort($response)` để nó thoát ra ngoài đường ống giả
 * (vendor/livewire/livewire/src/Drawer/Utils.php:192). Đây là điều khác với `abort(403)` phát
 * sinh BÊN TRONG vòng đời component, thứ `AnswerDeniedPanelRequestsWithNotFound` đã ghi là
 * không với tới được.
 */
class EnsurePortalAccountIsActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Guard `client` viết thẳng chứ không hỏi panel hiện hành: middleware này chỉ được đăng
        // ký trên panel `portal`, và trên đường ống bền của Livewire thì càng không nên phụ
        // thuộc vào thứ tự dựng panel.
        $user = Auth::guard('client')->user();

        if (! ($user instanceof ClientUser) || $user->is_active) {
            return $next($request);
        }

        Auth::guard('client')->logout();

        // Trên đường ống bền của Livewire, `$request` là một request GIẢ dựng từ request thật
        // (`PersistentMiddleware::makeFakeRequest()`), nên lấy kho phiên của chính nó nếu có và
        // rơi về kho phiên đã được `StartSession` của request thật dựng nếu không.
        $session = $request->hasSession() ? $request->session() : session();
        $session->invalidate();
        $session->regenerateToken();

        Notification::make()
            ->title(__('portal.inactive', ['phone' => config('vkcrm.brand.hotline')]))
            ->danger()
            ->persistent()
            ->send();

        return redirect()->to(Filament::getPanel('portal')->getLoginUrl());
    }
}
