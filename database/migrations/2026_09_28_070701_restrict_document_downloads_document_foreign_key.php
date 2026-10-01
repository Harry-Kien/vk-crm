<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 7, bước 1 — `document_downloads.document_id` đổi từ `cascadeOnDelete` sang
 * `restrictOnDelete`, TRƯỚC khi `RetractDocument` tồn tại.
 *
 * Nhật ký tải (SPEC §4.12, "ghi log mọi lượt tải") là bằng chứng khách đã nhận một tài liệu. Với
 * `cascadeOnDelete`, một lần xoá cứng tài liệu xoá luôn bằng chứng đó mà không để lại gì. Không có
 * đường xoá cứng tài liệu hợp lệ nào (R5: không `forceDelete()` dữ liệu hồ sơ), nên một lần xoá cứng
 * tài liệu ĐÃ có lượt tải phải hỏng to thay vì lặng lẽ xoá chứng cứ. Cột giữ `NOT NULL`.
 *
 * Xoá mềm (`deleted_at`) không đụng tới khoá ngoại, nên không đổi gì với đường xoá mềm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_downloads', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->foreign('document_id')->references('id')->on('documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_downloads', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });
    }
};
