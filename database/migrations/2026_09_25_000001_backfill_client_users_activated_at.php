<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 7 (R12, phát hiện `intake/intake-04`, `intake/intake-05`): "Có migration backfill cho các
 * tài khoản đã từng đăng nhập." — trước bản sửa này (đọc lại `App\Filament\Portal\Pages\Auth\ChangePassword`
 * ở cùng task), không đường nào từng ghi `activated_at`, nên MỌI tài khoản cổng đang có trong CSDL
 * — kể cả những tài khoản đã đăng nhập hàng chục lần — đều đọc `activated_at IS NULL`. Không
 * backfill thì `NotifyClientOfStageUpdate::eligibleRecipientsQuery()` (điều kiện mới của cùng
 * task) im lặng loại bỏ MỌI khách hàng đang dùng cổng khỏi diện nhận thư `client.stage_update`
 * ngay từ lần triển khai đầu tiên — đúng kiểu hồi quy một migration phải lấp.
 *
 * Chỉ backfill cho tài khoản CÓ `last_login_at` (đã từng đăng nhập thật — bằng chứng gần nhất mà
 * hệ thống có về việc ai đó làm chủ hộp thư này, dù không chặt bằng activated_at thật sự ghi tại
 * lần đổi mật khẩu đầu tiên). Tài khoản `last_login_at IS NULL` (chưa từng đăng nhập) giữ nguyên
 * `activated_at = NULL` — đúng ý nghĩa của cột: "chưa có bằng chứng nào khách làm chủ hộp thư
 * này", không suy diễn.
 *
 * `whereNull('activated_at')` là điều kiện AN TOÀN quan trọng nhất ở đây: nó bảo đảm migration
 * không bao giờ ghi đè một `activated_at` thật đã có (ví dụ một lần chạy migration thứ hai, hay
 * một hàng đã được `ChangePassword::changePassword()` ghi trước khi migration này chạy trên một
 * môi trường triển khai lại từ đầu) bằng một giá trị suy diễn từ `last_login_at`.
 *
 * Không thể hoàn tác có ý nghĩa: `down()` không xoá lại `activated_at` đã backfill, vì làm vậy sẽ
 * tái tạo đúng lỗ hổng mà migration này lấp, không phải "dọn dẹp" một cột mới.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_users')
            ->whereNull('activated_at')
            ->whereNotNull('last_login_at')
            ->update(['activated_at' => DB::raw('last_login_at')]);
    }

    public function down(): void
    {
        // Cố ý không hoàn tác — xem docblock ở trên.
    }
};
