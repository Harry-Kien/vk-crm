<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractAmendment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ContractAmendment>
 */
class ContractAmendmentFactory extends Factory
{
    protected $model = ContractAmendment::class;

    public function definition(): array
    {
        $previous = fake()->numberBetween(10, 200) * 1_000_000;

        return [
            'contract_id' => Contract::factory(),
            'sequence' => new Sequence(1, 2, 3, 4, 5),
            'previous_total_amount' => $previous,
            'new_total_amount' => $previous + fake()->numberBetween(5, 50) * 1_000_000,
            'reason' => 'Phát sinh công việc ngoài phạm vi ban đầu do vụ việc lên phúc thẩm.',
            'signed_at' => today()->toDateString(),
            'document_id' => null,
        ];
    }
}
