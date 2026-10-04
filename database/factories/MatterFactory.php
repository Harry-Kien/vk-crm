<?php

namespace Database\Factories;

use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
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

    /**
     * M11 R9: vụ đã được bật "truy cập qua AI". Không có state thì vụ nhận mặc định của
     * `MCP_MATTER_DEFAULT` (`denied`) qua hook `creating` của `Matter`.
     */
    public function aiAccessAllowed(): static
    {
        return $this->state(fn () => ['ai_access' => MatterAiAccess::Allowed]);
    }

    public function atStage(string $key): static
    {
        return $this->state(fn () => ['stage' => $key]);
    }
}
