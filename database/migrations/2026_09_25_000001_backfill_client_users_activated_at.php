<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 7 (R12, phát hiện `intake/intake-04`, `intake/intake-05`): "Có migration backfill cho các
 * tài khoản đã từng đăng nhập." — trước bản sửa này (đọc lại `App\Filament\Portal\Pages\Auth\ChangePassword`
 * ở cùng task), không đường HỆ THỐNG nào ghi `activated_at`. Không backfill thì
 * `NotifyClientOfStageUpdate::eligibleRecipientsQuery()` (điều kiện mới của cùng task) im lặng
 * loại bỏ MỌI khách hàng đang dùng cổng khỏi diện nhận thư `client.stage_update` ngay từ lần
 * triển khai đầu tiên — đúng kiểu hồi quy một migration phải lấp.
 *
 * # Fix round 1 (I3) — bản đầu tin nhầm một giá trị staff đã gõ tay
 *
 * Bản đầu của migration này giữ nguyên `activated_at` đã có (`whereNull('activated_at')`), với lý
 * do sai: docblock cũ viết "không đường nào từng ghi cột này", nhưng CÓ — form admin trước Task 7
 * có một `DateTimePicker::make('activated_at')` để NHÂN SỰ GÕ TAY (xem `ClientUserForm.php` ở
 * commit trước `eecbd46`, và chính phát hiện `intake/intake-05` mà task này xử lý: "activated_at
 * là một ô ngày giờ nhân sự gõ tay"). Một giá trị gõ tay không phải bằng chứng khách TỰ đổi mật
 * khẩu lần đầu — R12 định nghĩa `activated_at` đúng NGHĨA ĐÓ, không phải "một ngày nào đó nhân sự
 * điền" — nên GIỮ nguyên giá trị cũ là giữ lại đúng cái lỗ hổng migration này phải lấp.
 *
 * Phán quyết fix round 1: BỎ QUA mọi giá trị `activated_at` đang có, không điều kiện. Đặt lại
 * bằng `last_login_at` — bằng chứng GẦN NHẤT hệ thống có về việc ai đó thật sự đăng nhập được vào
 * hộp thư này (không chặt bằng "khách tự đổi mật khẩu lần đầu qua OTP", nhưng là ước lượng tốt
 * nhất cho dữ liệu quá khứ, và luôn ĐÚNG NGHĨA hơn một ngày staff gõ tay). Tài khoản chưa từng
 * đăng nhập (`last_login_at IS NULL`) tự nhiên nhận `activated_at = NULL` — không cần điều kiện
 * riêng, vì gán trực tiếp giá trị NULL của cột kia đã đúng ý nghĩa "chưa có bằng chứng nào".
 *
 * Cùng lượt: xoá `remember_token` của MỌI tài khoản — "Ghi nhớ đăng nhập" đã bị gỡ hẳn khỏi cổng
 * (`Login::form()`, phát hiện `portal/portal-1`) và CHƯA CÓ môi trường production nào đang chạy
 * (dự án đang ở M6.5), nên không có cookie thật nào phụ thuộc giá trị cũ để mất — token còn sót
 * lại chỉ là dữ liệu chết từ một tính năng không còn tồn tại.
 *
 * Không thể hoàn tác có ý nghĩa: `down()` không khôi phục `activated_at`/`remember_token` cũ, vì
 * không có bản sao nào được giữ lại trước khi ghi đè — và khôi phục giá trị staff gõ tay sẽ tái
 * tạo đúng lỗ hổng migration này lấp.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_users')->update([
            'activated_at' => DB::raw('last_login_at'),
            'remember_token' => null,
        ]);
    }

    public function down(): void
    {
        // Cố ý không hoàn tác — xem docblock ở trên.
    }
};
