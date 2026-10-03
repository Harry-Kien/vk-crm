<?php

use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 9 — index cho bốn cột tìm kiếm chưa có index (SPEC §6.13 "LIKE với index phù hợp"). Đo
 * bằng `Schema::getIndexes()` của CSDL đang chạy (SQLite hoặc MariaDB), không đọc tệp migration.
 */
it('có index một cột cho cột tìm kiếm', function (string $table, string $column) {
    $indexes = collect(Schema::getIndexes($table))
        ->filter(fn (array $index): bool => $index['columns'] === [$column])
        ->pluck('name')
        ->all();

    expect($indexes)->toBe(["{$table}_{$column}_index"]);
})->with([
    ['matters', 'case_number'],
    ['matters', 'title'],
    ['clients', 'name'],
    ['documents', 'title'],
]);
