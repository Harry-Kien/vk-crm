<?php

namespace Database\Factories;

use App\Models\Deadline;
use App\Models\McpConfirmation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpConfirmation>
 */
class McpConfirmationFactory extends Factory
{
    protected $model = McpConfirmation::class;

    public function definition(): array
    {
        return [
            'jti' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'tool' => 'create_deadline',
            'result_type' => 'deadline',
            'result_id' => Deadline::factory(),
        ];
    }
}
