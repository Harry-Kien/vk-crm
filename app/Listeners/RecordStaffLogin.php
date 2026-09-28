<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Login;

/**
 * Dấu vết đăng nhập THÀNH CÔNG của nhân sự trên guard `web` — SPEC §10.6, Task 20.
 *
 * Cùng khuôn M5 đã dựng cho guard `client`
 * ({@see \App\Filament\Portal\Pages\Auth\Login::recordSuccessfulLogin()}), nhưng khác cách móc
 * vào framework: cổng khách phải TỰ VIẾT lại `Login` page (SPEC §10.3 đòi một bộ đếm 5 lần/15
 * phút khác hẳn mặc định của Filament), nên nhật ký đăng nhập ở đó tiện thể nằm luôn trong lớp đã
 * viết lại ấy. Panel `admin` (`AdminPanelProvider`) KHÔNG viết lại `Login` page — nó dùng thẳng
 * `Filament\Auth\Pages\Login` mặc định, và trang đó đăng nhập qua
 * `Illuminate\Auth\SessionGuard::attemptWhen()`. Guard chuẩn của framework tự bắn
 * `Illuminate\Auth\Events\Login` ngay trong `login()` khi `attemptWhen()` thành công
 * (`SessionGuard::fireLoginEvent()`) — nên nghe đúng sự kiện chuẩn này là đủ, không cần viết lại
 * trang đăng nhập của panel `admin` chỉ để có một chỗ ghi nhật ký.
 *
 * Đăng ký qua auto-discovery của Laravel (phương thức tên `handle`) — không cần khai báo tường
 * minh như `App\Listeners\RecordOutboundMail` (lớp đó cố ý đặt tên khác phương thức để tránh
 * đăng ký trùng qua CẢ HAI đường). Lớp này chỉ có một đường đăng ký duy nhất nên không có nguy cơ
 * đó.
 *
 * `User::getActivitylogOptions()` không liệt kê `last_login_at` trong `logOnly()`, nên
 * `forceFill()->save()` dưới đây không sinh thêm một dòng "updated" nào — dòng `login_success` ở
 * đây là dòng nhật ký DUY NHẤT của lần đăng nhập.
 */
class RecordStaffLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $user = $event->user;

        if (! ($user instanceof User)) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Audit::record('login_success', $user, [
            'guard' => 'web',
            'ip' => request()->ip(),
        ], $user);
    }
}
