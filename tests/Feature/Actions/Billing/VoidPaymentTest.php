<?php

use App\Actions\Billing\VoidPayment;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\Role;
use App\Exceptions\PaymentAlreadyVoided;
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

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->contract = Contract::factory()->for($this->matter)->active()->create(['total_amount' => 10_000_000]);
    $this->instalment = Instalment::factory()->for($this->contract)->create([
        'amount' => 10_000_000,
        'due_date' => today()->subDay()->toDateString(),
        'status' => InstalmentStatus::Paid,
    ]);
    $this->payment = Payment::factory()->for($this->instalment)->create([
        'amount' => 10_000_000,
        'attributed_lawyer_id' => $this->lead->id,
    ]);
});

function voidFor(User $actor, Payment $payment, string $reason = 'Ghi nhầm khoản thu, khách chưa thực sự chuyển khoản.'): Payment
{
    return app(VoidPayment::class)->handle($actor, $payment, $reason);
}

// --- Đường chính: huỷ làm tụt dưới đủ -------------------------------------------------------------

it('reopens the instalment as pending, and overdue, once voiding drops the collected total below its amount', function () {
    $voided = voidFor($this->accountant, $this->payment);

    expect($voided->voided_at)->not->toBeNull()
        ->and($voided->voided_by)->toBe($this->accountant->id)
        ->and($this->instalment->fresh()->status)->toBe(InstalmentStatus::Pending)
        ->and($this->instalment->fresh()->state())->toBe(InstalmentState::Overdue);
});

it('excludes the voided payment from the collected total from then on', function () {
    voidFor($this->accountant, $this->payment);

    // (int): SUM() của một tập rỗng là NULL qua MariaDB/PDO, khác SQLite trả thẳng 0.
    expect((int) Payment::query()->where('instalment_id', $this->instalment->id)->whereNull('voided_at')->sum('amount'))->toBe(0);
});

/** Cặp dương của guard `status === Paid`: một đợt đã MIỄN không bị hạ lại về `pending` khi khoản thu cũ bị huỷ. */
it('does not revert a waived instalment back to pending when an old payment on it is voided', function () {
    $this->instalment->fill([
        'status' => InstalmentStatus::Waived,
        'waived_reason' => str_repeat('a', 20),
        'waived_by' => $this->lead->id,
        'waived_at' => now(),
    ])->save();

    voidFor($this->accountant, $this->payment);

    expect($this->instalment->fresh()->status)->toBe(InstalmentStatus::Waived);
});

// --- Lý do -------------------------------------------------------------------------------------

it('refuses to void without a reason', function () {
    expect(fn () => voidFor($this->accountant, $this->payment, ''))
        ->toThrow(ValidationException::class)
        ->and($this->payment->fresh()->voided_at)->toBeNull();
});

it('refuses a reason shorter than 20 characters', function () {
    expect(fn () => voidFor($this->accountant, $this->payment, str_repeat('a', 19)))
        ->toThrow(ValidationException::class)
        ->and($this->payment->fresh()->voided_at)->toBeNull();
});

it('accepts a reason of exactly 20 characters', function () {
    expect(voidFor($this->accountant, $this->payment, str_repeat('a', 20))->voided_at)->not->toBeNull();
});

// --- Đã huỷ từ trước, và huỷ hai lần -------------------------------------------------------------

it('refuses to void a payment that has already been voided', function () {
    voidFor($this->accountant, $this->payment);

    expect(fn () => voidFor($this->accountant, $this->payment))->toThrow(PaymentAlreadyVoided::class);
});

// --- Actor tường minh ----------------------------------------------------------------------------

it('records the actor passed in as the voider, not whoever holds the session', function () {
    $this->actingAs($this->manager, 'web');

    $voided = voidFor($this->accountant, $this->payment);

    expect($voided->voided_by)->toBe($this->accountant->id)
        ->and($voided->updated_by)->toBe($this->accountant->id);

    $audit = Activity::query()->where('event', 'payment_voided')->sole();
    expect($audit->causer_id)->toBe($this->accountant->id);
});

// --- Quyền -------------------------------------------------------------------------------------

it('refuses a manager, who can only view the money, not void it', function () {
    expect(fn () => voidFor($this->manager, $this->payment))->toThrow(AuthorizationException::class)
        ->and($this->payment->fresh()->voided_at)->toBeNull();

    expect(voidFor($this->accountant, $this->payment)->voided_at)->not->toBeNull();
});
