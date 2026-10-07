<?php

namespace App\Listeners;

use App\Actions\Push\ForgetPushDevice;
use App\Http\Middleware\EnsurePortalAccountIsActive;
use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Auth\Http\Controllers\LogoutController;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * M12 R9 — đăng xuất trên máy này thì máy này thôi nhận thông báo đẩy của người vừa đăng xuất
 * (câu `push.devices.logout_note` trên trang "Thông báo trên điện thoại"). Đăng ký qua
 * auto-discovery (kiểu tham số của `handle`), cùng khuôn {@see StampStaffSessionEpoch}. KHÔNG
 * `ShouldQueue`: phải đọc phiên của request đang chạy, trước khi phiên bị xoá.
 *
 * Các đường đăng xuất, đều phát sự kiện TRƯỚC khi phiên bị xoá:
 *  - `Logout` — nút Đăng xuất của hai panel ({@see LogoutController}: `logout()` rồi
 *    `invalidate()`); cắt phiên SPEC §10.9 ({@see EnsurePortalAccountIsActive}, cả trên request
 *    cập nhật Livewire); "Đặt lại 2FA" ({@see RejectStaffSessionsFromBeforeReset}: `logout()`, KHÔNG
 *    huỷ phiên).
 *  - `CurrentDeviceLogout` — mật khẩu đổi ở nơi khác ({@see AuthenticateSession} của panel:
 *    `logoutCurrentDevice()` rồi `flush()`).
 *
 * Luật nằm ở {@see ForgetPushDevice::onLogout()}: gỡ dòng có endpoint ghi trong phiên theo guard
 * của sự kiện, chỉ khi dòng thuộc đúng người đang đăng xuất; không bao giờ ném.
 *
 * Sự kiện không mang request, nên phiên lấy từ kho `session.store` — cùng đối tượng mà `StartSession`
 * của request thật đã mở và gắn vào request (request giả của đường ống bền Livewire là `duplicate()`
 * của request thật, cùng kho). Ngoài một request (dòng lệnh, job) kho chưa mở và rỗng: không có khoá
 * nào, không máy nào là "máy này", nên không gỡ gì.
 *
 * Hết phiên mà KHÔNG đăng xuất (hết 120 phút, phiên bị xoá bằng CSDL khi "Đặt lại 2FA") thì đăng
 * ký còn, có chủ đích (R9): đó là lúc push có ích nhất, và nơi quyết định thật là luật người nhận
 * lúc gửi (Task 7–9), không phải lúc dọn.
 */
class ForgetPushDeviceOnLogout
{
    public function __construct(private readonly ForgetPushDevice $forget) {}

    public function handle(Logout|CurrentDeviceLogout $event): void
    {
        $user = $event->user;

        // `logout()` khi guard không có ai đăng nhập vẫn phát sự kiện, với `user` null.
        if (! ($user instanceof User || $user instanceof ClientUser)) {
            return;
        }

        /** @var Session $session */
        $session = app('session.store');

        $this->forget->onLogout($user, $event->guard, $session);
    }
}
