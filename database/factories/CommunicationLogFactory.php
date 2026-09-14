<?php

namespace Database\Factories;

use App\Enums\CommunicationType;
use App\Models\CommunicationLog;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationLog>
 */
class CommunicationLogFactory extends Factory
{
    protected $model = CommunicationLog::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'type' => CommunicationType::CallOut,
            'occurred_at' => fake()->dateTimeBetween('-20 days', 'now'),
            'duration_minutes' => fake()->numberBetween(5, 45),
            'counterpart' => fake()->name(),
            'summary' => 'Trao đổi về tiến độ và giấy tờ còn thiếu.',
            'is_visible_to_client' => false,
        ];
    }
}
