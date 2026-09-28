<?php

namespace Database\Factories;

use App\Enums\BillingModel;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `code` ở đây chỉ là một chuỗi giả DUY NHẤT — sinh thật bằng `App\Support\CodeSequence` là việc
 * của Task 4 (`HD-{YYYY}-{0001}`). Task 2 chỉ cần cột tồn tại, unique, và factory điền được.
 *
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'matter_id' => Matter::factory(),
            'code' => 'HD-TEST-'.fake()->unique()->numerify('######'),
            'status' => ContractStatus::Draft,
            'billing_model' => BillingModel::FixedFee,
            'total_amount' => fake()->numberBetween(10, 200) * 1_000_000,
            'vat_rate_percent' => null,
            'signed_at' => null,
            'activated_by' => null,
            'ended_at' => null,
            'ended_reason' => null,
            'note' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => ContractStatus::Active,
            'signed_at' => today()->subDay()->toDateString(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ContractStatus::Completed,
            'signed_at' => today()->subMonth()->toDateString(),
            'ended_at' => today()->toDateString(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ContractStatus::Cancelled,
            'signed_at' => today()->subMonth()->toDateString(),
            'ended_at' => today()->toDateString(),
            'ended_reason' => 'Khách hàng chấm dứt hợp đồng trước thời hạn theo thoả thuận.',
        ]);
    }

    public function withVat(int $percent): static
    {
        return $this->state(fn () => ['vat_rate_percent' => $percent]);
    }
}
