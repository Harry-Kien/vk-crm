<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R5 (Task 7) — nháp dòng cập nhật tiến độ (KHÔNG đổi giai đoạn) do tool `draft_progress_update`
 * soạn (Task 13). Bảng riêng, không phải `stage_logs`: một nháp về cấu trúc không thể tới cổng khách.
 * Một người trong `/admin` mở nháp, sửa, rồi bấm "Thêm cập nhật" của luồng web (`TransitionMatterStage`,
 * Task 12) dưới tên của chính người bấm; `used_stage_log_id` trỏ tới dòng tiến độ đã sinh ra.
 *
 *  - Năm cột nội dung cùng kiểu với cột tương ứng của `stage_logs` (TEXT, `date`), để một nháp luôn
 *    dùng được nguyên văn.
 *  - `idempotency_key` (8–64 ký tự, Task 13 kiểm) unique THEO NGƯỜI: gọi lại cùng khoá trả nháp đã có.
 *  - Không `deleted_at`: nháp không xoá được, chỉ BỎ — `discarded_at`, `discarded_by`, `discard_reason`
 *    (M6.5 R14). `App\Models\StageLogDraft` từ chối mọi lần xoá và mọi lần bỏ thiếu người hay lý do.
 *  - `created_by` bắt buộc: người sở hữu token MCP. `restrictOnDelete` — không ai xoá cứng được một
 *    nhân sự mà vẫn để nháp của họ trôi nổi không chủ.
 *  - `matter_id` và `used_stage_log_id` cũng `restrictOnDelete` (M11 Task 13, rà soát Task 7 m2/m3; sửa
 *    tại chỗ vì migration chưa từng chạy trên máy chủ nào): một lần xoá CỨNG vụ — dưới tầng model, nơi
 *    hook `deleting` của nháp không chạy — không được lặng lẽ xoá nháp; và một lần xoá cứng dòng tiến độ
 *    không được đưa nháp đã dùng về "đang chờ" để gửi lần hai. Không đường nào trong `app/` xoá cứng
 *    hai bảng cha đó, nên khoá ngoại là chốt cuối, không đổi hành vi nào hôm nay.
 *  - `idempotency_key` được tool chuyển về chữ thường trước khi ghi (Task 13), nên unique theo người
 *    cho cùng câu trả lời trên MariaDB (`utf8mb4_unicode_ci`) và SQLite (rà soát Task 7 m4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_log_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('public_content')->nullable();
            $table->text('next_step')->nullable();
            $table->text('client_action')->nullable();
            $table->date('expected_next_update_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('idempotency_key', 64);
            $table->foreignId('used_stage_log_id')->nullable()->constrained('stage_logs')->restrictOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('discard_reason')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['matter_id', 'used_stage_log_id', 'discarded_at'], 'stage_log_drafts_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_log_drafts');
    }
};
