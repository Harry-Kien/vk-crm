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
            // Mặc định bằng `created_at` giả lập: mọi luồng do các Action thật tạo ra đều có giá
            // trị này (Task 18, REQ-2), nên một fixture không đi qua Action mà bỏ trống cột sẽ
            // xếp hạng theo `null` — khác hẳn dữ liệu thật và làm test sắp xếp không đo đúng thứ
            // nó cần đo.
            'last_activity_at' => now(),
        ];
    }
}
