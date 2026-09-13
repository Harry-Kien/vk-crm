<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'type' => ClientType::Individual,
            'name' => fake()->name(),
            'id_number' => fake()->numerify('0###########'),
            'phone' => fake()->numerify('09########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn () => [
            'type' => ClientType::Organization,
            'name' => fake()->company(),
            'representative_name' => fake()->name(),
        ]);
    }
}
