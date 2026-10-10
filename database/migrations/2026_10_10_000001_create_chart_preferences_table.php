<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dạng biểu đồ mà TỪNG nhân sự chọn cho TỪNG biểu đồ trên bảng điều khiển (cột / đường / tròn).
 * Một dòng cho mỗi cặp (người, biểu đồ); `widget` là tên lớp widget không kèm namespace
 * (`class_basename`), `chart_kind` là giá trị của `App\Enums\ChartKind`.
 *
 * Bảng riêng chứ không phải một cột JSON trên `users`: đổi dạng biểu đồ là một thao tác xem, không được
 * làm bẩn nhật ký thay đổi tài khoản, và không được chạm vào dòng `users` mà các lớp bảo vệ phiên đọc.
 * Xoá nhân sự (xoá mềm) không xoá dòng ở đây; xoá hẳn một dòng `users` thì các lựa chọn đi theo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget', 100);
            $table->string('chart_kind', 20);
            $table->timestamps();

            $table->unique(['user_id', 'widget']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_preferences');
    }
};
