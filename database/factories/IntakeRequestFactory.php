<?php

namespace Database\Factories;

use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeRequest>
 */
class IntakeRequestFactory extends Factory
{
    protected $model = IntakeRequest::class;

    /**
     * Không điền `contact_phone_normalized` / `contact_id_number_hash`: hai cột đó chỉ `identify()`
     * ghi được — dùng {@see self::identified()} khi test cần dò theo SĐT hoặc CCCD.
     */
    public function definition(): array
    {
        return [
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->numerify('09########'),
            'source' => IntakeSource::Phone,
            'status' => IntakeStatus::New,
            'received_at' => now(),
        ];
    }

    public function status(IntakeStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function identified(?string $idNumber, ?string $phone): static
    {
        return $this->afterMaking(fn (IntakeRequest $intake) => $intake->identify($idNumber, $phone));
    }
}
