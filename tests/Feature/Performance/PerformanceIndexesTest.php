<?php

use Illuminate\Support\Facades\Schema;

/**
 * M13 Task 8 — index chỉ thêm khi `EXPLAIN` chứng minh cần VÀ ngân sách vỡ (R11). Trên dữ liệu của
 * `tests/Benchmark/TeamPerformanceBenchmarkTest.php` (150.000 dòng nhật ký, MariaDB), truy vấn P6 của "Hiệu
 * suất theo kỳ" một quý (dòng `checklist_item_reviewed` trong kỳ, gộp theo người bấm) quét CẢ index `causer`
 * (`type=index`, 148.955 dòng, 157 ms) trong khi trang vượt 500 ms — ứng viên `activity_log(event, created_at)`
 * của R11. Số đo trước/sau ở "Ghi chú M13" (PROGRESS).
 */
it('indexes the activity log by event then time, for the review lines of a period (P6)', function () {
    expect(Schema::hasIndex('activity_log', ['event', 'created_at']))->toBeTrue();
});
