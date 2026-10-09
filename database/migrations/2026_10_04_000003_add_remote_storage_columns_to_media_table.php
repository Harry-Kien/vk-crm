<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Task 1 — cột của kho tài liệu trên bảng `media` của thư viện media (kế hoạch M14, R2, R10,
 * "Mô hình dữ liệu"). "Tệp đang ở đâu" vẫn là cột `disk` sẵn có (`private` hay `documents_remote`);
 * bốn cột này đi kèm:
 *
 * - `remote_pushed_at`: lúc đổi đĩa sang kho. Quay lui đặt về NULL.
 * - `local_purge_after`: bản trong vùng đệm (`private`) được giữ ít nhất tới lúc này. NULL = không
 *   còn bản cục bộ, chưa đẩy, hoặc đã quay lui.
 * - `checksum_md5`, `checksum_sha256`: tính từ vùng đệm lúc đẩy. md5 là thứ so với Google; sha256 là
 *   bản ghi toàn vẹn của riêng CRM (`sha256Checksum` của Google có thể vắng).
 *
 * Chỉ mục `(disk, created_at)` cho việc tìm tệp chờ đẩy và chuyển tệp cũ; `(disk,
 * local_purge_after)` và `local_purge_after` cho lượt dọn vùng đệm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->timestamp('remote_pushed_at')->nullable();
            $table->timestamp('local_purge_after')->nullable()->index();
            $table->char('checksum_md5', 32)->nullable();
            $table->char('checksum_sha256', 64)->nullable();

            $table->index(['disk', 'created_at']);
            $table->index(['disk', 'local_purge_after']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['disk', 'local_purge_after']);
            $table->dropIndex(['disk', 'created_at']);
            $table->dropIndex(['local_purge_after']);

            $table->dropColumn(['remote_pushed_at', 'local_purge_after', 'checksum_md5', 'checksum_sha256']);
        });
    }
};
