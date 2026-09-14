<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplateItem>
 */
class ChecklistTemplateItemFactory extends Factory
{
    protected $model = ChecklistTemplateItem::class;

    public function definition(): array
    {
        return [
            'template_id' => ChecklistTemplate::factory(),
            'name' => 'Giấy tờ '.fake()->unique()->numberBetween(1, 99999),
            'description' => 'Bản sao có chứng thực, còn hiệu lực.',
            'is_required' => false,
            'sort_order' => 1,
        ];
    }
}
