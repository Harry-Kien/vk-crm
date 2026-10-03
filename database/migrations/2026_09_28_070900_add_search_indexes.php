<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 9 — index cho bốn cột mà ô tìm kiếm của admin đọc và chưa có index (SPEC §6.13 "`LIKE` với
 * index phù hợp"): `matters.case_number`, `matters.title`, `clients.name`, `documents.title`.
 * (`matters.code`, `clients.code` đã unique từ M1; `matter_parties.name_normalized` đã có index.)
 *
 * **`LIKE 'x%'` (tiền tố) dùng được index B-tree; `LIKE '%x%'` (chứa) thì không** — với kiểu chứa,
 * MariaDB duyệt cả bảng, hoặc cả index khi index phủ đủ cột (`EXPLAIN` `type=index`).
 *
 * **Câu tìm của `App\Actions\Search\SearchMatters` KHÔNG dùng các index này**, và đã đo: sáu nguồn
 * nằm trong một `OR` có vế kiểu chứa, nên `EXPLAIN` cho `type=ALL` ở `matters`, `clients`,
 * `documents`, `matter_parties` — 3,5–23,5 ms trên 6.000 vụ (số đo ở "Ghi chú M7"). Các index phục vụ
 * câu tiền tố đứng riêng (`case_number LIKE '4711/2026%'` → `range` trên `matters_case_number_index`),
 * sắp xếp theo cột đó, và là đường nâng cấp khi quy mô vượt "vài nghìn hồ sơ". Kế hoạch M7 Task 9 đặt
 * chúng; docblock của Action ghi lý do chọn kiểu tìm cho từng nguồn.
 *
 * Độ dài khoá: cột dài nhất là `varchar(250)` utf8mb4 = 1.000 byte, dưới trần 3.072 byte của InnoDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->index('case_number');
            $table->index('title');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index('name');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['title']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->dropIndex(['title']);
            $table->dropIndex(['case_number']);
        });
    }
};
