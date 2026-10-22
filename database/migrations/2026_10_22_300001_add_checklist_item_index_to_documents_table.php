<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn perf2 — index cho `documents.matter_checklist_item_id` ở nơi chưa có.
 *
 * MariaDB/MySQL tự tạo index cho khoá ngoại (`documents_matter_checklist_item_id_foreign`), SQLite thì không. Trên
 * SQLite, cột N10 "Hoàn thiện danh mục" của "Theo dõi đội ngũ" (`ChecklistProgress::totalsByLead()`, nhánh "có tài
 * liệu nhóm A" là một EXISTS trên `documents` theo đầu mục) quét cả bảng tài liệu cho MỖI đầu mục: khoảng 5 giây trên
 * dữ liệu đo M13 (30.000 đầu mục, 20.000 tài liệu), 85 ms khi có index. Chỉ tạo khi cột chưa có index nào đứng đầu,
 * nên MariaDB không có index trùng; không đổi kết quả của truy vấn nào.
 */
return new class extends Migration
{
    private const INDEX = 'documents_matter_checklist_item_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('documents', ['matter_checklist_item_id'])) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->index('matter_checklist_item_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('documents', self::INDEX)) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
