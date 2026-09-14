<?php

namespace Database\Factories;

use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterArchive>
 */
class MatterArchiveFactory extends Factory
{
    protected $model = MatterArchive::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'archived_at' => now(),
            'archived_by' => User::factory(),
            'client_access_until' => now()->addDays((int) config('vkcrm.client_access_days', 90))->toDateString(),
            'retention_until' => now()->addYears((int) config('vkcrm.retention_years', 10))->toDateString(),
        ];
    }
}
