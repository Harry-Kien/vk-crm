<?php

namespace Database\Factories;

use App\Enums\DeadlineSeverity;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deadline>
 */
class DeadlineFactory extends Factory
{
    protected $model = Deadline::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'name' => 'Hạn nộp '.fake()->words(2, true),
            'due_date' => now()->addDays(10)->toDateString(),
            'severity' => DeadlineSeverity::Normal,
            'responsible_user_id' => User::factory(),
            'is_completed' => false,
            'is_published' => false,
            'reminders_sent' => [],
        ];
    }

    public function dueIn(int $days): static
    {
        return $this->state(fn () => ['due_date' => now()->addDays($days)->toDateString()]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => DeadlineSeverity::Critical]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true]);
    }
}
