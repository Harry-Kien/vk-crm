<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC §2 "Giám sát cron": trên shared hosting cron rất hay lặng lẽ ngừng chạy sau khi gia hạn
 * gói hoặc đổi cấu hình PHP, và không ai biết cho tới lúc lỡ một mốc thời hạn. Bảng này giữ
 * `last_schedule_run_at`, và trang chủ admin cảnh báo đỏ khi giá trị đó cũ hơn 30 phút.
 *
 * MỘT DÒNG DUY NHẤT, và điều đó được cưỡng chế bằng một cột hằng có ràng buộc duy nhất chứ không
 * bằng quy ước. Lý do: nếu bảng này có thể có hai dòng thì mọi nơi đọc nó phải quyết định đọc dòng
 * nào, và câu trả lời của hai người đọc sẽ lệch nhau vào đúng lúc cron chết — tức đúng lúc con số
 * này là thứ duy nhất đáng tin. `singleton` luôn mang giá trị 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_health', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('singleton')->default(1)->unique();
            $table->timestamp('last_schedule_run_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->text('last_heartbeat_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_health');
    }
};
