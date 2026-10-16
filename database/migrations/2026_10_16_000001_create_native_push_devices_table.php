<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M15 Task 2 — sổ máy iPhone (ứng dụng nội bộ) nhận thông báo qua APNs, của NHÂN SỰ. Tách khỏi
 * `push_subscriptions` (Web Push của trình duyệt): token APNs là một chuỗi hex, không có khoá
 * `p256dh`/`auth`, và chỉ nhân sự có ứng dụng.
 *
 * `token` unique: một token là MỘT dòng; người đăng ký sau lấy dòng từ người trước (máy dùng chung)
 * bằng một câu upsert. Cột `ascii` (token chỉ gồm `[0-9a-f]`, tối đa 200) để chỉ mục unique nằm gọn
 * trong giới hạn khoá của MariaDB. Xoá nhân sự thật thì dòng đi theo (`cascade`); xoá MỀM thì
 * `ForgetPushDevice::all()` gỡ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 200)->charset('ascii')->unique();
            $table->string('environment', 16);
            $table->string('device_label', 120);
            $table->string('app_version', 20);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_push_devices');
    }
};
