<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Làn tk, Task 1 — việc giao nội bộ giữa luật sư, chuyên viên và nhân sự (chủ văn phòng, 2026-10-10).
 *
 * `matter_id` rỗng = việc chung (chỉ quản lý/quản trị giao). Độ dài cột là độ dài form và Action
 * (`App\Models\Task::*_MAX_LENGTH`, MariaDB strict): tiêu đề 255 ký tự, lý do trả lại / lý do huỷ
 * 1000, ghi chú hoàn thành 2000, mô tả là `text` (65.535 byte = 16.383 ký tự utf8mb4).
 *
 * Không `onDelete` nào: vụ việc và nhân sự không bao giờ bị xoá cứng (`MatterNotDestroyable`, R7), và
 * một khoá ngoại mặc định RESTRICT là lưới an toàn cuối nếu ai đó thử.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->nullable()->constrained('matters');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->constrained('users');
            $table->foreignId('assigned_by')->constrained('users');
            $table->date('due_date')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->string('status', 16)->default('pending');
            $table->timestamp('assigned_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_note', 2000)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assignee_id', 'status']);
            $table->index(['assigned_by', 'status']);
            $table->index(['matter_id', 'status']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
