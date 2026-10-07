<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M10 Task 2, fix vòng 1 (I2) — ĐỎ DÍNH. `conflict_level` là mức của lần kiểm tra GẦN NHẤT, nên
     * một người không phải quản lý chỉ cần sửa/gỡ bên đối lập rồi chạy lại là Đỏ biến mất và ô câu
     * chuyện mở — trái R1 ("Đỏ … chỉ quản lý hoặc admin mở được, bằng một trong hai: từ chối (R8),
     * hoặc ghi đè kèm lý do bắt buộc"). Cột này ghi THỜI ĐIỂM bản ghi ra Đỏ lần đầu mà chưa được xử
     * lý: `CheckIntakeConflict` đặt nó khi một lần kiểm tra ra Đỏ (hoặc khi người gọi lại mang khoá
     * của lần gọi trước, C1), chỉ `ResolveIntakeRedConflict` (ghi đè có lý do) xoá nó. Không phải dữ
     * liệu cá nhân: ẩn danh (Task 7) giữ nguyên.
     *
     * Không có trong bảng "Mô hình dữ liệu" của kế hoạch — ghi ở Ghi chú M10.
     */
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->timestamp('conflict_red_pending_since')->nullable()->after('conflict_override_reason');
        });
    }

    public function down(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->dropColumn('conflict_red_pending_since');
        });
    }
};
