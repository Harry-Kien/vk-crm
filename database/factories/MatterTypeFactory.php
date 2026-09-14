<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Support\StagePresets;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterType>
 */
class MatterTypeFactory extends Factory
{
    protected $model = MatterType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => 'Loại vụ việc '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Tạo kèm bộ giai đoạn theo mã (mặc định dân sự). */
    public function withStages(): static
    {
        return $this->afterCreating(function (MatterType $type): void {
            foreach (StagePresets::for($type->code) as $index => $stage) {
                $type->stages()->create([...$stage, 'sort_order' => $index + 1]);
            }
            $type->unsetRelation('stages');
        });
    }
}
