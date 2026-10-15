<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Làn kd (2026-10-14): ghi chú chăm sóc lead — mỗi lần liên hệ một khách tiềm năng là MỘT dòng (thời
     * gian, kênh, nội dung ngắn, người ghi). `communication_logs` bắt buộc có vụ việc (`matter_id` không
     * null), nên không dùng lại được cho người chưa thành khách.
     *
     * `note` 1000 ký tự (= `IntakeFollowUp::NOTE_MAX_LENGTH` = `maxLength` của form, MariaDB strict) và
     * nullable: ẩn danh (`AnonymiseProspect`, R7) đặt nó về null, dòng ở lại (thời gian, kênh, người ghi)
     * cho thống kê như dòng của bản ghi cha. Không `softDeletes`: không màn hình nào xoá một lần chăm sóc,
     * và bản ghi cha cũng không bao giờ bị xoá (xoá là ẩn danh).
     */
    public function up(): void
    {
        Schema::create('intake_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_request_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->dateTime('occurred_at');
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['intake_request_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_follow_ups');
    }
};
