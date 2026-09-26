<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DraftContract;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Exceptions\ContractStatusConflict;
use App\Exceptions\ContractTotalMismatch;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    // Ngày ký trong các test dưới đây là 2026-09-20; ghim "hôm nay" để chúng không bao giờ ở tương lai.
    Carbon::setTestNow('2026-09-25 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

/**
 * Bản nháp soạn đúng đường thật (`DraftContract`): tạm ứng khi ký (5 ngày), một đợt theo giai
 * đoạn `filed`, một đợt theo ngày.
 *
 * @param  list<int>  $amounts  số tiền ba đợt
 */
function activationDraft(User $actor, Matter $matter, int $total, array $amounts = [30_000_000, 40_000_000, 30_000_000]): Contract
{
    return app(DraftContract::class)->handle($actor, $matter, ['total_amount' => $total], [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => $amounts[0], 'trigger_type' => 'on_signing', 'due_days_after_trigger' => 5],
        ['name' => 'Thanh toán khi nộp đơn khởi kiện', 'amount' => $amounts[1], 'trigger_type' => 'stage', 'trigger_stage_key' => 'filed'],
        ['name' => 'Thanh toán đợt cuối', 'amount' => $amounts[2], 'trigger_type' => 'due_date', 'due_date' => '2027-03-31'],
    ]);
}

function activate(User $actor, Contract $contract, string $signedAt = '2026-09-20'): Contract
{
    return app(ActivateContract::class)->handle($actor, $contract, $signedAt);
}

it('activates a draft whose schedule matches its total to the dong', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);

    $activated = activate($this->lead, $contract, '2026-09-20');

    expect($activated->status)->toBe(ContractStatus::Active)
        ->and($activated->signed_at->toDateString())->toBe('2026-09-20')
        ->and($activated->activated_by)->toBe($this->lead->id)
        ->and($activated->updated_by)->toBe($this->lead->id);
});

it('refuses to activate a schedule that is one dong short, and names both numbers', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000, [30_000_000, 40_000_000, 29_999_999]);

    expect(fn () => activate($this->lead, $contract))
        ->toThrow(ContractTotalMismatch::class, __('billing.errors.total_mismatch_on_activation', [
            'code' => $contract->code,
            'total' => Money::format(100_000_000),
            'schedule' => Money::format(99_999_999),
            'difference' => Money::format(1),
        ]))
        ->and($contract->refresh()->status)->toBe(ContractStatus::Draft)
        ->and($contract->signed_at)->toBeNull();
});

it('refuses to activate a schedule that is one dong over', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000, [30_000_000, 40_000_001, 30_000_000]);

    expect(fn () => activate($this->lead, $contract))->toThrow(ContractTotalMismatch::class)
        ->and($contract->refresh()->status)->toBe(ContractStatus::Draft);
});

it('fills the due date of every on-signing instalment from the signing date', function () {
    $contract = activate($this->lead, activationDraft($this->lead, $this->matter, 100_000_000), '2026-09-20');

    [$onSigning, $byStage, $byDate] = $contract->instalments()->get()->all();

    expect($onSigning->trigger_type)->toBe(InstalmentTrigger::OnSigning)
        ->and($onSigning->due_date->toDateString())->toBe('2026-09-25')
        ->and($onSigning->triggered_at?->toDateTimeString())->toBe('2026-09-25 10:00:00')
        ->and($onSigning->updated_by)->toBe($this->lead->id)
        ->and($byStage->due_date)->toBeNull()
        ->and($byStage->triggered_at)->toBeNull()
        ->and($byDate->due_date->toDateString())->toBe('2027-03-31')
        ->and($byDate->triggered_at)->toBeNull();
});

it('records the activation in the audit log against the actor passed in', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $contract = activate($this->lead, activationDraft($this->lead, $this->matter, 100_000_000), '2026-09-20');

    $audit = Activity::query()->where('event', 'contract_activated')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_id)->toBe($contract->id)
        ->and($audit->properties['signed_at'])->toBe('2026-09-20')
        ->and($audit->properties['on_signing_released'])->toBe(1);
});

/**
 * Ba người khác nhau: quản lý soạn nháp, quản trị đang giữ phiên, luật sư phụ trách kích hoạt. Nếu
 * người soạn cũng là người kích hoạt thì `updated_by` đã đúng từ lúc soạn và không test nào thấy
 * được một `blameOn($actor)` bị quên ở bước kích hoạt (probe A10/A11 từng sống sót vì thế).
 */
it('blames the contract and its on-signing instalments on the activating actor, not the drafter or the session', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $draft = activationDraft($manager, $this->matter, 100_000_000);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');

    $contract = activate($this->lead, $draft, '2026-09-20');
    [$onSigning, $byStage] = $contract->instalments()->get()->all();

    expect($contract->created_by)->toBe($manager->id)
        ->and($contract->updated_by)->toBe($this->lead->id)
        ->and($onSigning->updated_by)->toBe($this->lead->id)
        ->and($byStage->updated_by)->toBe($manager->id);
});

it('refuses an account that cannot manage the contract', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => activate($outsider, $contract))->toThrow(AuthorizationException::class)
        ->and($contract->refresh()->status)->toBe(ContractStatus::Draft);
});

it('refuses to activate a contract that is no longer a draft', function () {
    $contract = activate($this->lead, activationDraft($this->lead, $this->matter, 100_000_000));

    expect(fn () => activate($this->lead, $contract))
        ->toThrow(ContractStatusConflict::class, ContractStatusConflict::notDraft($contract)->getMessage());
});

it('reads the status from the locked row, not from the object the caller holds', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);
    $stale = Contract::query()->findOrFail($contract->id);
    activate($this->lead, $contract);

    expect(fn () => activate($this->lead, $stale))->toThrow(ContractStatusConflict::class);
});

it('refuses a signing date in the future', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);

    expect(fn () => activate($this->lead, $contract, '2026-09-26'))->toThrow(ValidationException::class, __('billing.validation.date_future'))
        ->and(activate($this->lead, $contract, '2026-09-25')->signed_at->toDateString())->toBe('2026-09-25');
});

it('refuses a signing date that is not a date, as a validation error rather than a crash', function (string $date) {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);

    expect(fn () => activate($this->lead, $contract, $date))->toThrow(ValidationException::class, __('billing.validation.date_invalid'));
})->with(['words' => 'hôm qua', 'empty' => '', 'impossible' => '2026-02-30']);

it('accepts a DateTimeInterface as the signing date', function () {
    $contract = activationDraft($this->lead, $this->matter, 100_000_000);

    expect(app(ActivateContract::class)->handle($this->lead, $contract, new DateTimeImmutable('2026-09-21 16:45'))->signed_at->toDateString())
        ->toBe('2026-09-21');
});

/**
 * M9 Task 6 (`TriggerInstalmentsForStage`) bị hoãn tới khi M6.5 merge (phán quyết controller).
 * `ActivateContract::releaseStageTriggeredInstalments()` là điểm nối tường minh, để trống có chủ
 * đích — không một bản sao nào của logic kích hoạt theo giai đoạn.
 */
it('releases the stage-triggered instalments whose stage the matter has already passed when the contract is activated (M9 Task 6: TriggerInstalmentsForStage via ActivateContract::releaseStageTriggeredInstalments)')
    ->todo();
