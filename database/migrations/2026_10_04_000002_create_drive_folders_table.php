<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Task 1 — thư mục tháng `<thư mục gốc>/<YYYY-MM>/` trên Drive (kế hoạch M14, R4). Drive cho
 * phép hai thư mục CÙNG TÊN dưới một cha, nên không bao giờ tìm thư mục theo tên: mã của thư mục
 * tháng nằm ở đây, và unique `(root_folder_id, name)` bảo đảm mỗi tháng chỉ một thư mục dưới mỗi thư
 * mục gốc.
 *
 * Collation nhị phân cho mã Drive trên MariaDB: cùng lý do đã ghi ở migration `drive_objects`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $binary = fn (ColumnDefinition $column): ColumnDefinition => in_array(Schema::getConnection()->getDriverName(), ['mariadb', 'mysql'], true)
            ? $column->collation('utf8mb4_bin')
            : $column;

        Schema::create('drive_folders', function (Blueprint $table) use ($binary) {
            $table->id();
            $binary($table->string('drive_id', 128))->index();
            $binary($table->string('root_folder_id', 128));
            $table->string('name', 20);
            $binary($table->string('folder_id', 128))->unique();
            $table->timestamps();

            $table->unique(['root_folder_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_folders');
    }
};
