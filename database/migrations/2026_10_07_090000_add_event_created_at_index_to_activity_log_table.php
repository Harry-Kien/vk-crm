<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M13 Task 8 — index `activity_log(event, created_at)`, ứng viên đã nêu tên trong R11 của kế hoạch M13
 * ("P6, lần vô hiệu hoá của R3"). Chỉ thêm vì `EXPLAIN` chứng minh cần và ngân sách vỡ: trên dữ liệu của
 * `tests/Benchmark/TeamPerformanceBenchmarkTest.php` (MariaDB, 150.000 dòng nhật ký), truy vấn P6 của trang
 * "Hiệu suất theo kỳ" một quý — dòng `checklist_item_reviewed` có `created_at` trong kỳ, gộp theo người bấm —
 * đi theo index `causer` với `type=index`, quét 148.955 dòng, 157 ms, trong khi cả trang vượt ngân sách
 * 500 ms. Số đo trước/sau ở "Ghi chú M13" của `docs/PROGRESS.md`.
 *
 * Chỉ thêm index, không đổi cột hay dữ liệu: `migrate` trên máy chủ đã có dữ liệu chạy một câu `ALTER TABLE
 * … ADD INDEX` (MariaDB dựng index trực tuyến, bảng vẫn đọc ghi được). `down()` gỡ đúng index này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['event', 'created_at'], 'activity_log_event_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_event_created_at_index');
        });
    }
};
