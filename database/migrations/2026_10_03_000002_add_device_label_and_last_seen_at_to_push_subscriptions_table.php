<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12 R8 — hai cột của dự án trên bảng của gói:
 *
 * - `device_label` `string(100)`: nhãn rút từ User-Agent lúc bật ("iPhone · Safari"), để trang
 *   "Thông báo trên điện thoại" (Task 5) liệt kê máy mà KHÔNG phải hiện endpoint (R8: endpoint không
 *   bao giờ ra màn hình, log, audit hay `outbound_messages`). Theo R8 đây cũng là thứ DUY NHẤT về thiết
 *   bị mà audit `push_device_added`/`push_device_removed` (Task 5) được ghi. Validation phải giới hạn
 *   đúng 100 (MariaDB strict).
 * - `last_seen_at`: theo R8/R9, lượt kiểm `sync=1` lúc tải trang đã đăng nhập (Task 5) cập nhật nó, và
 *   tác vụ dọn dẹp (Task 6) xoá đăng ký cũ hơn 180 ngày.
 *
 * Cả hai `nullable`: `HasPushSubscriptions::updatePushSubscription()` của gói tạo dòng mà không
 * biết hai cột này (`$fillable` của model gói chỉ có `endpoint`, `public_key`, `auth_token`,
 * `content_encoding`, nên chúng phải được ghi bằng `forceFill`; model gói cũng không cast
 * `last_seen_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('device_label', 100)->nullable()->after('content_encoding');
            $table->timestamp('last_seen_at')->nullable()->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['device_label', 'last_seen_at']);
        });
    }
};
