<?php

namespace Database\Factories;

use App\Models\MatterType;
use App\Support\StagePresets;
use Database\Seeders\MatterTypeSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatterType>
 */
class MatterTypeFactory extends Factory
{
    protected $model = MatterType::class;

    /**
     * Mã hai chữ ngẫu nhiên, LOẠI mọi mã `MatterTypeSeeder` sở hữu (mười hai mã, M9 Task 1). Không
     * loại thì mỗi lần tạo có ~1,8% trùng một mã đã seed: hoặc `DuplicateMatterTypeCode` ném ra
     * (test nào seed rồi tạo thêm loại), hoặc `withStages()` lặng lẽ nhận bộ giai đoạn hình sự,
     * doanh nghiệp hay bộ tạm thay vì bộ dân sự mà test tưởng. Test cần một mã cụ thể vẫn truyền
     * `['code' => 'DS']` như trước.
     */
    private function randomCode(): string
    {
        $reserved = array_column(MatterTypeSeeder::types(), 'code');

        do {
            $code = strtoupper(fake()->unique()->lexify('??'));
        } while (in_array($code, $reserved, true));

        return $code;
    }

    public function definition(): array
    {
        return [
            'code' => $this->randomCode(),
            'name' => 'Loại vụ việc '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Tạo kèm bộ giai đoạn theo mã (mặc định dân sự). */
    public function withStages(): static
    {
        return $this->afterCreating(function (MatterType $type): void {
            foreach (StagePresets::for($type->code) as $index => $stage) {
                $type->stages()->create([...$stage, 'sort_order' => $index + 1]);
            }
            $type->unsetRelation('stages');
        });
    }
}
