<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): trước bản vá này, lớp này gọi
 * thẳng bảy seeder không điều kiện — `migrate:fresh --seed` trên MỘT tên miền thật (production)
 * tạo ra đúng những tài khoản demo mật khẩu `password` (`admin@luatvukhang.com`, `quanly@…`,
 * `luatsu1@…`…) mà `docs/CAI-DAT.md` liệt kê cho máy DEV, cùng khách hàng và vụ việc giả.
 *
 * `ReferenceDataSeeder` (vai trò, quyền, loại vụ việc, giai đoạn, danh mục hồ sơ mẫu) LUÔN chạy:
 * đây là dữ liệu cấu hình mọi bản cài đặt cần, kể cả production. `DemoDataSeeder` (tài khoản demo,
 * khách hàng, vụ việc) chỉ chạy khi `app()->environment(['local', 'testing'])` — tức máy dev và bộ
 * test (`phpunit.xml` ghim `APP_ENV=testing`).
 *
 * Một văn phòng CẦN dữ liệu mẫu trên production (demo cho khách trước khi dùng thật) không bị
 * chặn: `php artisan db:seed --class=DemoDataSeeder` gọi thẳng lớp đó, không qua `run()` ở đây —
 * xem docblock `DemoDataSeeder` cho lý do cờ `--class` đi đường khác.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
