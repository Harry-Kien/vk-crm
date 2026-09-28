<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'user_id' => User::factory(),
            'worked_on' => today()->toDateString(),
            'minutes' => fake()->numberBetween(15, 240),
            'description' => fake()->sentence(8),
            'is_billable' => true,
            'hourly_rate' => null,
            'invoiced_at' => null,
        ];
    }

    public function notBillable(): static
    {
        return $this->state(fn () => ['is_billable' => false]);
    }
}
