<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Làn tk, Task 2 — "Nhận hồ sơ": một nhân sự được THÊM vào đội ngũ một vụ (trừ vai người theo dõi) hay
 * trở thành luật sư phụ trách qua "Bàn giao" xác nhận lần giao đó — "Đã nhận hồ sơ" hoặc "Trả lại" kèm
 * lý do. Bốn mốc trên chính ghế của họ (`matter_user`), không bảng riêng: ghế là thứ được giao.
 *
 *  - `assigned_by`, `assigned_at`: ai giao, lúc nào (lần giao gần nhất của ghế này).
 *  - `accepted_at`: người nhận bấm "Đã nhận hồ sơ".
 *  - `declined_at`, `decline_reason`: người nhận bấm "Trả lại" — KHÔNG gỡ người khỏi vụ; quản lý hay
 *    luật sư phụ trách phân công lại bằng các nút sẵn có (`App\Models\MatterUser::assignmentStatus()`).
 *
 * `decline_reason` VARCHAR(1000) — cùng độ dài lý do trả lại của một việc (`Task::REASON_MAX_LENGTH`).
 *
 * **Ghế đã có trước tính năng này coi như đã nhận**: `accepted_at` và `assigned_at` lấy `created_at` của
 * dòng (hay giờ chạy migration nếu dòng không có), `assigned_by` để trống — không ai đoán lại được người
 * đã thêm họ. Không có dòng cũ nào hiện "Chờ nhận" sau lần nâng cấp.
 *
 * Không thêm chỉ mục: mọi truy vấn ghế lọc theo `user_id` hay `matter_id` trước (đã có chỉ mục của hai khoá
 * ngoại và khoá duy nhất `(matter_id, user_id)`). Một chỉ mục ghép bắt đầu bằng `user_id` còn khiến
 * MariaDB dùng nó thay chỉ mục của khoá ngoại `user_id`, và `down()` không gỡ được nó nữa (lỗi 1553).
 *
 * `assigned_by` không `onDelete`: nhân sự không bao giờ bị xoá cứng (xoá mềm), và khoá ngoại mặc định
 * RESTRICT là lưới cuối — cùng luật bảng `tasks`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_user', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('role_in_matter')->constrained('users');
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->timestamp('accepted_at')->nullable()->after('assigned_at');
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
            $table->string('decline_reason', 1000)->nullable()->after('declined_at');
        });

        $now = DB::getPdo()->quote(now()->toDateTimeString());

        DB::table('matter_user')->update([
            'assigned_at' => DB::raw("COALESCE(created_at, {$now})"),
            'accepted_at' => DB::raw("COALESCE(created_at, {$now})"),
        ]);
    }

    public function down(): void
    {
        Schema::table('matter_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['assigned_at', 'accepted_at', 'declined_at', 'decline_reason']);
        });
    }
};
