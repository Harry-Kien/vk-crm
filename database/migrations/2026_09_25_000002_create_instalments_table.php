<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // KHÔNG có `paid_amount` (số đã thu là SUM của `payments` chưa huỷ — một cột tổng hợp
        // là nguồn sự thật thứ hai về tiền), KHÔNG có trạng thái `overdue` trong `status` (quá
        // hạn là hàm của thời gian, xem `App\Enums\InstalmentState`), KHÔNG có `reminders_sent`
        // (trí nhớ chống gửi trùng là `outbound_messages`, M6 R3). KHÔNG `softDeletes()` cùng lý
        // do với `contracts` (M9 quyết định 4): đợt của hợp đồng `draft` xoá cứng được, từ
        // `active` trở đi chỉ `cancelled` hoặc `waived` — model chặn ở `Instalment::booted()`.
        Schema::create('instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name', 150);
            $table->unsignedBigInteger('amount');
            $table->decimal('percent_basis', 5, 2)->nullable();
            $table->string('trigger_type', 20);
            $table->string('trigger_stage_key', 40)->nullable();
            $table->unsignedSmallInteger('due_days_after_trigger')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->foreignId('triggered_by_stage_log_id')->nullable()->constrained('stage_logs')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('waived_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'sequence']);
            // Cùng hình dạng với chỉ mục quá hạn của `deadlines` (SPEC §4.13): truy vấn quá hạn
            // chạy hằng ngày (Task 11).
            $table->index(['due_date', 'status']);
            $table->index('trigger_stage_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instalments');
    }
};
