<?php

namespace Database\Factories;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
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
            'channel' => MessageChannel::Email,
            'recipient' => fake()->safeEmail(),
            'template' => 'client.stage_update',
            'payload' => [],
            'status' => MessageStatus::Queued,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => MessageStatus::Sent, 'sent_at' => now()]);
    }
}
