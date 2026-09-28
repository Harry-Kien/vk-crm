<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Một hợp đồng cho một vụ việc (M9 quyết định 1): `matter_id` UNIQUE THẬT, không phải
        // một quy ước ở tầng ứng dụng. KHÔNG `softDeletes()` (M9 quyết định 4, deviation so với
        // §4): `matter_id` là unique, và một hợp đồng xoá mềm vẫn chiếm chỗ index — "xoá mềm rồi
        // tạo lại" đúng là lỗ hổng dự án đã vấp hai lần (`matter_type_stages.key`,
        // `MatterType.code`). Model chặn xoá cứng trừ khi còn `draft` và chưa có khoản thu nào
        // (`Contract::booted()` → `ContractNotDestroyable`).
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('status', 20)->default('draft');
            $table->string('billing_model', 20)->default('fixed_fee');
            $table->unsignedBigInteger('total_amount');
            $table->unsignedTinyInteger('vat_rate_percent')->nullable();
            $table->date('signed_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('ended_at')->nullable();
            $table->text('ended_reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('matter_id');
            $table->unique('code');
            $table->index('status');
            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
