<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Filament\Auth\Pages\Login;
use Illuminate\Auth\Events\Failed;

/**
 * Dấu vết đăng nhập THẤT BẠI của nhân sự trên guard `web` — SPEC §10.6, Task 20. Xem docblock
 * {@see RecordStaffLogin} cho lý do nghe sự kiện chuẩn của framework thay vì viết lại trang
 * đăng nhập của panel `admin`.
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
 */
class RecordStaffLoginFailure
{
    public function handle(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user instanceof User ? $event->user : null;

        Audit::record('login_failed', $user, [
            'guard' => 'web',
            'email' => $event->credentials['email'] ?? null,
            'ip' => request()->ip(),
        ], $user);
    }
}
