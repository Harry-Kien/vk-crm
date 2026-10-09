<?php

use App\Actions\Billing\UnwaiveInstalment;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\InstalmentNotPayable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\ScheduleTotal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Bỏ miễn" ở tầng Action (làn fb, mục A2). Hành vi màn hình đo ở
 * `tests/Feature/Filament/BillingTabCorrectionsTest.php`; ở đây chỉ những luật mà nút không tới
 * được (nút chỉ hiện trên đợt `waived`, và mọi người thấy tab đều có `contract.manage`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->subDay()->toDateString(),
        'status' => InstalmentStatus::Waived,
        'waived_reason' => 'Miễn theo thoả thuận riêng với khách hàng.',
        'waived_by' => $this->lead->id,
        'waived_at' => now(),
    ]);
});

it('refuses to unwaive an instalment that is not waived', function () {
    $this->instalment->forceFill(['status' => InstalmentStatus::Pending, 'waived_reason' => null, 'waived_by' => null, 'waived_at' => null])->save();

    expect(fn () => app(UnwaiveInstalment::class)->handle($this->lead, $this->instalment, str_repeat('a', 20)))
        ->toThrow(InstalmentNotPayable::class, __('billing_corrections.errors.instalment_not_waived', [
            'name' => $this->instalment->name,
            'status' => InstalmentStatus::Pending->label(),
        ]));
});

it('refuses the accountant, who records payments but does not manage contract terms', function () {
    expect(fn () => app(UnwaiveInstalment::class)->handle($this->accountant, $this->instalment, str_repeat('a', 20)))
        ->toThrow(AuthorizationException::class)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Waived);

    expect(app(UnwaiveInstalment::class)->handle($this->lead, $this->instalment, str_repeat('a', 20))->status)
        ->toBe(InstalmentStatus::Pending);
});

it('keeps the contract total and the schedule balance unchanged', function () {
    app(UnwaiveInstalment::class)->handle($this->lead, $this->instalment, str_repeat('a', 20));

    expect($this->contract->fresh()->total_amount)->toBe(10_000_000)
        ->and((int) ScheduleTotal::counted()->where('contract_id', $this->contract->id)->sum('amount'))->toBe(10_000_000);
});
