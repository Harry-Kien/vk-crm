<?php

namespace Database\Factories;

use App\Models\ClientUser;
use App\Models\StageLog;
use App\Models\StageLogView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StageLogView>
 */
class StageLogViewFactory extends Factory
{
    protected $model = StageLogView::class;

    public function definition(): array
    {
        return [
            'stage_log_id' => StageLog::factory(),
            'client_user_id' => ClientUser::factory(),
            'viewed_at' => now(),
            'ip' => fake()->ipv4(),
        ];
    }
}
