<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A4): trang đăng nhập nội bộ không còn ô
 * "Ghi nhớ đăng nhập" (`App\Filament\Admin\Pages\Auth\Login::form()`), nhưng những máy đã tích ô đó
 * TRƯỚC bản sửa vẫn giữ cookie recaller sống 400 ngày. Xoá `remember_token` của mọi nhân sự làm
 * mọi cookie đó hết hiệu lực: `EloquentUserProvider::retrieveByToken()` từ chối khi token trong
 * CSDL rỗng. Nhân sự chỉ phải đăng nhập lại (mật khẩu + mã 2FA); không dữ liệu nào mất.
 *
 * Chỉ bảng `users` (nhân sự). Cổng khách đã bỏ ô này từ `portal/portal-1`. `down()` không làm gì:
 * token cũ không khôi phục được, và cũng không nên.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        //
    }
};
