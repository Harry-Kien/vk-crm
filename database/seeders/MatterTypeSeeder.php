<?php

namespace Database\Seeders;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Seeder;

class MatterTypeSeeder extends Seeder
{
    /** @return list<array{code: string, name: string}> */
    public static function types(): array
    {
        return [
            ['code' => 'DD', 'name' => 'Tranh chấp đất đai'],
            ['code' => 'DS', 'name' => 'Tranh chấp dân sự'],
            ['code' => 'HS', 'name' => 'Hình sự'],
            ['code' => 'DN', 'name' => 'Doanh nghiệp'],
            ['code' => 'LD', 'name' => 'Lao động'],
            ['code' => 'HN', 'name' => 'Hôn nhân và gia đình'],
        ];
    }

    public function run(): void
    {
        foreach (self::types() as $index => $data) {
            $type = MatterType::query()->updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'is_active' => true, 'sort_order' => $index + 1],
            );

            foreach (StagePresets::for($type->code) as $order => $stage) {
                $type->stages()->updateOrCreate(
                    ['key' => $stage['key']],
                    [...$stage, 'sort_order' => $order + 1],
                );
            }
        }
    }
}
