<?php

use App\Http\Middleware\RejectStaffSessionsFromBeforeReset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Epoch phiên" của nhân sự (M8 Task 2, vòng sửa 1): bộ đếm tăng một mỗi lần `ResetStaffTwoFactor`
     * chạy. Mỗi phiên `web` ghi lại giá trị hiện hành lúc đăng nhập; phiên nào mang giá trị khác thì
     * bị đăng xuất ở request kế tiếp ({@see RejectStaffSessionsFromBeforeReset}).
     * Có cột này để KHÔNG phải tìm phiên theo `sessions.user_id` — cột đó do guard mặc định lúc ghi
     * điền, nên không đáng tin (xem docblock của middleware).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_epoch');
        });
    }
};
