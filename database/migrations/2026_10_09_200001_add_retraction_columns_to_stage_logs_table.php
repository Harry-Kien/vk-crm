<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Làn fm, mục A2 (kiểm tra nghiệp vụ 2026-10-09): rút một dòng tiến độ đã công bố khỏi cổng khách
 * (`App\Actions\Matter\RetractStageLog`). Dòng KHÔNG bị xoá hay sửa nội dung — sổ tiến độ vẫn chỉ
 * ghi thêm (SPEC §4.8); ba cột này chỉ ghi ai rút, lúc nào, vì sao (lý do nội bộ, khách không đọc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_logs', function (Blueprint $table) {
            $table->timestamp('retracted_at')->nullable()->after('notified_at');
            $table->foreignId('retracted_by')->nullable()->after('retracted_at')
                ->constrained('users')->nullOnDelete();
            $table->text('retraction_reason')->nullable()->after('retracted_by');
        });
    }

    public function down(): void
    {
        Schema::table('stage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retracted_by');
            $table->dropColumn(['retracted_at', 'retraction_reason']);
        });
    }
};
