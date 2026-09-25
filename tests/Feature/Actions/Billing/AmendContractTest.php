<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\AmendContract;
use App\Actions\Billing\DraftContract;
use App\Enums\ContractStatus;
use App\Enums\DocumentGroup;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Exceptions\ContractNotAmendable;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\ScheduleTotal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/** Lý do 20 ký tự tiếng Việt có dấu — đúng ngưỡng. `strlen` của nó lớn hơn nhiều. */
const AMEND_REASON = 'Lên phúc thẩm vụ án.';

beforeEach(function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    $draft = app(DraftContract::class)->handle($this->lead, $this->matter, ['total_amount' => 100_000_000], [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => 30_000_000, 'trigger_type' => 'on_signing'],
        ['name' => 'Thanh toán khi nộp đơn khởi kiện', 'amount' => 40_000_000, 'trigger_type' => 'stage', 'trigger_stage_key' => 'filed'],
        ['name' => 'Thanh toán đợt cuối', 'amount' => 30_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-03-31'],
    ]);
    $this->contract = app(ActivateContract::class)->handle($this->lead, $draft, '2026-09-20');
    [$this->first, $this->second, $this->third] = $this->contract->instalments()->get()->all();
});

/** @param  list<array<string, mixed>>  $changes */
function amend(User $actor, Contract $contract, int $newTotal, array $changes, string $reason = AMEND_REASON, string $signedAt = '2026-09-24', ?Document $document = null): ContractAmendment
{
    return app(AmendContract::class)->handle($actor, $contract, $newTotal, $changes, $reason, $signedAt, $document);
}

function amendErrors(callable $amend): array
{
    try {
        $amend();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/** Không có gì của hợp đồng mẫu bị đổi: giá trị 100.000.000, ba đợt nguyên vẹn, không phụ lục. */
function expectContractUntouched(Contract $contract): void
{
    $contract->refresh();

    expect($contract->total_amount)->toBe(100_000_000)
        ->and($contract->instalments()->pluck('amount')->all())->toBe([30_000_000, 40_000_000, 30_000_000])
        ->and($contract->instalments()->pluck('status')->all())->toBe([InstalmentStatus::Pending, InstalmentStatus::Pending, InstalmentStatus::Pending])
        ->and(ContractAmendment::count())->toBe(0);
}

// --- Đường chính -------------------------------------------------------------------------------

it('raises the total and the schedule together, and keeps the old total on the amendment', function () {
    $amendment = amend($this->lead, $this->contract, 120_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 50_000_000],
    ]);

    expect($amendment->sequence)->toBe(1)
        ->and($amendment->previous_total_amount)->toBe(100_000_000)
        ->and($amendment->new_total_amount)->toBe(120_000_000)
        ->and($amendment->reason)->toBe(AMEND_REASON)
        ->and($amendment->signed_at->toDateString())->toBe('2026-09-24')
        ->and($amendment->created_by)->toBe($this->lead->id)
        ->and($this->contract->refresh()->total_amount)->toBe(120_000_000)
        ->and($this->contract->updated_by)->toBe($this->lead->id)
        ->and($this->third->refresh()->amount)->toBe(50_000_000)
        ->and($this->third->updated_by)->toBe($this->lead->id)
        ->and(ScheduleTotal::of($this->contract->id))->toBe(120_000_000);
});

it('reads the previous total from the locked row, not from the object the caller holds', function () {
    $stale = Contract::query()->findOrFail($this->contract->id);
    amend($this->lead, $this->contract, 120_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 50_000_000],
    ]);

    $second = amend($this->lead, $stale, 130_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 60_000_000],
    ]);

    expect($second->sequence)->toBe(2)
        ->and($second->previous_total_amount)->toBe(120_000_000)
        ->and($second->new_total_amount)->toBe(130_000_000);
});

it('cancels an instalment through an amendment, and the new total matches without it', function () {
    amend($this->lead, $this->contract, 70_000_000, [
        ['action' => 'cancel', 'instalment_id' => $this->third->id],
    ]);

    expect($this->third->refresh()->status)->toBe(InstalmentStatus::Cancelled)
        ->and($this->third->amount)->toBe(30_000_000)
        ->and($this->contract->refresh()->total_amount)->toBe(70_000_000)
        ->and(ScheduleTotal::of($this->contract->id))->toBe(70_000_000)
        ->and(ScheduleTotal::mismatchedActiveContracts())->toBeEmpty();
});

it('adds an instalment at the end of the schedule, an on-signing one falling due from the amendment date', function () {
    amend($this->lead, $this->contract, 150_000_000, [
        ['action' => 'add', 'name' => 'Phí phúc thẩm', 'amount' => 20_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-06-30'],
        ['action' => 'add', 'name' => 'Tạm ứng phúc thẩm', 'amount' => 30_000_000, 'trigger_type' => 'on_signing', 'due_days_after_trigger' => 7, 'percent_basis' => '20'],
    ]);

    [, , , $byDate, $onSigning] = $this->contract->instalments()->get()->all();

    expect($byDate->sequence)->toBe(4)
        ->and($byDate->due_date->toDateString())->toBe('2027-06-30')
        ->and($byDate->status)->toBe(InstalmentStatus::Pending)
        ->and($byDate->created_by)->toBe($this->lead->id)
        ->and($onSigning->sequence)->toBe(5)
        ->and($onSigning->trigger_type)->toBe(InstalmentTrigger::OnSigning)
        ->and($onSigning->due_date->toDateString())->toBe('2026-10-01')
        ->and($onSigning->triggered_at?->toDateTimeString())->toBe('2026-09-25 10:00:00')
        ->and($onSigning->percent_basis)->toBe('20.00');
});

it('lets a stage-triggered instalment added by amendment wait for its stage', function () {
    amend($this->lead, $this->contract, 110_000_000, [
        ['action' => 'add', 'name' => 'Thanh toán khi toà thụ lý', 'amount' => 10_000_000, 'trigger_type' => 'stage', 'trigger_stage_key' => 'court_accepted'],
    ]);

    $added = $this->contract->instalments()->get()->last();

    expect($added->trigger_stage_key)->toBe('court_accepted')
        ->and($added->due_date)->toBeNull()
        ->and($added->triggered_at)->toBeNull();
});

it('reshuffles the schedule without changing the total', function () {
    $amendment = amend($this->lead, $this->contract, 100_000_000, [
        ['action' => 'cancel', 'instalment_id' => $this->third->id],
        ['action' => 'add', 'name' => 'Đợt cuối chia đôi — phần một', 'amount' => 15_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-03-31'],
        ['action' => 'add', 'name' => 'Đợt cuối chia đôi — phần hai', 'amount' => 15_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-06-30'],
    ]);

    expect($amendment->previous_total_amount)->toBe(100_000_000)
        ->and($amendment->new_total_amount)->toBe(100_000_000)
        ->and($this->contract->instalments()->count())->toBe(5);
});

it('clears the percent basis of an instalment whose amount changes, unless a new one is given', function () {
    $this->third->forceFill(['percent_basis' => '30'])->saveQuietly();

    amend($this->lead, $this->contract, 110_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 35_000_000],
        ['action' => 'update', 'instalment_id' => $this->second->id, 'amount' => 45_000_000, 'percent_basis' => '40.9'],
    ]);

    expect($this->third->refresh()->percent_basis)->toBeNull()
        ->and($this->second->refresh()->percent_basis)->toBe('40.90')
        ->and($this->second->amount)->toBe(45_000_000);
});

it('records the amendment in the audit log against the actor passed in', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $amendment = amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]]);

    $audit = Activity::query()->where('event', 'contract_amended')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_id)->toBe($this->contract->id)
        ->and($audit->properties['amendment_id'])->toBe($amendment->id)
        ->and($audit->properties['previous_total_amount'])->toBe(100_000_000)
        ->and($audit->properties['new_total_amount'])->toBe(70_000_000)
        ->and($audit->properties['cancelled'])->toBe([$this->third->id]);
});

it('puts the per-write guard back once the amendment is done', function () {
    amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]]);

    expect(ScheduleTotal::isBeingAmended($this->contract->id))->toBeFalse()
        ->and(fn () => $this->first->refresh()->update(['amount' => 1]))->toThrow(ContractTotalMismatch::class);
});

// --- Tầng 3: giá trị và lịch thu phải đổi cùng nhau ----------------------------------------------

it('fails when the total changes but the schedule does not, and writes nothing', function () {
    expect(fn () => amend($this->lead, $this->contract, 120_000_000, []))
        ->toThrow(ContractTotalMismatch::class, __('billing.errors.total_mismatch_on_amendment', [
            'code' => $this->contract->code,
            'total' => Money::format(120_000_000),
            'schedule' => Money::format(100_000_000),
            'difference' => Money::format(20_000_000),
        ]));

    expectContractUntouched($this->contract);
});

it('fails when the schedule changes but the total does not, and writes nothing', function () {
    expect(fn () => amend($this->lead, $this->contract, 100_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 30_000_001],
    ]))->toThrow(ContractTotalMismatch::class);

    expectContractUntouched($this->contract);
});

it('fails when an instalment is cancelled but the total is kept, and writes nothing', function () {
    expect(fn () => amend($this->lead, $this->contract, 100_000_000, [
        ['action' => 'cancel', 'instalment_id' => $this->third->id],
    ]))->toThrow(ContractTotalMismatch::class);

    expectContractUntouched($this->contract);
});

// --- Trạng thái và quyền ---------------------------------------------------------------------------

it('amends only an active contract', function (string $state) {
    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->{$state}()->create();

    expect(fn () => amend($this->lead, $contract, 1_000_000, []))
        ->toThrow(ContractNotAmendable::class, ContractNotAmendable::make($contract)->getMessage());
})->with(['completed', 'cancelled']);

it('does not amend a draft', function () {
    $draft = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]))->create(['status' => ContractStatus::Draft]);

    expect(fn () => amend($this->lead, $draft, 1_000_000, []))->toThrow(ContractNotAmendable::class);
});

it('refuses an account that cannot manage the contract', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => amend($outsider, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]]))
        ->toThrow(AuthorizationException::class);

    expectContractUntouched($this->contract);
});

// --- Lý do -------------------------------------------------------------------------------------------

it('refuses an amendment without a reason', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], '')))
        ->toBe(['reason' => [__('billing.validation.reason_too_short', ['min' => 20])]]);

    expectContractUntouched($this->contract);
});

it('refuses a 19-character vietnamese reason, counted in characters rather than bytes', function () {
    $reason = 'Lên phúc thẩm vụ án';

    expect(mb_strlen($reason))->toBe(19)
        ->and(strlen($reason))->toBeGreaterThan(20)
        ->and(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], $reason)))
        ->toHaveKey('reason');
});

it('accepts a 20-character vietnamese reason', function () {
    expect(mb_strlen(AMEND_REASON))->toBe(20)
        ->and(amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON)->reason)
        ->toBe(AMEND_REASON);
});

it('does not count surrounding whitespace toward the reason', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], '   Lên phúc thẩm vụ án   ')))
        ->toHaveKey('reason');
});

// --- Ngày ký, giá trị, bản scan, "không đổi gì" ------------------------------------------------------

it('refuses an amendment signed in the future', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON, '2026-09-26')))
        ->toBe(['signed_at' => [__('billing.validation.date_future')]]);
});

it('refuses an amendment signed before the contract itself', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON, '2026-09-19')))
        ->toBe(['signed_at' => [__('billing.validation.amendment_signed_before_contract', ['date' => '20/09/2026'])]]);
});

it('accepts an amendment signed on the day the contract was signed', function () {
    expect(amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON, '2026-09-20')->signed_at->toDateString())
        ->toBe('2026-09-20');
});

it('refuses a new total outside 1 to Money::MAX', function (int $total) {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, $total, [])))->toHaveKey('new_total_amount');
})->with(['zero' => 0, 'over the ceiling' => Money::MAX + 1]);

it('attaches the scan of the amendment when it is an internal document of the same matter', function () {
    $scan = Document::factory()->group(DocumentGroup::Internal)->create(['matter_id' => $this->matter->id]);

    expect(amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON, '2026-09-24', $scan)->document_id)
        ->toBe($scan->id);
});

it('refuses a scan that belongs to another matter, or that the client can see', function (string $case) {
    $scan = $case === 'other matter'
        ? Document::factory()->group(DocumentGroup::Internal)->create()
        : Document::factory()->group(DocumentGroup::Issued)->create(['matter_id' => $this->matter->id]);

    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]], AMEND_REASON, '2026-09-24', $scan)))
        ->toBe(['document_id' => [__('billing.validation.document_not_eligible')]]);
})->with(['other matter', 'group B']);

it('refuses an amendment that changes nothing', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 100_000_000, [])))
        ->toBe(['instalment_changes' => [__('billing.validation.amendment_changes_nothing')]]);
});

// --- Từng thay đổi lịch thu ----------------------------------------------------------------------------

it('refuses a change without a known action', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'delete', 'instalment_id' => $this->third->id]])))
        ->toHaveKey('instalment_changes.0.action');
});

it('refuses to touch an instalment of another contract', function () {
    $foreign = Instalment::factory()->create();

    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $foreign->id]])))
        ->toBe(['instalment_changes.0.instalment_id' => [__('billing.validation.instalment_not_in_contract')]]);
});

it('refuses to change the same instalment twice in one amendment', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 75_000_000, [
        ['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 5_000_000],
        ['action' => 'cancel', 'instalment_id' => $this->third->id],
    ])))->toBe(['instalment_changes.1.instalment_id' => [__('billing.validation.instalment_changed_twice')]]);
});

it('refuses to change an instalment that is no longer pending', function (InstalmentStatus $status) {
    $this->third->forceFill(['status' => $status, 'waived_reason' => 'Miễn theo thoả thuận riêng với khách.'])->saveQuietly();

    expect(amendErrors(fn () => amend($this->lead, $this->contract, 80_000_000, [['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 10_000_000]])))
        ->toBe(['instalment_changes.0.instalment_id' => [__('billing.validation.instalment_not_pending', ['name' => 'Thanh toán đợt cuối'])]]);
})->with([InstalmentStatus::Paid, InstalmentStatus::Waived, InstalmentStatus::Cancelled]);

it('refuses to cancel an instalment that already has money on it', function () {
    Payment::factory()->for($this->third)->create(['amount' => 5_000_000]);

    expect(amendErrors(fn () => amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]])))
        ->toBe(['instalment_changes.0.instalment_id' => [__('billing.validation.instalment_has_payments', [
            'name' => 'Thanh toán đợt cuối', 'collected' => Money::format(5_000_000),
        ])]]);
});

it('cancels an instalment whose only payment was voided', function () {
    Payment::factory()->for($this->third)->voided()->create(['amount' => 5_000_000]);

    amend($this->lead, $this->contract, 70_000_000, [['action' => 'cancel', 'instalment_id' => $this->third->id]]);

    expect($this->third->refresh()->status)->toBe(InstalmentStatus::Cancelled);
});

it('refuses to lower an instalment below what was already collected on it', function () {
    Payment::factory()->for($this->third)->create(['amount' => 20_000_000]);

    expect(amendErrors(fn () => amend($this->lead, $this->contract, 89_999_999, [['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 19_999_999]])))
        ->toHaveKey('instalment_changes.0.amount');
});

it('lowers an instalment to exactly what was collected on it', function () {
    Payment::factory()->for($this->third)->create(['amount' => 20_000_000]);
    Payment::factory()->for($this->third)->voided()->create(['amount' => 9_000_000]);

    amend($this->lead, $this->contract, 90_000_000, [['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => 20_000_000]]);

    expect($this->third->refresh()->amount)->toBe(20_000_000);
});

it('checks an added instalment by the same rules as a drafted one', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 110_000_000, [
        ['action' => 'add', 'name' => 'Sai giai đoạn', 'amount' => 10_000_000, 'trigger_type' => 'stage', 'trigger_stage_key' => 'intake'],
    ])))->toHaveKey('instalment_changes.0.trigger_stage_key');
});

it('refuses an updated amount that is not a whole number of dong', function () {
    expect(amendErrors(fn () => amend($this->lead, $this->contract, 100_000_000, [['action' => 'update', 'instalment_id' => $this->third->id, 'amount' => '30.000.000']])))
        ->toHaveKey('instalment_changes.0.amount');
});
