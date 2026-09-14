<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng quan trọng nhất hệ thống: chỉ thêm, không sửa nội dung, không xoá (SPEC §4.8).
        // Không có deleted_at có chủ đích; model chặn delete.
        Schema::create('stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40)->nullable();
            $table->dateTime('occurred_at');
            $table->text('internal_note')->nullable();
            $table->text('public_content')->nullable();
            $table->text('next_step')->nullable();
            $table->text('client_action')->nullable();
            $table->date('expected_next_update_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['matter_id', 'is_published', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_logs');
    }
};
