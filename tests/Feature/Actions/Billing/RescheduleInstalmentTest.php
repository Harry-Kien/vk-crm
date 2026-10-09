<?php

use App\Actions\Billing\RescheduleInstalment;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Exceptions\InstalmentNotPayable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * "Dời hạn đợt" ở tầng Action (làn fb, mục B). Hành vi màn hình đo ở
 * `tests/Feature/Filament/BillingTabCorrectionsTest.php`; ở đây những luật nút không tới được.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->subDay()->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);
});

function rescheduleFor(User $actor, Instalment $instalment, ?string $date = null): Instalment
{
    return app(RescheduleInstalment::class)->handle($actor, $instalment, $date ?? today()->addMonth()->toDateString(), 'Khách xin khất một tháng, đã đồng ý.');
}

it('refuses the accountant, who records payments but does not decide payment terms', function () {
    expect(fn () => rescheduleFor($this->accountant, $this->instalment))->toThrow(AuthorizationException::class);

    expect(rescheduleFor($this->lead, $this->instalment)->due_date->toDateString())->toBe(today()->addMonth()->toDateString());
});

it('refuses an instalment that is no longer pending', function () {
    $this->instalment->forceFill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_by' => $this->lead->id, 'waived_at' => now()])->save();

    expect(fn () => rescheduleFor($this->lead, $this->instalment))->toThrow(InstalmentNotPayable::class);
});

it('refuses an instalment of a contract that is no longer active', function () {
    $this->contract->forceFill(['status' => ContractStatus::Cancelled, 'ended_at' => today(), 'ended_reason' => str_repeat('a', 20)])->save();

    expect(fn () => rescheduleFor($this->lead, $this->instalment))->toThrow(InstalmentNotPayable::class);
});

it('refuses a stage instalment that has no due date yet', function () {
    $this->instalment->forceFill(['trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'due_date' => null, 'due_days_after_trigger' => 5])->save();

    expect(fn () => rescheduleFor($this->lead, $this->instalment))->toThrow(ValidationException::class);
});

it('refuses a malformed date instead of turning it into today', function () {
    expect(fn () => rescheduleFor($this->lead, $this->instalment, '2026-02-30'))->toThrow(ValidationException::class)
        ->and($this->instalment->fresh()->due_date->toDateString())->toBe(today()->subDay()->toDateString());
});
