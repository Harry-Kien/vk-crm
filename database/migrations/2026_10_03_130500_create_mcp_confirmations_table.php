<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R6 (Task 7) — mã xác nhận hai bước ĐÃ DÙNG của hai tool ghi nội bộ (`create_deadline`,
 * `log_communication`, Task 13). Mã ký HMAC không trạng thái; bảng này chỉ giữ `jti` của mã đã dùng
 * và bản ghi nó đã tạo, trong CÙNG transaction với bản ghi đó. `jti` unique: gọi lại cùng mã trả
 * đúng bản ghi đã tạo (`result_type`/`result_id`, alias của morph map), không tạo bản thứ hai — đó là
 * lý do `idempotentHint: true` trung thực.
 *
 * Chỉ `created_at`: một dòng không bao giờ đổi sau khi ghi. `tool` dài 64 = độ dài tối đa của tên
 * tool MCP. `user_id` `cascadeOnDelete`: dòng này không có giá trị nghiệp vụ nào ngoài chống gọi lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('jti', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 64);
            $table->string('result_type', 50);
            $table->unsignedBigInteger('result_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['result_type', 'result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_confirmations');
    }
};
