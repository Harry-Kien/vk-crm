<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M13 Task 7 — ảnh chụp cuối ngày của số "bây giờ" theo người (R10): đầu ra của các luật N1, N4, N5,
 * N10 lúc 23:50 mỗi ngày, để vẽ xu hướng mà không dựng lại lịch sử (`last_client_update_at` bị ghi đè,
 * dựng lại nó là viết luật quá hạn lần thứ hai). Ghi bởi `App\Actions\Schedule\CapturePerformanceSnapshots`;
 * đọc qua `App\Models\PerformanceSnapshot::scopeVisibleTo()` (R4).
 *
 * - `captured_on` `date`: ngày theo `APP_TIMEZONE` lúc chụp.
 * - `user_id` FK `users`, `restrictOnDelete`: người được chụp. Nhân sự chỉ bị xoá mềm, không bao giờ xoá
 *   cứng (`UserPolicy::forceDelete()`), nên khoá này chỉ chặn một lần xoá cứng ngoài ứng dụng.
 * - `confidentiality` `string(20)`, giá trị `App\Enums\Confidentiality`: mỗi người hai dòng, `normal` và
 *   `restricted`, vì cả hai được tính NGOÀI `listableBy` (tác vụ không có người đăng nhập) và người xem
 *   chỉ được đọc dòng mà R4 chứng minh được. Dòng `normal` LUÔN được ghi, kể cả toàn số 0 — "một ngày bị
 *   lỡ" nghĩa là không có dòng `normal` của ngày đó; dòng `restricted` chỉ khi có ít nhất một số > 0.
 * - Năm số không âm: N1 (`open_lead_matters`), N4 (`stale_matters`), N5 (`overdue_deadlines`), X và Y của
 *   N10 (`checklist_settled`, `checklist_total`).
 * - Unique `(captured_on, user_id, confidentiality)`: chạy lại trong ngày là `upsert`, số mới hơn thắng.
 *   Index `(user_id, captured_on)` cho xu hướng một người. Không xoá mềm (số gộp, không phải hồ sơ),
 *   không blameable (hệ thống ghi); dòng quá `PerformanceSnapshot::KEEP_MONTHS` bị xoá bằng `delete()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('captured_on');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('confidentiality', 20);
            $table->unsignedInteger('open_lead_matters');
            $table->unsignedInteger('stale_matters');
            $table->unsignedInteger('overdue_deadlines');
            $table->unsignedInteger('checklist_settled');
            $table->unsignedInteger('checklist_total');
            $table->timestamps();

            $table->unique(['captured_on', 'user_id', 'confidentiality']);
            $table->index(['user_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_snapshots');
    }
};
