<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'instalment_id' => Instalment::factory(),
            'amount' => fake()->numberBetween(1, 20) * 1_000_000,
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer,
            'reference' => fake()->bothify('UNC-########'),
            'receipt_document_id' => null,
            'attributed_lawyer_id' => User::factory(),
            'note' => null,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }

    public function voided(string $reason = 'Ghi nhầm khoản thu, đã có dòng đúng thay thế.'): static
    {
        return $this->state(fn () => [
            'voided_at' => now(),
            'voided_by' => User::factory(),
            'void_reason' => $reason,
        ]);
    }
}
