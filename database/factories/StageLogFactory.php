<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\StageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageLog>
 */
class StageLogFactory extends Factory
{
    protected $model = StageLog::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'from_stage' => null,
            'to_stage' => null,
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'internal_note' => 'Ghi chú nội bộ: '.fake()->sentence(8),
            'public_content' => 'Văn phòng đã hoàn tất bước này và sẽ tiếp tục theo dõi vụ việc của anh/chị.',
            'next_step' => 'Chờ toà án phản hồi.',
            'client_action' => null,
            'expected_next_update_at' => now()->addDays(14)->toDateString(),
            'is_published' => true,
            'published_at' => now(),
            'notified_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true, 'published_at' => now()]);
    }

    public function internalOnly(): static
    {
        return $this->state(fn () => ['is_published' => false, 'published_at' => null, 'public_content' => null]);
    }

    public function transition(string $from, string $to): static
    {
        return $this->state(fn () => ['from_stage' => $from, 'to_stage' => $to]);
    }
}
