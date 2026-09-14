<?php

namespace Database\Factories;

use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientRequest>
 */
class ClientRequestFactory extends Factory
{
    protected $model = ClientRequest::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'client_user_id' => ClientUser::factory(),
            'subject' => 'Hỏi về '.fake()->words(3, true),
            'content' => fake()->paragraph(),
            'status' => ClientRequestStatus::New,
        ];
    }
}
