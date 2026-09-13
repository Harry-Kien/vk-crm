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
 */
class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@luatvukhang.com'],
            [
                'name' => 'Quản trị hệ thống',
                'password' => 'password',
                'position' => UserPosition::Admin,
                'is_active' => true,
            ],
        );

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
