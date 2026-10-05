<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M10 (R1, R7): các bên đối lập người gọi khai lúc tiếp nhận. BẢNG CON, không phải JSON — dò
        // được, index được — cùng khuôn cột với `matter_parties` (SPEC §4.16) nhưng CỐ Ý không có
        // cột SĐT thô, không cột CCCD thô, không địa chỉ, không ghi chú: bên thứ ba không thể đồng ý
        // (R7), nên chỉ giữ thứ kiểm tra xung đột cần. Tên/`name_normalized` về null khi ẩn danh.
        Schema::create('intake_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_request_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('name', 200)->nullable();
            $table->string('name_normalized', 200)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('id_number_hash', 64)->nullable();
            $table->timestamps();

            $table->index('name_normalized');
            $table->index('phone_normalized');
            $table->index('id_number_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_parties');
    }
};
