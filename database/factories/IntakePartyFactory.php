<?php

namespace Database\Factories;

use App\Enums\PartyRole;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeParty>
 */
class IntakePartyFactory extends Factory
{
    protected $model = IntakeParty::class;

    public function definition(): array
    {
        return [
            'intake_request_id' => IntakeRequest::factory(),
            'role' => PartyRole::Defendant,
            'name' => fake()->name(),
        ];
    }

    public function identified(?string $idNumber, ?string $phone): static
    {
        return $this->afterMaking(fn (IntakeParty $party) => $party->identify($idNumber, $phone));
    }
}
