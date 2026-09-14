<?php

namespace Database\Factories;

use App\Enums\Confidentiality;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'matter_type_id' => MatterType::factory()->withStages(),
            'title' => 'Tranh chấp '.fake()->words(3, true),
            'description_internal' => fake()->paragraph(),
            'summary_for_client' => fake()->sentence(12),
            'lead_lawyer_id' => User::factory(),
            'is_published_to_portal' => true,
            'confidentiality' => Confidentiality::Normal,
        ];
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['confidentiality' => Confidentiality::Restricted]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published_to_portal' => false]);
    }

    public function atStage(string $key): static
    {
        return $this->state(fn () => ['stage' => $key]);
    }
}
