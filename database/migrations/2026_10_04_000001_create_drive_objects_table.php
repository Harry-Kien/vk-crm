<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Task 1 — chỉ mục khoá → tệp Google Drive (kế hoạch M14, R4, "Mô hình dữ liệu"). Bảng của hạ
 * tầng: không màn hình, không blameable. Đọc, kiểm tồn tại, kích thước, liệt kê đều trả lời từ đây,
 * nên một lượt tải xuống tốn đúng MỘT lệnh gọi Google.
 *
 * - `object_key` `<media_id>/<file_name>`, unique, NULL khi dòng đã rời chỉ mục sống (vào thùng rác,
 *   bị thay): MariaDB cho nhiều NULL trong một unique, nên không cần partial index. Khoá cũ khi đó ở
 *   `former_key`, lý do ở `retired_reason` (`App\Enums\DriveObjectRetirement`), lúc ở `retired_at`.
 *   Không dòng nào bị xoá: dòng đã rời là dấu vết của một tệp vẫn có thể còn trên Drive.
 * - `generation`: lần tải thứ mấy của khoá này; > 1 thì tên trên Drive có hậu tố `~g<N>` (R4).
 * - `drive_id` có ngay từ đầu: thêm Shared Drive thứ hai (gần 400.000 mục) hay chuyển sang Shared
 *   Drive mới khi khôi phục không phải sửa dữ liệu cũ.
 * - `file_id`: mã tệp Drive. KHÔNG BAO GIỜ rời máy chủ (R3).
 * - `size`, `md5`: Google trả về lúc tải lên xong. `md5` là `md5Checksum` của Google.
 * - `office_copied_at`: lúc CRM nhập một biên nhận của máy văn phòng có đúng tên, thế hệ và md5 của
 *   dòng này (R10) — điều kiện để dọn bản trong vùng đệm.
 *
 * # Collation nhị phân cho mã Drive và khoá (MariaDB)
 *
 * Mã tệp, mã thư mục và mã Shared Drive của Google phân biệt hoa thường. Với collation mặc định của
 * dự án (`utf8mb4_unicode_ci`), MariaDB coi hai mã chỉ khác hoa thường là MỘT: unique từ chối mã thứ
 * hai (tệp đã lên Drive mà không ghi được chỉ mục), và `where('file_id', …)` trả nhầm dòng. Khoá và
 * tiền tố thư mục (`LIKE '<d>/%'`) cũng phải so đúng từng byte. SQLite vốn so nhị phân, nên chỉ
 * MariaDB/MySQL cần khai; `tests/Feature/Storage/StorageSchemaTest.php` đo điều đó khi chạy trên
 * MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $binary = fn (ColumnDefinition $column): ColumnDefinition => in_array(Schema::getConnection()->getDriverName(), ['mariadb', 'mysql'], true)
            ? $column->collation('utf8mb4_bin')
            : $column;

        Schema::create('drive_objects', function (Blueprint $table) use ($binary) {
            $table->id();
            $binary($table->string('drive_id', 128))->index();
            $binary($table->string('object_key', 255))->nullable()->unique();
            $table->unsignedSmallInteger('generation')->default(1);
            $binary($table->string('former_key', 255))->nullable()->index();
            $table->string('retired_reason', 20)->nullable();
            $table->timestamp('retired_at')->nullable()->index();
            $binary($table->string('file_id', 128))->unique();
            $binary($table->string('parent_id', 128));
            $table->unsignedBigInteger('size');
            $table->char('md5', 32);
            $table->string('mime_type', 255)->nullable();
            $table->timestamp('office_copied_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_objects');
    }
};
