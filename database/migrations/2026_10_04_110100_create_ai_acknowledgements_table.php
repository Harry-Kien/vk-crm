<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M11 R12 mục 1 (Task 6): lời cam kết của NHÂN SỰ trước lần kết nối AI đầu tiên (Quy tắc 7.2
     * [PL:342]) — không thay đồng ý của khách (R9, `matters.ai_access`). Nghị định 356 đòi đồng ý
     * "lưu lại và kiểm chứng được" [PL:331], nên mỗi lần cam kết là một dòng không bao giờ sửa:
     *
     *  - `policy_version`: phiên bản chính sách người đó đã đọc (`config('vkcrm.mcp.policy_version')`
     *    lúc cam kết). Đổi phiên bản thì dòng cũ ở lại làm bằng chứng, và người đó phải cam kết lại
     *    (dòng mới) trước khi `/mcp` mở lại cho họ;
     *  - `accepted_at`, `ip_address` (45 = IPv6 dạng dài nhất), `user_agent` (cắt ở 500 ký tự).
     *
     * Unique (`user_id`, `policy_version`): cam kết lại cùng phiên bản trả đúng dòng đã có.
     * `restrictOnDelete`: tài khoản nhân sự chỉ xoá mềm (`UserPolicy::forceDelete()` luôn từ chối);
     * một lần xoá cứng nào đó không được lặng lẽ cuốn theo bằng chứng.
     */
    public function up(): void
    {
        Schema::create('ai_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 20);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_acknowledgements');
    }
};
