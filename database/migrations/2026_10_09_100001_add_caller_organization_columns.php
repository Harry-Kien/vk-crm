<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A1): người gọi thường gọi THAY cho một tổ chức (ông A,
     * giám đốc Công ty X). Bản ghi tiếp nhận cần chỗ cho tổ chức đó để (1) kiểm tra xung đột lúc tiếp nhận
     * chạy trên Công ty X như một bên phía mình, không chỉ trên ông A, và (2) lúc chuyển đổi hồ sơ khách
     * "tổ chức" mang tên công ty, người gọi là người đại diện, mã số thuế là định danh.
     *
     * Cùng khuôn với người liên hệ: tên gõ (`organization_name`, 200 = `clients.name`), tên chuẩn hoá để
     * nguồn dò thứ hai khớp theo tên (`organization_name_normalized`), và CHỈ dấu băm của mã số thuế
     * (`organization_id_number_hash`) — không có cột số thô, như CCCD (R7). Cả ba là dữ liệu của người
     * liên hệ chưa thành khách: nullable, về null khi ẩn danh (`AnonymiseProspect`).
     */
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->string('organization_name', 200)->nullable()->after('contact_role');
            $table->string('organization_name_normalized', 200)->nullable()->after('organization_name');
            $table->string('organization_id_number_hash', 64)->nullable()->after('organization_name_normalized');

            $table->index('organization_name_normalized');
            $table->index('organization_id_number_hash');
        });
    }

    public function down(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->dropIndex(['organization_name_normalized']);
            $table->dropIndex(['organization_id_number_hash']);
            $table->dropColumn(['organization_name', 'organization_name_normalized', 'organization_id_number_hash']);
        });
    }
};
