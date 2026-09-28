<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): DỮ LIỆU MẪU — tài khoản demo
 * (mật khẩu `password`, kể cả `admin@luatvukhang.com`), khách hàng giả, vụ việc giả. `MatterSeeder`
 * đọc `MatterType`/`ChecklistTemplate` đã có (`ReferenceDataSeeder`) nên PHẢI chạy sau seeder đó —
 * `DatabaseSeeder::run()` giữ đúng thứ tự này.
 *
 * `DatabaseSeeder` chỉ gọi seeder này ở môi trường `local`/`testing`, để một lần
 * `migrate:fresh --seed` trên tên miền thật KHÔNG BAO GIỜ tạo tài khoản demo mật khẩu `password`.
 * Văn phòng cần dữ liệu mẫu trên production (demo cho khách hàng xem trước khi dùng thật) vẫn
 * gọi được lớp này trực tiếp: `php artisan db:seed --class=DemoDataSeeder` — cờ `--class` tường
 * minh này đi THẲNG vào seeder được đặt tên, không qua `DatabaseSeeder::run()`, nên không bị chặn
 * bởi kiểm tra môi trường ở đó.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoAccountsSeeder::class,   // giữ hai tài khoản đăng nhập M0
            StaffSeeder::class,
            ClientSeeder::class,
            MatterSeeder::class,
        ]);
    }
}
