<?php

namespace Database\Factories;

use App\Enums\ChecklistItemStatus;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterChecklistItem>
 */
class MatterChecklistItemFactory extends Factory
{
    protected $model = MatterChecklistItem::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'name' => 'Giấy tờ '.fake()->unique()->numberBetween(1, 99999),
            'description' => 'Bản sao có chứng thực.',
            'is_required' => true,
            'sort_order' => 1,
            'status' => ChecklistItemStatus::Missing,
        ];
    }

    public function status(ChecklistItemStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
