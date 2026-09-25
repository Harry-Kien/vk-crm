<?php

namespace Database\Factories;

use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Models\Contract;
use App\Models\Instalment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<Instalment>
 */
class InstalmentFactory extends Factory
{
    protected $model = Instalment::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            // Không hằng số 1: hai đợt cùng dựng qua `count(2)->for($contract)` phải nhận hai số
            // thứ tự khác nhau để không đụng unique(contract_id, sequence).
            'sequence' => new Sequence(1, 2, 3, 4, 5),
            'name' => 'Đợt thanh toán '.fake()->numberBetween(1, 5),
            'amount' => fake()->numberBetween(5, 50) * 1_000_000,
            'percent_basis' => null,
            'trigger_type' => InstalmentTrigger::DueDate,
            'trigger_stage_key' => null,
            'due_days_after_trigger' => 0,
            'due_date' => today()->addDays(30)->toDateString(),
            'triggered_at' => null,
            'triggered_by_stage_log_id' => null,
            'status' => InstalmentStatus::Pending,
            'waived_reason' => null,
            'waived_by' => null,
            'waived_at' => null,
            'note' => null,
        ];
    }

    public function onSigning(): static
    {
        return $this->state(fn () => [
            'trigger_type' => InstalmentTrigger::OnSigning,
            'due_date' => null,
        ]);
    }

    public function onStage(string $key): static
    {
        return $this->state(fn () => [
            'trigger_type' => InstalmentTrigger::Stage,
            'trigger_stage_key' => $key,
            'due_date' => null,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['due_date' => null]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => InstalmentStatus::Paid]);
    }

    public function waived(string $reason = 'Miễn khoản này theo thoả thuận riêng với khách hàng.'): static
    {
        return $this->state(fn () => [
            'status' => InstalmentStatus::Waived,
            'waived_reason' => $reason,
            'waived_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => InstalmentStatus::Cancelled]);
    }
}
