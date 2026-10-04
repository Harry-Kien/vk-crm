<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R5 (Task 7) — nháp trả lời một yêu cầu của khách, do tool `draft_request_reply` soạn (Task 13).
 * Cùng khuôn với `stage_log_drafts`: bảng riêng (không phải `client_request_replies`, nên không tới
 * được cổng khách), một người trong `/admin` mở nháp, sửa, rồi bấm Gửi của luồng web
 * (`ReplyToClientRequest`, Task 12) dưới tên của chính người bấm; `used_reply_id` trỏ tới câu trả lời
 * đã sinh ra.
 *
 *  - Cha là `request_id` — đúng tên cột của `client_request_replies`, bảng mà nháp này sẽ trở thành.
 *  - `content` TEXT như `client_request_replies.content`. Không có cột người nhận: tool không nhận
 *    địa chỉ nào (R5, R11).
 *  - `idempotency_key` unique THEO NGƯỜI; không `deleted_at` — chỉ bỏ, kèm người và lý do (M6.5 R14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_request_reply_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('client_requests')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('content');
            $table->string('idempotency_key', 64);
            $table->foreignId('used_reply_id')->nullable()->constrained('client_request_replies')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('discard_reason')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['request_id', 'used_reply_id', 'discarded_at'], 'reply_drafts_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_reply_drafts');
    }
};
