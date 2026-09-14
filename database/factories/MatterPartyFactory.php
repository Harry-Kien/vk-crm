<?php

namespace Database\Factories;

use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterParty>
 */
class MatterPartyFactory extends Factory
{
    protected $model = MatterParty::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'role' => PartyRole::Related,
            'is_our_client' => false,
            'client_id' => null,
            'name' => fake()->name(),
            'address' => fake()->address(),
        ];
    }

    public function defendant(): static
    {
        return $this->state(fn () => ['role' => PartyRole::Defendant]);
    }

    public function identify(?string $idNumber, ?string $phone): static
    {
        return $this->afterMaking(fn (MatterParty $party) => $party->identify($idNumber, $phone));
    }

    /** Khách hàng của văn phòng đứng vai nguyên đơn, định danh lấy từ hồ sơ khách. */
    public function ourClient(Client $client, PartyRole $role = PartyRole::Plaintiff): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'is_our_client' => true,
            'client_id' => $client->id,
            'name' => $client->name,
            'address' => $client->address,
        ])->afterMaking(fn (MatterParty $party) => $party->identify($client->id_number, $client->phone));
    }
}
