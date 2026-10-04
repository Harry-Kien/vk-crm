<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): DỮ LIỆU MẪU — tài khoản demo
 * (mật khẩu `password`, kể cả `admin@luatvukhang.com`), khách hàng giả, vụ việc giả, và từ M9 Task
 * 13 hợp đồng, lịch thu, khoản thu giả ({@see BillingSeeder}, chạy sau `MatterSeeder`). `MatterSeeder`
 * đọc `MatterType`/`ChecklistTemplate` đã có (`ReferenceDataSeeder`) nên PHẢI chạy sau seeder đó —
 * `DatabaseSeeder::run()` giữ đúng thứ tự này.
 *
 * `DatabaseSeeder` chỉ gọi seeder này ở môi trường `local`/`testing`, để một lần
 * `migrate:fresh --seed` trên tên miền thật KHÔNG BAO GIỜ tạo tài khoản demo mật khẩu `password`.
 * Văn phòng cần dữ liệu mẫu trên production (demo cho khách hàng xem trước khi dùng thật) vẫn
 * gọi được lớp này trực tiếp: `php artisan db:seed --class=DemoDataSeeder` — cờ `--class` tường
 * minh này đi THẲNG vào seeder được đặt tên, không qua `DatabaseSeeder::run()`, nên không bị chặn
 * bởi kiểm tra môi trường ở đó. Chốt chặn trong MÃ cho đường đó (final review I4): `vkcrm:preflight`
 * ĐỎ khi một tài khoản trong {@see self::staffEmails()} còn mật khẩu mẫu mà `ADMIN_IP_ALLOWLIST`
 * trống — xem `App\Actions\Deployment\RunPreflight::demoAccountsRow()`.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Email của MỌI tài khoản NHÂN SỰ (panel /admin) mà seeder này tạo — quản trị viên demo cộng
     * danh sách của {@see StaffSeeder}. Tài khoản cổng khách demo (`khach1@example.com`) không ở
     * đây: mã OTP của nó gửi tới một hộp thư `example.com` không ai nhận, nên không ai chiếm được.
     *
     * @return list<string>
     */
    public static function staffEmails(): array
    {
        return [
            DemoAccountsSeeder::ADMIN_EMAIL,
            ...array_map(fn (array $person): string => $person['email'], StaffSeeder::roster()),
        ];
    }

    public function run(): void
    {
        $this->call([
            DemoAccountsSeeder::class,   // giữ hai tài khoản đăng nhập M0
            StaffSeeder::class,
            ClientSeeder::class,
            MatterSeeder::class,
            // M9 Task 13: tiền mẫu đọc vụ việc của MatterSeeder nên đứng sau nó — và chỉ ở đây,
            // không bao giờ trong ReferenceDataSeeder (docblock BillingSeeder).
            BillingSeeder::class,
        ]);

        // M10 Task 8 — tiếp nhận: SAU các seeder trên (Đỏ trỏ vào khách hiện hữu, bản chuyển đổi gắn vào
        // một khách đã có và thêm một vụ việc). Sau cả `BillingSeeder` (gộp M10 vào main): vụ mở từ tiếp
        // nhận chưa có hợp đồng, để form "Soạn hợp đồng" của nó hiện phí đã báo làm gợi ý (R3). Gọi riêng
        // để làn khác thêm seeder vào danh sách trên mà không chạm dòng này.
        $this->call(IntakeSeeder::class);
    }
}
