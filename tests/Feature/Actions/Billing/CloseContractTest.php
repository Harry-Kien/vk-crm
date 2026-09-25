<?php

use App\Actions\Billing\CancelContract;
use App\Actions\Billing\CompleteContract;
use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    // Hợp đồng `active` khớp tổng: 60.000.000 + 40.000.000, đợt 2 đã có hạn (2026-10-15).
    $this->contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);
    $this->first = Instalment::factory()->for($this->contract)->create(['sequence' => 1, 'amount' => 60_000_000]);
    $this->second = Instalment::factory()->for($this->contract)->create(['sequence' => 2, 'amount' => 40_000_000, 'due_date' => '2026-10-15']);
    $this->contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-09-01']);
});

const CANCEL_REASON = 'Khách chấm dứt dịch vụ trước hạn.';

// --- Hoàn tất ----------------------------------------------------------------------------------------

it('completes a contract once every instalment is paid, waived or cancelled', function () {
    $this->first->update(['status' => InstalmentStatus::Paid]);
    // Thu đủ nhưng `status` chưa kịp đồng bộ vẫn là đã thu xong — `Instalment::state()` nói vậy.
    Payment::factory()->for($this->second)->create(['amount' => 40_000_000]);

    $completed = app(CompleteContract::class)->handle($this->lead, $this->contract);

    expect($completed->status)->toBe(ContractStatus::Completed)
        ->and($completed->ended_at->toDateString())->toBe('2026-09-25')
        ->and($completed->updated_by)->toBe($this->lead->id)
        ->and(Activity::query()->where('event', 'contract_completed')->sole()->causer_id)->toBe($this->lead->id);
});

it('completes a contract whose last instalment was waived', function () {
    $this->first->update(['status' => InstalmentStatus::Paid]);
    $this->second->update(['status' => InstalmentStatus::Waived, 'waived_reason' => 'Miễn phần còn lại theo thoả thuận với khách.']);

    expect(app(CompleteContract::class)->handle($this->lead, $this->contract)->status)->toBe(ContractStatus::Completed);
});

it('completes a contract with a cancelled instalment left in its schedule', function () {
    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))
        ->create(['status' => ContractStatus::Draft, 'total_amount' => 70_000_000]);
    Instalment::factory()->for($contract)->paid()->create(['sequence' => 1, 'amount' => 70_000_000]);
    Instalment::factory()->for($contract)->cancelled()->create(['sequence' => 2, 'amount' => 30_000_000]);
    $contract->update(['status' => ContractStatus::Active, 'signed_at' => '2026-09-01']);

    expect(app(CompleteContract::class)->handle($this->lead, $contract)->status)->toBe(ContractStatus::Completed);
});

it('refuses to complete a contract with an instalment still owed, even partly', function () {
    $this->first->update(['status' => InstalmentStatus::Paid]);
    Payment::factory()->for($this->second)->create(['amount' => 39_999_999]);

    expect(fn () => app(CompleteContract::class)->handle($this->lead, $this->contract))
        ->toThrow(ContractStatusConflict::class, ContractStatusConflict::hasUnsettled($this->contract, 1)->getMessage())
        ->and($this->contract->refresh()->status)->toBe(ContractStatus::Active);
});

it('counts every unsettled instalment in the refusal', function () {
    expect(fn () => app(CompleteContract::class)->handle($this->lead, $this->contract))
        ->toThrow(ContractStatusConflict::class, ContractStatusConflict::hasUnsettled($this->contract, 2)->getMessage());
});

it('completes only an active contract', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->create(['status' => ContractStatus::Draft]);

    expect(fn () => app(CompleteContract::class)->handle($this->lead, $draft))
        ->toThrow(ContractStatusConflict::class, ContractStatusConflict::notActive($draft)->getMessage());
});

it('refuses to complete for an account that cannot manage the contract', function () {
    $this->first->update(['status' => InstalmentStatus::Paid]);
    $this->second->update(['status' => InstalmentStatus::Paid]);

    expect(fn () => app(CompleteContract::class)->handle(User::factory()->withRole(Role::Lawyer)->create(), $this->contract))
        ->toThrow(AuthorizationException::class);
});

// --- Huỷ ---------------------------------------------------------------------------------------------

it('cancels an active contract with its reason, and leaves the schedule and the money alone', function () {
    $payment = Payment::factory()->for($this->first)->create(['amount' => 10_000_000]);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $cancelled = app(CancelContract::class)->handle($this->lead, $this->contract, CANCEL_REASON);

    expect($cancelled->status)->toBe(ContractStatus::Cancelled)
        ->and($cancelled->ended_at->toDateString())->toBe('2026-09-25')
        ->and($cancelled->ended_reason)->toBe(CANCEL_REASON)
        ->and($cancelled->updated_by)->toBe($this->lead->id)
        ->and($this->first->refresh()->status)->toBe(InstalmentStatus::Pending)
        ->and($this->second->refresh()->status)->toBe(InstalmentStatus::Pending)
        ->and($payment->refresh()->voided_at)->toBeNull()
        ->and(Activity::query()->where('event', 'contract_cancelled')->sole()->causer_id)->toBe($this->lead->id);
});

it('refuses to cancel without a reason of at least 20 characters', function (string $reason) {
    expect(fn () => app(CancelContract::class)->handle($this->lead, $this->contract, $reason))
        ->toThrow(ValidationException::class)
        ->and($this->contract->refresh()->status)->toBe(ContractStatus::Active);
})->with(['empty' => '', '19 vietnamese characters' => 'Lên phúc thẩm vụ án']);

it('cancels only an active contract', function () {
    app(CancelContract::class)->handle($this->lead, $this->contract, CANCEL_REASON);

    expect(fn () => app(CancelContract::class)->handle($this->lead, $this->contract, CANCEL_REASON))
        ->toThrow(ContractStatusConflict::class);
});

it('refuses to cancel for an account that cannot manage the contract', function () {
    expect(fn () => app(CancelContract::class)->handle(User::factory()->withRole(Role::Lawyer)->create(), $this->contract, CANCEL_REASON))
        ->toThrow(AuthorizationException::class)
        ->and($this->contract->refresh()->status)->toBe(ContractStatus::Active);
});
