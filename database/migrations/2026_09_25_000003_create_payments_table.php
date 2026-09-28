<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khoản thu là bản ghi riêng, chỉ thêm (M9 quyết định 4): KHÔNG `softDeletes()`, model
        // chặn MỌI lần xoá vô điều kiện (`Payment::booted()` → `PaymentNotDestroyable`). Ghi
        // nhầm thì huỷ (`voided_at`/`voided_by`/`void_reason`), không xoá — đúng tinh thần
        // `stage_logs`. `attributed_lawyer_id` (P2, doanh thu ghi cho luật sư phụ trách LÚC THU)
        // KHÔNG nullable: mọi vụ việc luôn có luật sư phụ trách.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instalment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->foreignId('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('attributed_lawyer_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('instalment_id');
            $table->index('paid_on');
            $table->index('voided_at');
            $table->index(['attributed_lawyer_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
