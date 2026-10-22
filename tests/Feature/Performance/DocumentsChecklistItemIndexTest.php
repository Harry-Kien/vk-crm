<?php

use Illuminate\Support\Facades\Schema;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn perf2 — cột N10 của "Theo dõi đội ngũ" (`ChecklistProgress::totalsByLead()`)
 * hỏi EXISTS trên `documents` theo đầu mục. MariaDB/MySQL có sẵn index của khoá ngoại; SQLite không tự tạo, và thiếu
 * nó trang mất khoảng 5 giây trên dữ liệu đo M13 (migration `2026_10_22_300001`). Trên mọi cơ sở dữ liệu phải có
 * ĐÚNG MỘT index chỉ gồm cột này (không trùng với index của khoá ngoại).
 */
it('has exactly one index on documents.matter_checklist_item_id on every database', function () {
    $indexes = collect(Schema::getIndexes('documents'))
        ->filter(fn (array $index): bool => $index['columns'] === ['matter_checklist_item_id']);

    expect($indexes)->toHaveCount(1);
});
