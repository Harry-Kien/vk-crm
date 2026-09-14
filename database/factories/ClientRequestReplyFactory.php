<?php

namespace Database\Factories;

use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientRequestReply>
 */
class ClientRequestReplyFactory extends Factory
{
    protected $model = ClientRequestReply::class;

    public function definition(): array
    {
        return [
            'request_id' => ClientRequest::factory(),
            'author_type' => (new User)->getMorphClass(),
            'author_id' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
