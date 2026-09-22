<?php

namespace Database\Factories;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundMessage>
 */
class OutboundMessageFactory extends Factory
{
    protected $model = OutboundMessage::class;

    public function definition(): array
    {
        return [
            'channel' => OutboundChannel::Email,
            'recipient' => fake()->safeEmail(),
            'template' => 'client.stage_update',
            'payload' => [],
            'status' => OutboundStatus::Queued,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => OutboundStatus::Sent, 'sent_at' => now()]);
    }
}
