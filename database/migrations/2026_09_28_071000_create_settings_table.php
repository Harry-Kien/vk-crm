<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 10 — bảng cấu hình khoá–giá trị CHUNG của hệ thống (không riêng cho văn phòng).
 *
 * Người dùng đầu tiên: "Thông tin văn phòng" (khoá `office.<trường>`, đọc qua
 * `App\Support\OfficeProfile`). Người dùng thứ hai đã định sẵn: M11 lưu `mcp.enabled` và
 * `mcp.write_enabled` vào CHÍNH bảng này (kế hoạch M11, R2). Mọi lần ghi đi qua
 * `App\Actions\Settings\WriteSettings`.
 *
 * - `key` `string(100)`, unique: khoá do MÃ đặt (có tiền tố theo tính năng), không do người gõ.
 *   `WriteSettings` từ chối khoá dài hơn trước khi chạm DB: lần chèn của nó là `INSERT IGNORE`,
 *   thứ CẮT khoá dài kèm một cảnh báo thay vì ném lỗi, kể cả ở chế độ strict.
 * - `value` `text` NULL: NULL nghĩa là "chưa đặt" — người đọc rơi về giá trị mặc định của mình
 *   (với văn phòng: `config('vkcrm.brand.*')`). Giá trị là chuỗi; công tắc của M11 lưu `'1'`/`'0'`.
 *   Mỗi trường tự đặt giới hạn ký tự của nó, kiểm ở form và ở Action (`OfficeProfile::FIELDS`), và
 *   mọi giới hạn đó nhỏ hơn rất xa sức chứa của `text` (65.535 byte), nên MariaDB strict không bao
 *   giờ là chốt chặn cuối.
 * - `updated_by` FK `users` NULL: người lưu lần cuối. Dấu vết đầy đủ (ai đổi trường nào, lúc nào)
 *   nằm ở nhật ký hệ thống do Action của từng tính năng ghi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
