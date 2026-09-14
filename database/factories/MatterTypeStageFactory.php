<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Models\MatterTypeStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterTypeStage>
 */
class MatterTypeStageFactory extends Factory
{
    protected $model = MatterTypeStage::class;

    public function definition(): array
    {
        $key = fake()->unique()->lexify('stage_????');

        return [
            'matter_type_id' => MatterType::factory(),
            'key' => $key,
            'label' => ucfirst($key),
            'client_label' => ucfirst($key),
            'client_description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_terminal' => false,
            'allowed_next' => [],
            'default_next_update_days' => 14,
        ];
    }
}
