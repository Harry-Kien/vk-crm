<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rà soát toàn hệ thống 2026-10-22 (làn portal, việc chuyển từ làn security): thế hệ phiên của tài khoản
 * cổng — cùng khuôn `users.session_epoch`. `App\Actions\Portal\EndPortalSessions` tăng nó khi văn phòng
 * tắt hay xoá tài khoản; `App\Http\Middleware\EnsurePortalAccountIsActive` đăng xuất phiên mang số cũ, kể
 * cả khi tài khoản đã được bật lại / khôi phục trước request kế tiếp của phiên đó. Phiên có từ trước bản
 * này không mang số nào và được đọc là 0 — đúng giá trị mặc định, nên không ai bị đăng xuất lúc triển khai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropColumn('session_epoch');
        });
    }
};
