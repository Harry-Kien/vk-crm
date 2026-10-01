<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Filament\Auth\Pages\Login;
use Illuminate\Auth\Events\Failed;

/**
 * Dấu vết đăng nhập THẤT BẠI của nhân sự trên guard `web` — SPEC §10.6, Task 20. Xem docblock
 * {@see RecordStaffLogin} cho lý do nghe sự kiện chuẩn của framework thay vì viết lại trang
 * đăng nhập của panel `admin`. (M8 Task 3 sau đó thêm `App\Filament\Admin\Pages\Auth\Login` cho
 * bộ đếm SPEC §10.3, nhưng nó KẾ THỪA trang của Filament và không đổi hai nhánh bắn `Failed` dưới
 * đây — listener này vẫn là nơi ghi lỗi ở bước mật khẩu.)
 *
 * `Filament\Auth\Pages\Login` bắn `Illuminate\Auth\Events\Failed` ở HAI nhánh khác nhau, cả hai
 * đều tới được đây:
 *
 *  - `$authGuard->attemptWhen()` (bước cuối, mật khẩu đúng nhưng callback từ chối, hoặc mật khẩu
 *    sai) — `SessionGuard::attemptWhen()` tự bắn qua `fireFailedEvent()` của chính guard.
 *  - Nhánh kiểm sớm trong `authenticate()` (không tìm thấy tài khoản, hoặc tài khoản không vào
 *    được panel) — trang tự bắn bằng `event(app(Failed::class, [...]))`
 *    ({@see Login::fireFailedEvent()}).
 *
 * Cả hai đều mang `guard` là TÊN GUARD (chuỗi 'web'), không phải đối tượng Guard, và `$user` có
 * thể `null` — đúng trường hợp một email không ứng với tài khoản nào, thứ vẫn phải để lại dấu vết
 * kèm địa chỉ mạng (SPEC §10.6, "một lần thất bại trên email không tồn tại vẫn được audit, kèm
 * IP"). `Audit::record()` chấp nhận `$subject`/`$causer` đều `null` — dòng vẫn được ghi, không có
 * causer nào để gán (không phải một thiếu sót, xem docblock `Audit::record()`).
 *
 * Không log mật khẩu: `$event->credentials` mang cả khoá `password`, nên chỉ đọc `email` ra khỏi
 * nó, đúng thành ngữ `App\Filament\Portal\Pages\Auth\Login::auditFailedLogin()`.
 *
 * **Final review C-M6: và chỉ ghi `email` khi nó THẬT SỰ là một địa chỉ email.** Ô email có thể
 * chứa một mật khẩu gõ/dán nhầm ô; ghi nguyên văn thì mật khẩu đó nằm dạng chữ trong nhật ký. Giá
 * trị không phải email → `null`.
 */
class RecordStaffLoginFailure
{
    public function handle(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user instanceof User ? $event->user : null;
        $typed = $event->credentials['email'] ?? null;

        Audit::record('login_failed', $user, [
            'guard' => 'web',
            // M8 Task 3: cùng khoá `step` mà cổng khách ghi (`Login::auditFailedLogin()`), để
            // `UnlockStaffLogin` biết tra khoá IP của bước mật khẩu hay bước mã. Sự kiện `Failed`
            // chỉ bắn ở bước mật khẩu (kể cả lần kiểm lại credentials sau khi mã đã đúng, cũng đập
            // bộ đếm mật khẩu); lỗi ở bước mã do `Admin\Pages\Auth\Login` ghi với `step = code`.
            'step' => 'password',
            'email' => is_string($typed) && filter_var(trim($typed), FILTER_VALIDATE_EMAIL) !== false ? trim($typed) : null,
            'ip' => request()->ip(),
        ], $user);
    }
}
