<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MariaDB không có unique một phần: `unique(matter_type_id, key)` tính cả dòng đã xoá mềm,
     * nên xoá một giai đoạn rồi tạo lại cùng `key` sẽ báo lỗi trùng. Bỏ ràng buộc ở DB; tính
     * duy nhất trong phạm vi các dòng còn sống được form (StagesRelationManager) kiểm tra qua
     * `scopedUnique(...)` với điều kiện `whereNull('deleted_at')`.
     */
    public function up(): void
    {
        Schema::table('matter_type_stages', function (Blueprint $table) {
            $table->dropUnique(['matter_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('matter_type_stages', function (Blueprint $table) {
            $table->unique(['matter_type_id', 'key']);
        });
    }
};
