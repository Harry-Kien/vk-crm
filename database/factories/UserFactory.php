<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('09########'),
            'position' => UserPosition::Lawyer,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->position(UserPosition::Admin)->withRole(Role::Admin);
    }

    public function position(UserPosition $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            SpatieRole::findOrCreate($role->value, 'web');
            $user->syncRoles([$role->value]);
        });
    }
}
