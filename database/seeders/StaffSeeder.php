<?php

namespace Database\Seeders;

use App\Enums\UserPosition;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffSeeder extends Seeder
{
    /** @return list<array{email: string, name: string, position: UserPosition, bar_number?: string}> */
    public static function roster(): array
    {
        return [
            ['email' => 'quanly@luatvukhang.com', 'name' => 'Lê Minh Quản', 'position' => UserPosition::Manager],
            ['email' => 'luatsu1@luatvukhang.com', 'name' => 'Vũ Đức Khang', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1001'],
            ['email' => 'luatsu2@luatvukhang.com', 'name' => 'Phạm Thu Hà', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1002'],
            ['email' => 'luatsu3@luatvukhang.com', 'name' => 'Đỗ Quốc Bảo', 'position' => UserPosition::Lawyer, 'bar_number' => 'LS-1003'],
            ['email' => 'troly1@luatvukhang.com', 'name' => 'Ngô Thị Lan', 'position' => UserPosition::Assistant],
            ['email' => 'troly2@luatvukhang.com', 'name' => 'Bùi Văn Tùng', 'position' => UserPosition::Assistant],
            ['email' => 'ketoan@luatvukhang.com', 'name' => 'Hoàng Kim Ngân', 'position' => UserPosition::Accountant],
        ];
    }

    public function run(): void
    {
        foreach (self::roster() as $index => $person) {
            $user = User::query()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => 'password',
                    'position' => $person['position'],
                    'bar_number' => $person['bar_number'] ?? null,
                    'phone' => '09'.str_pad((string) (10000000 + $index), 8, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ],
            );

            $user->assignRoleFromPosition();
        }
    }
}
