<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cùng lỗ hổng mà migration 2026_09_15_000001 đã đóng cho `matter_type_stages.key`, lần này
     * cho `matter_types.code`: MariaDB không có unique một phần, nên `unique('code')` tính cả các
     * dòng đã xoá mềm và một loại vụ việc bị xoá vĩnh viễn giữ mã của nó. Xoá nhầm rồi tạo lại
     * đúng loại ấy với cùng mã — việc bình thường nhất sau một lần gõ sai — trả về lỗi ràng buộc,
     * tức một trang 500, và người dùng không nhìn thấy dòng đang chặn mình để gỡ.
     *
     * "Dùng mã khác đi" không phải lối thoát: mã hồ sơ của SPEC §6.1 nhúng mã loại vụ việc, nên
     * đổi mã là đổi cách đánh số hồ sơ của cả văn phòng vĩnh viễn.
     *
     * Bỏ ràng buộc ở DB; tính duy nhất trong phạm vi các dòng còn dùng chuyển vào `MatterType`
     * (sự kiện `saving`, phủ mọi đường ghi) và `MatterTypeForm` (thông báo thân thiện trên form).
     */
    public function up(): void
    {
        Schema::table('matter_types', function (Blueprint $table) {
            // Giữ một chỉ mục thường trên `code`: không khoá ngoại nào dựa vào nó (khác
            // matter_type_stages), nhưng cả form lẫn chốt chặn ở model đều tra theo cột này ở
            // MỌI lần lưu một loại vụ việc, và bảng này là nơi đọc nhiều hơn ghi rất nhiều.
            $table->index('code', 'matter_types_code_index');
            $table->dropUnique(['code']);
        });
    }

    public function down(): void
    {
        Schema::table('matter_types', function (Blueprint $table) {
            $table->dropIndex('matter_types_code_index');
            $table->unique('code');
        });
    }
};
