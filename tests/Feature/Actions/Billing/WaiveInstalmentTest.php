<?php

use App\Actions\Billing\WaiveInstalment;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\InstalmentNotPayable;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->subDay()->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);
});

function waiveFor(User $actor, Instalment $instalment, string $reason = 'Miễn theo thoả thuận riêng với khách hàng, đã trao đổi.'): Instalment
{
    return app(WaiveInstalment::class)->handle($actor, $instalment, $reason);
}

// --- Đường chính ---------------------------------------------------------------------------------

it('waives a pending instalment, without changing the contract total', function () {
    $waived = waiveFor($this->lead, $this->instalment, str_repeat('a', 20));

    expect($waived->status)->toBe(InstalmentStatus::Waived)
        ->and($waived->waived_reason)->toBe(str_repeat('a', 20))
        ->and($waived->waived_by)->toBe($this->lead->id)
        ->and($waived->waived_at)->not->toBeNull()
        ->and($waived->state())->toBe(InstalmentState::Waived)
        ->and($this->contract->fresh()->total_amount)->toBe(10_000_000);
});

// --- Lý do -------------------------------------------------------------------------------------

it('refuses to waive without a reason', function () {
    expect(fn () => waiveFor($this->lead, $this->instalment, ''))
        ->toThrow(ValidationException::class)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

it('refuses a reason shorter than 20 characters', function () {
    expect(fn () => waiveFor($this->lead, $this->instalment, str_repeat('a', 19)))
        ->toThrow(ValidationException::class);
});

it('accepts a reason of exactly 20 characters', function () {
    expect(waiveFor($this->lead, $this->instalment, str_repeat('a', 20))->status)->toBe(InstalmentStatus::Waived);
});

// --- Trạng thái đợt và hợp đồng phải cho phép -------------------------------------------------------

it('refuses to waive an instalment that is not pending', function () {
    Payment::factory()->for($this->instalment)->create(['amount' => 10_000_000]);
    $this->instalment->fill(['status' => InstalmentStatus::Paid])->save();

    expect(fn () => waiveFor($this->lead, $this->instalment))
        ->toThrow(InstalmentNotPayable::class)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Paid);
});

it('refuses to waive an instalment of a contract that is no longer active', function () {
    $this->contract->fill(['status' => ContractStatus::Cancelled, 'ended_at' => today(), 'ended_reason' => str_repeat('a', 20)])->save();

    expect(fn () => waiveFor($this->lead, $this->instalment))
        ->toThrow(InstalmentNotPayable::class)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

// --- Actor tường minh ----------------------------------------------------------------------------

it('records the actor passed in as waiver, not whoever holds the session', function () {
    $this->actingAs($this->admin, 'web');

    $waived = waiveFor($this->lead, $this->instalment);

    expect($waived->waived_by)->toBe($this->lead->id)
        ->and($waived->updated_by)->toBe($this->lead->id);

    $audit = Activity::query()->where('event', 'instalment_waived')->sole();
    expect($audit->causer_id)->toBe($this->lead->id);
});

// --- Quyền: contract.manage, không phải payment.record --------------------------------------------

it('refuses the accountant, who records payments but does not manage contract terms', function () {
    expect(fn () => waiveFor($this->accountant, $this->instalment))->toThrow(AuthorizationException::class)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending);

    // Cặp dương: luật sư phụ trách (có `contract.manage`) miễn được, trên CÙNG đợt.
    expect(waiveFor($this->lead, $this->instalment)->status)->toBe(InstalmentStatus::Waived);
});

it('lets the manager waive, since manager holds contract.manage', function () {
    expect(waiveFor($this->manager, $this->instalment)->status)->toBe(InstalmentStatus::Waived);
});
