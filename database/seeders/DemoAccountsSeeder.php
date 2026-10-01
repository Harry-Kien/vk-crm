<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\UserPosition;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Tài khoản demo tối thiểu để mở được hai panel (M0). M1 mở rộng thành bộ dữ liệu mẫu đầy đủ (SPEC §12)
 * nhưng phải giữ nguyên hai tài khoản đăng nhập dưới đây.
 *
 * M8 Task 2 (R2): panel `admin` bắt buộc 2FA, nên tài khoản `admin@luatvukhang.com` cần một secret
 * mới đăng nhập được. {@see self::DEMO_TWO_FACTOR_SECRET} là secret CỐ ĐỊNH, ghi trong
 * `docs/CAI-DAT.md` mục "Tài khoản dùng thử" — dev thêm nó vào app xác thực (Google Authenticator…)
 * MỘT LẦN, dùng lại được qua mọi lần `migrate:fresh --seed`. **CHỈ gán khi
 * `app()->environment(['local', 'testing'])`** — chạy ép `db:seed --class=DemoDataSeeder` ở môi
 * trường khác (văn phòng demo cho khách trên production, xem docblock `DemoDataSeeder`) để trống
 * secret, đúng luật "R2: không cột nào tắt được" — một secret CÔNG KHAI trong mã nguồn mà vẫn ở
 * production sẽ là một cửa sau 2FA thật.
 */
class DemoAccountsSeeder extends Seeder
{
    /** Base32 hợp lệ (`pragmarx/google2fa` decode được) — KHÔNG phải bí mật thật, chỉ dùng ở local/testing. */
    public const DEMO_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

    /** Email của quản trị viên demo — `vkcrm:preflight` (final review I4) tra theo hằng này. */
    public const ADMIN_EMAIL = 'admin@luatvukhang.com';

    /** Mật khẩu mẫu CÔNG KHAI của mọi tài khoản demo (ghi trong docs/CAI-DAT.md). */
    public const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Quản trị hệ thống',
                'password' => self::DEMO_PASSWORD,
                'position' => UserPosition::Admin,
                'is_active' => true,
                ...(app()->environment(['local', 'testing'])
                    ? ['two_factor_secret' => self::DEMO_TWO_FACTOR_SECRET]
                    : []),
            ],
        );

        $admin->assignRoleFromPosition();

        $client = Client::query()->firstOrCreate(
            ['email' => 'khach1@example.com'],
            [
                'type' => ClientType::Individual,
                'name' => 'Nguyễn Văn An',
                'id_number' => '079090001234',
                'phone' => '0901234567',
                'address' => 'Quận 1, TP. Hồ Chí Minh',
            ],
        );

        ClientUser::query()->updateOrCreate(
            ['email' => 'khach1@example.com'],
            [
                'client_id' => $client->id,
                'name' => 'Nguyễn Văn An',
                'password' => 'password',
                'is_active' => true,
                'must_change_password' => false,
                'activated_at' => now(),
            ],
        );
    }
}
