<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoAccountsSeeder::class,   // giữ hai tài khoản đăng nhập M0
            StaffSeeder::class,
            MatterTypeSeeder::class,
            ChecklistTemplateSeeder::class,
            ClientSeeder::class,
            MatterSeeder::class,
        ]);
    }
}
