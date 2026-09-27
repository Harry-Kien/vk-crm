<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SPEC §15: "time_entries tuy chưa làm ở bản 1.0 nhưng nên tạo sẵn quan hệ trong model
        // Matter". M9 Task 12 dựng ĐÚNG khung — bảng, model, quan hệ, policy đóng kín, factory —
        // KHÔNG Action, KHÔNG màn hình, KHÔNG một con số nào trên dashboard đọc bảng này ở M9.
        // `hourly_rate` (đồng/giờ) và `invoiced_at` có mặt vì chúng là hình dạng CỘT của mô hình
        // tính phí theo giờ (giai đoạn 2), không phải vì bản 1.0 dùng chúng — thứ khó gắn thêm
        // nhất, theo đúng ghi chú M1, là hình dạng bảng, không phải nghiệp vụ đọc nó.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('worked_on');
            $table->unsignedSmallInteger('minutes');
            $table->text('description');
            $table->boolean('is_billable')->default(true);
            $table->unsignedBigInteger('hourly_rate')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('matter_id');
            $table->index(['user_id', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
