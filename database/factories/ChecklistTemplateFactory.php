<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\MatterType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistTemplate>
 */
class ChecklistTemplateFactory extends Factory
{
    protected $model = ChecklistTemplate::class;

    public function definition(): array
    {
        return [
            'matter_type_id' => MatterType::factory()->withStages(),
            'name' => 'Danh mục '.fake()->words(2, true),
            'is_active' => true,
        ];
    }

    public function withItems(int $count = 3): static
    {
        return $this->afterCreating(function (ChecklistTemplate $template) use ($count): void {
            for ($i = 1; $i <= $count; $i++) {
                ChecklistTemplateItem::factory()->create([
                    'template_id' => $template->id,
                    'sort_order' => $i,
                    'is_required' => $i === 1,
                ]);
            }
            $template->unsetRelation('items');
        });
    }
}
