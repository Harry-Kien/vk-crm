<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * M12 R8 — bảng đăng ký thiết bị nhận thông báo đẩy, publish NGUYÊN VĂN từ
 * `laravel-notification-channels/webpush` 13.0.1 (`migrations/create_push_subscriptions_table.php.stub`),
 * chỉ đổi tên tệp để xếp trước migration cột của dự án (`…_000002_…`).
 *
 * - `subscribable` là morph; `enforceMorphMap` (`AppServiceProvider`) lưu bí danh `user` /
 *   `client_user`, không tên lớp.
 * - `endpoint` `varchar(1024)` bộ ký tự `ascii` + UNIQUE: khoá chỉ mục 1024 byte, trong giới hạn
 *   3072 byte của InnoDB (cùng cột ở utf8mb4 là 4096 byte). Đo trên MariaDB thật ở Task 4
 *   (`SHOW CREATE TABLE`/`SHOW INDEX`, báo cáo task). So sánh theo `ascii_general_ci` (không
 *   phân biệt hoa thường): hai endpoint chỉ khác hoa thường là hai chuỗi token ngẫu nhiên trùng nhau
 *   ngoài chữ hoa — không xảy ra trong thực tế, và kẻ biết một endpoint đã gửi đúng nó được rồi.
 *
 * Stub thứ hai của gói (`increase_push_subscriptions_endpoint_length`) KHÔNG publish: nó chỉ nâng
 * bảng của bản gói cũ (`endpoint` 500 ký tự) lên đúng hình dạng mà stub này đã tạo sẵn; ở một cài
 * đặt mới nó chỉ xoá rồi dựng lại chính chỉ mục UNIQUE ấy.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->create($tableName, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)
                ->charset('ascii')
                ->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        /** @var string|null $connection */
        $connection = config('webpush.database_connection');
        /** @var string $tableName */
        $tableName = config('webpush.table_name');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
