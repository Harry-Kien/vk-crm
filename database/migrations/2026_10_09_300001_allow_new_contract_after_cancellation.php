<?php

use App\Enums\ContractStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A1): "một hợp đồng cho một vụ việc" (M9
 * quyết định 1) đọc lại là "một hợp đồng CHƯA HUỶ cho một vụ việc". Trước đây `matter_id` unique
 * thật, nên một hợp đồng đã huỷ (không xoá được, vì là lịch sử tiền) khoá vụ việc vĩnh viễn: không
 * soạn được hợp đồng mới, không lịch thu, không công nợ, không nhắc quá hạn.
 *
 * Chốt ở CSDL vẫn là một unique THẬT, không chỉ một kiểm tra ở tầng ứng dụng: cột sinh
 * `open_matter_id` = `matter_id` khi hợp đồng chưa huỷ, `NULL` khi đã huỷ; unique trên cột đó cho
 * phép nhiều bản huỷ nhưng không bao giờ hai bản chưa huỷ trên cùng một vụ (NULL không đụng nhau
 * trong unique index — đúng trên cả MariaDB lẫn SQLite). Cột sinh kiểu VIRTUAL: MariaDB đánh index
 * được, SQLite thêm được bằng `ALTER TABLE` (cột STORED thì không).
 *
 * Thứ tự trên MariaDB: thêm index thường cho `matter_id` TRƯỚC khi bỏ unique, vì khoá ngoại
 * `contracts_matter_id_foreign` cần một index trên cột đó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->index('matter_id', 'contracts_matter_id_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique('contracts_matter_id_unique');
        });

        $cancelled = ContractStatus::Cancelled->value;

        Schema::table('contracts', function (Blueprint $table) use ($cancelled) {
            $table->unsignedBigInteger('open_matter_id')
                ->nullable()
                ->virtualAs("case when status = '{$cancelled}' then null else matter_id end");
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->unique('open_matter_id', 'contracts_open_matter_id_unique');
        });
    }

    /** Chỉ chạy được khi mỗi vụ còn đúng một hợp đồng — ngược lại unique cũ không dựng lại được. */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique('contracts_open_matter_id_unique');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('open_matter_id');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->unique('matter_id', 'contracts_matter_id_unique');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_matter_id_index');
        });
    }
};
