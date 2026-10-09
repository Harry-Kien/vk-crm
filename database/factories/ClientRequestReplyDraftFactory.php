<?php

namespace Database\Factories;

use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClientRequestReplyDraft>
 */
class ClientRequestReplyDraftFactory extends Factory
{
    protected $model = ClientRequestReplyDraft::class;

    public function definition(): array
    {
        return [
            'request_id' => ClientRequest::factory(),
            'created_by' => User::factory(),
            'content' => fake()->paragraph(),
            'idempotency_key' => Str::lower(Str::random(24)),
        ];
    }

    /** Đã được một người trong `/admin` dùng để gửi câu trả lời `$reply`. */
    public function usedFor(ClientRequestReply $reply): static
    {
        return $this->state(fn () => ['used_reply_id' => $reply->id]);
    }

    public function discarded(User $by, string $reason): static
    {
        return $this->state(fn () => [
            'discarded_at' => now(),
            'discarded_by' => $by->id,
            'discard_reason' => $reason,
        ]);
    }
}
