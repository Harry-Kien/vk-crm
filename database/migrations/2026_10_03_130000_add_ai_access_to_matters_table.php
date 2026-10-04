<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R9 (Task 7) — `matters.ai_access` (`App\Enums\MatterAiAccess`: `allowed` / `denied`): vụ việc có
 * được lên máy chủ MCP hay không.
 *
 * Mặc định của CỘT là `denied`, độc lập với `.env`: mọi vụ có trước migration này chưa có lời đồng ý
 * bằng văn bản nào được ghi nhận trong hệ thống, nên chúng KHÔNG lên AI cho tới khi một người bật từng
 * vụ ở tab Tổng quan (kèm ô tích đồng ý, `App\Actions\Matter\SetMatterAiAccess`). Vụ MỚI nhận giá trị
 * của `MCP_MATTER_DEFAULT` qua hook `creating` của `App\Models\Matter`, không qua mặc định này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->string('ai_access', 20)->default('denied')->after('confidentiality');
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn('ai_access');
        });
    }
};
