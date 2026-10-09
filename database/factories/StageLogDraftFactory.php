<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StageLogDraft>
 */
class StageLogDraftFactory extends Factory
{
    protected $model = StageLogDraft::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'created_by' => User::factory(),
            'public_content' => fake()->sentence(10),
            'next_step' => fake()->sentence(6),
            'client_action' => null,
            'expected_next_update_at' => null,
            'internal_note' => null,
            'idempotency_key' => Str::lower(Str::random(24)),
        ];
    }

    /** Đã được một người trong `/admin` dùng để gửi dòng tiến độ `$log`. */
    public function usedFor(StageLog $log): static
    {
        return $this->state(fn () => ['used_stage_log_id' => $log->id]);
    }

    public function discarded(User $by, string $reason): static
    {
        return $this->state(fn () => [
            'discarded_at' => now(),
            'discarded_by' => $by->id,
            'discard_reason' => $reason,
        ]);
    }
}
