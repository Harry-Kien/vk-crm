<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R5 (Task 7) — `communication_logs.created_via` (`App\Enums\CreatedVia`, mặc định `web`). Tool
 * `log_communication` (Task 13, qua Action ghi nhật ký liên lạc của M7 Task 8) ÉP `mcp`, cùng
 * `is_visible_to_client = false`; tab "Liên lạc" hiện nhãn "Tạo qua AI" (Task 12). Mọi dòng có trước
 * migration này do nhân sự nhập, nên `web` là đúng cho chúng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->string('created_via', 20)->default('web')->after('is_visible_to_client');
        });
    }

    public function down(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
