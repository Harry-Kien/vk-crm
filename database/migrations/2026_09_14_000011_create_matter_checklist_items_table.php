<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sao chép từ template khi mở vụ việc; sửa template sau không ảnh hưởng (SPEC §4.10).
        Schema::create('matter_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('missing');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_checklist_items');
    }
};
