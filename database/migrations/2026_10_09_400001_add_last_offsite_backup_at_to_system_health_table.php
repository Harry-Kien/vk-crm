<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Làn fc (kiểm tra nghiệp vụ 2026-10-09, mục B "cảnh báo sao lưu chỉ có một kênh") —
 * `last_offsite_backup_at` trên dòng duy nhất của `system_health`: lúc một archive sao lưu được đẩy lên
 * Google Drive VÀ xác minh xong (tên và dung lượng trên remote khớp), do
 * `App\Actions\Backup\PushBackupArchiveToRclone` ghi. Dải sức khoẻ trên trang chủ `/admin` đọc nó để báo
 * đỏ khi bản ngoài máy chủ gần nhất quá cũ — không phụ thuộc thư báo lỗi có đi được hay không.
 *
 * Chỉ THÊM một cột rỗng được; máy chủ đã có dữ liệu không đổi gì cho tới lượt đẩy kế tiếp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_health', function (Blueprint $table) {
            $table->timestamp('last_offsite_backup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_health', function (Blueprint $table) {
            $table->dropColumn('last_offsite_backup_at');
        });
    }
};
