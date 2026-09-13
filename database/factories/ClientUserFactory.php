<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClientUser>
 */
class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09########'),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function activated(): static
    {
        return $this->state(fn () => [
            'must_change_password' => false,
            'activated_at' => now(),
        ]);
    }
}
