<?php

use App\Enums\AiAccessMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M11 R2 (Task 6): công tắc truy cập qua AI THEO NGƯỜI — `off` / `read` / `read_write`
     * ({@see AiAccessMode}). Mặc định của CỘT là `off`: mọi nhân sự có trước M11, và mọi nhân sự tạo
     * sau đó, không dùng được máy chủ MCP cho tới khi quản trị bật đích danh người đó.
     *
     * Không phải một quyền spatie (`App\Enums\Permission` không thêm gì): đây là công tắc vận hành
     * theo người, không theo vai trò. Chỉ `App\Actions\Mcp\SetUserAiAccess` (bật, có Gate) và
     * `App\Actions\Mcp\RevokeAiConnections` (hạ về `off` khi vô hiệu hoá, đổi vai, xoá) đổi cột này.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ai_access', 20)->default(AiAccessMode::Off->value)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_access');
        });
    }
};
