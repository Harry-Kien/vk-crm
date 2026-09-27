<?php

use App\Actions\Billing\UpdateDraftContract;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Exceptions\ContractStatusConflict;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * `UpdateDraftContract` (M9 Task 7, carry-forward #3): sửa giá trị, thuế suất và lịch thu của một
 * hợp đồng còn `draft` — `DraftContract` chỉ tạo, đây là đường DUY NHẤT sửa một bản nháp đã tồn
 * tại. Tab "Hợp đồng và thanh toán" cần Action này để cho phép sửa trước khi kích hoạt.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->civilType = MatterType::factory()->withStages()->create(['code' => 'CIV']);
    $this->matter = Matter::factory()->for($this->civilType, 'matterType')->create(['lead_lawyer_id' => $this->lead->id]);
});

/** @return list<array<string, mixed>> */
function updateSchedule(): array
{
    return [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => 20_000_000, 'trigger_type' => 'on_signing', 'due_days_after_trigger' => 3],
        ['name' => 'Thanh toán đợt cuối', 'amount' => 60_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-05-31'],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>|null  $instalments
 */
function updateDraft(User $actor, Contract $contract, array $attributes = [], ?array $instalments = null): Contract
{
    return app(UpdateDraftContract::class)->handle(
        $actor,
        $contract,
        [...['total_amount' => 80_000_000, 'vat_rate_percent' => 8], ...$attributes],
        $instalments ?? updateSchedule(),
    );
}

/** @return array<string, array<int, string>> */
function updateErrors(callable $update): array
{
    try {
        $update();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function draftContract(Matter $matter, array $overrides = []): Contract
{
    return Contract::factory()->for($matter)->create($overrides);
}

// --- Đường chính -------------------------------------------------------------------------------

it('replaces the total, vat rate and whole schedule of a draft contract', function () {
    $contract = draftContract($this->matter, ['total_amount' => 100_000_000, 'vat_rate_percent' => 10]);
    Instalment::factory()->for($contract)->create(['sequence' => 1, 'name' => 'Cũ', 'amount' => 100_000_000]);

    $updated = updateDraft($this->lead, $contract);

    expect($updated->status)->toBe(ContractStatus::Draft)
        ->and($updated->total_amount)->toBe(80_000_000)
        ->and($updated->vat_rate_percent)->toBe(8);

    $instalments = $updated->instalments()->get();

    expect($instalments->pluck('sequence')->all())->toBe([1, 2])
        ->and($instalments->pluck('name')->all())->toBe(['Tạm ứng khi ký hợp đồng', 'Thanh toán đợt cuối'])
        ->and($instalments->pluck('amount')->all())->toBe([20_000_000, 60_000_000])
        ->and(Instalment::query()->where('name', 'Cũ')->exists())->toBeFalse();
});

it('records the actor passed in as author of the update, and an audit entry', function () {
    $contract = draftContract($this->matter);
    Instalment::factory()->for($contract)->create();

    $sessionUser = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($sessionUser, 'web');

    $updated = updateDraft($this->lead, $contract);

    expect($updated->updated_by)->toBe($this->lead->id)
        ->and($updated->instalments()->pluck('created_by')->unique()->all())->toBe([$this->lead->id]);

    $audit = Activity::query()->where('event', 'contract_draft_updated')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_id)->toBe($contract->id)
        ->and($audit->properties['total_amount'])->toBe(80_000_000)
        ->and($audit->properties['instalment_count'])->toBe(2);
});

it('does not ask the new schedule of a draft to match its total', function () {
    $contract = draftContract($this->matter);

    $updated = updateDraft($this->lead, $contract, ['total_amount' => 100_000_000], [
        ['name' => 'Trọn gói', 'amount' => 1, 'trigger_type' => 'due_date', 'due_date' => '2027-01-15'],
    ]);

    expect($updated->instalments()->sum('amount'))->toEqual(1);
});

it('rolls the whole update back, old schedule included, when one instalment is invalid', function () {
    $contract = draftContract($this->matter);
    Instalment::factory()->for($contract)->create(['name' => 'Đợt gốc', 'sequence' => 1]);

    $errors = updateErrors(fn () => updateDraft($this->lead, $contract, [], [
        updateSchedule()[0],
        [...updateSchedule()[1], 'amount' => 0],
    ]));

    expect($errors)->toHaveKey('instalments.1.amount')
        ->and($contract->instalments()->pluck('name')->all())->toBe(['Đợt gốc']);
});

// --- Chỉ trên bản nháp ---------------------------------------------------------------------------

it('refuses to update a contract that has left draft, with its own message', function (ContractStatus $status) {
    $contract = draftContract($this->matter, ['status' => $status, 'signed_at' => today()->subDay()->toDateString()]);

    expect(fn () => updateDraft($this->lead, $contract))
        ->toThrow(ContractStatusConflict::class, __('billing.errors.contract_not_draft_for_update', [
            'code' => $contract->code,
            'status' => $status->label(),
        ]));
})->with([ContractStatus::Active, ContractStatus::Completed, ContractStatus::Cancelled]);

// --- Quyền ---------------------------------------------------------------------------------------

it('refuses an account that cannot manage the money of this matter', function () {
    $contract = draftContract($this->matter);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => updateDraft($outsider, $contract))->toThrow(AuthorizationException::class)
        ->and($contract->fresh()->total_amount)->not->toBe(80_000_000);
});

it('lets the manager update a draft on an ordinary matter', function () {
    $contract = draftContract($this->matter);
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(updateDraft($manager, $contract)->updated_by)->toBe($manager->id);
});

// --- Giá trị, thuế suất và một dòng lịch thu (đọc qua ValidatesBillingInput, cùng luật DraftContract) --

it('refuses a total that is not a whole number of dong between 1 and Money::MAX', function (mixed $total) {
    $contract = draftContract($this->matter);

    expect(updateErrors(fn () => updateDraft($this->lead, $contract, ['total_amount' => $total])))
        ->toHaveKey('total_amount');
})->with(['zero' => 0, 'negative' => -1, 'a string' => '80.000.000', 'over the ceiling' => Money::MAX + 1]);

it('refuses a vat rate outside 0 to 100', function () {
    $contract = draftContract($this->matter);

    expect(updateErrors(fn () => updateDraft($this->lead, $contract, ['vat_rate_percent' => 101])))
        ->toHaveKey('vat_rate_percent');
});

it('refuses an unknown trigger type on the new schedule', function () {
    $contract = draftContract($this->matter);

    expect(updateErrors(fn () => updateDraft($this->lead, $contract, [], [
        ['name' => 'Đợt lạ', 'amount' => 1, 'trigger_type' => 'when_paid'],
    ])))->toHaveKey('instalments.0.trigger_type');
});

it('accepts on-signing, stage and due-date triggers exactly as DraftContract would', function () {
    $contract = draftContract($this->matter);

    $instalments = updateDraft($this->lead, $contract, [], [
        ['name' => 'Tạm ứng', 'amount' => 20_000_000, 'trigger_type' => 'on_signing', 'due_days_after_trigger' => 5],
        ['name' => 'Nộp đơn', 'amount' => 30_000_000, 'trigger_type' => 'stage', 'trigger_stage_key' => 'filed'],
        ['name' => 'Cuối', 'amount' => 30_000_000, 'trigger_type' => 'due_date', 'due_date' => '2027-06-30'],
    ])->instalments()->get();

    expect($instalments[0]->trigger_type)->toBe(InstalmentTrigger::OnSigning)
        ->and($instalments[1]->trigger_stage_key)->toBe('filed')
        ->and($instalments[2]->due_date->toDateString())->toBe('2027-06-30');
});
