<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chỉ thêm, không sửa, không xoá — tiền lệ `stage_logs` / `StageLogImmutable`. Giữ được
        // cả "giá trị hợp đồng là một cột" (contracts.total_amount) lẫn "phụ lục là chuyện có
        // thật" (M9, mục "Chỗ tôi nghĩ một quyết định chưa đúng"): mỗi lần total_amount đổi sinh
        // một dòng ở đây mang giá trị cũ, giá trị mới, lý do, ngày ký. Không `softDeletes()`:
        // đúng bảng chỉ-thêm thì không có gì để "xoá mềm" cả — model chặn XOÁ CỨNG vô điều kiện.
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->unsignedBigInteger('previous_total_amount');
            $table->unsignedBigInteger('new_total_amount');
            $table->text('reason');
            $table->date('signed_at');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_amendments');
    }
};
