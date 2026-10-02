<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 7 — ba cột ghi lại một lần RÚT LẠI tài liệu đã công bố (`RetractDocument`), cùng trạng
 * thái thứ năm `retracted` của `documents.status` (cột `string(20)` có từ M1, đủ chỗ, không đổi).
 *
 * - `retracted_at`: lúc rút.
 * - `retracted_by`: người rút. `nullOnDelete` — xoá một tài khoản nhân sự (vốn chỉ xoá mềm) không
 *   được kéo theo bản ghi tài liệu; dòng audit `document_retracted` vẫn giữ causer.
 * - `retraction_reason`: lý do, KHÁCH ĐỌC ĐƯỢC trên cổng. `text`; trần chủ động ở
 *   `RetractDocument::REASON_MAX`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('retracted_at')->nullable()->after('published_by');
            $table->foreignId('retracted_by')->nullable()->after('retracted_at')
                ->constrained('users')->nullOnDelete();
            $table->text('retraction_reason')->nullable()->after('retracted_by');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retracted_by');
            $table->dropColumn(['retracted_at', 'retraction_reason']);
        });
    }
};
