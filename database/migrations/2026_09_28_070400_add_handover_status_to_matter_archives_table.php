<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 4 (R9): trạng thái của MỘT lần yêu cầu sinh gói bàn giao, để màn hình hiện "đang sinh /
 * sẵn sàng / lỗi" và khoá nút bấm lại khi gói đang được dựng (một gói lớn có thể mất nhiều phút,
 * và người bấm không có gì khác để biết nó có chạy hay không).
 *
 * - `handover_status` (`HandoverPackageStatus`): NULL = chưa ai yêu cầu. `string(20)` khớp cột
 *   `documents.status`.
 * - `handover_requested_at` / `handover_requested_by`: lúc bấm (hoặc lúc vụ đóng, với lần tự sinh)
 *   và người bấm (NULL với lần tự sinh). `handover_requested_at` còn là **dấu của lần yêu cầu**:
 *   job mang theo giá trị đó và chỉ được ghi kết quả khi nó vẫn khớp — một job cũ (bị nhặt lại
 *   sau khi nút được mở khoá vì kẹt) không ghi đè kết quả của lần yêu cầu mới hơn.
 * - `handover_error`: một câu tiếng Việt cho người vận hành, KHÔNG phải thông điệp thô của
 *   exception (thông điệp thô đi vào log). `string(500)`.
 *
 * `handover_generated_at` (thời điểm xong) đã có từ M1; `handover_document_id` từ Task 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_archives', function (Blueprint $table) {
            $table->string('handover_status', 20)->nullable()->after('handover_document_id');
            $table->timestamp('handover_requested_at')->nullable()->after('handover_status');
            $table->foreignId('handover_requested_by')->nullable()->after('handover_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->string('handover_error', 500)->nullable()->after('handover_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('matter_archives', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_requested_by');
            $table->dropColumn(['handover_status', 'handover_requested_at', 'handover_error']);
        });
    }
};
