<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy" — finding "tài khoản demo và dữ
 * liệu mẫu chạy chung với dữ liệu tham chiếu khi cài production"): DỮ LIỆU THAM CHIẾU — vai trò,
 * quyền, loại vụ việc, giai đoạn, danh mục hồ sơ mẫu — là thứ MỌI bản cài đặt cần, kể cả bản
 * production đầu tiên chưa có nhân sự hay khách hàng nào. Trước seeder này, `DatabaseSeeder` gọi
 * thẳng bảy seeder không điều kiện, trộn lẫn dữ liệu tham chiếu với tài khoản demo/khách/vụ việc
 * giả — không có cách nào tách hai loại đó khi cài lên tên miền thật.
 *
 * `DemoDataSeeder` là seeder còn lại (tài khoản demo, khách hàng, vụ việc); `DatabaseSeeder`
 * quyết định KHI NÀO gọi seeder đó (xem docblock ở đó).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            MatterTypeSeeder::class,
            ChecklistTemplateSeeder::class,
        ]);
    }
}
