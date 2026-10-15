<?php

namespace Database\Factories;

use App\Enums\FollowUpChannel;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntakeFollowUp> */
class IntakeFollowUpFactory extends Factory
{
    protected $model = IntakeFollowUp::class;

    public function definition(): array
    {
        return [
            'intake_request_id' => IntakeRequest::factory(),
            'channel' => FollowUpChannel::Call,
            'occurred_at' => now(),
            'note' => 'Gọi lại khách tiềm năng',
        ];
    }
}
