<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb) — tab "Hợp đồng và thanh toán"
|--------------------------------------------------------------------------
| A3: nút huỷ khoản thu chọn ĐÚNG khoản (ô chọn có ngày, số tiền, cách nhận, lúc ghi), mặc định là
|     khoản GHI gần nhất — không phải khoản có ngày tiền về muộn nhất.
| A2: miễn đợt hiện rõ số bị xoá nợ, và có "Bỏ miễn" để sửa một lần miễn nhầm.
| Mọi hành vi đo qua Livewire, không gọi thẳng Action.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function fbBillingTab(Matter $matter)
{
    return test()->livewire(BillingRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** @return array{0: Contract, 1: Instalment} */
function fbActiveContract(Matter $matter, int $amount = 30_000_000): array
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount]);
    $instalment = Instalment::factory()->for($contract)->create([
        'name' => 'Đợt 2',
        'amount' => $amount,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->subDays(5)->toDateString(),
        'status' => InstalmentStatus::Pending,
    ]);

    return [$contract, $instalment];
}

// =================================================================================================
// A3 — huỷ khoản thu: chọn đúng khoản
// =================================================================================================

/**
 * Trên vụ thường chỉ kế toán và admin huỷ được khoản thu (`PaymentPolicy::void`, `payment.record`);
 * trên vụ `restricted` thêm luật sư phụ trách — đúng kịch bản của phát hiện (luật sư phụ trách vụ
 * mật chỉ có tab này để sửa).
 */
it('preselects the payment recorded last, not the one with the latest money date, when voiding', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    [, $instalment] = fbActiveContract($restricted);
    $right = Payment::factory()->for($instalment)->create(['amount' => 5_000_000, 'paid_on' => '2026-10-05']);
    $mistyped = Payment::factory()->for($instalment)->create(['amount' => 3_000_000, 'paid_on' => '2026-09-01']);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($restricted)
        ->mountAction(TestAction::make('voidPayment')->table($instalment))
        ->assertActionDataSet(['payment_id' => $mistyped->id])
        ->assertMountedActionModalSee(__('billing_corrections.void.description'))
        ->setActionData(['reason' => 'Ghi nhầm ngày tiền về, ghi lại bằng dòng đúng.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($mistyped->fresh()->voided_at)->not->toBeNull()
        ->and($right->fresh()->voided_at)->toBeNull();
});

it('voids exactly the payment picked in the void form, and lists each unvoided one with its date, amount, method and recording time', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $older = Payment::factory()->for($instalment)->create(['amount' => 5_000_000, 'paid_on' => '2026-10-05', 'method' => PaymentMethod::Cash, 'reference' => null]);
    $newer = Payment::factory()->for($instalment)->create(['amount' => 3_000_000, 'paid_on' => '2026-10-06', 'reference' => 'FT123']);
    $voided = Payment::factory()->for($instalment)->voided()->create(['amount' => 7_000_000, 'paid_on' => '2026-10-07']);

    $expected = [
        $newer->id => __('billing_corrections.void.option_reference', [
            'date' => '06/10/2026',
            'amount' => Money::format(3_000_000),
            'method' => PaymentMethod::BankTransfer->label(),
            'reference' => 'FT123',
            'recorded_at' => $newer->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ]),
        $older->id => __('billing_corrections.void.option', [
            'date' => '05/10/2026',
            'amount' => Money::format(5_000_000),
            'method' => PaymentMethod::Cash->label(),
            'recorded_at' => $older->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ]),
    ];

    $this->actingAs($this->admin, 'web');

    fbBillingTab($this->matter)
        ->mountAction(TestAction::make('voidPayment')->table($instalment))
        ->assertFormFieldExists('payment_id', fn (Select $field): bool => $field->getOptions() === $expected)
        ->setActionData(['payment_id' => $older->id, 'reason' => 'Khách chuyển nhầm, đã hoàn lại cho khách.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($older->fresh()->voided_at)->not->toBeNull()
        ->and($newer->fresh()->voided_at)->toBeNull()
        ->and($voided->fresh()->void_reason)->not->toBe('Khách chuyển nhầm, đã hoàn lại cho khách.');
});

it('refuses a payment id of another instalment sent through a tampered void form', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $mine = Payment::factory()->for($instalment)->create(['amount' => 5_000_000]);
    [, $other] = fbActiveContract(Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]));
    $foreign = Payment::factory()->for($other)->create(['amount' => 1]);

    $this->actingAs($this->admin, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'payment_id' => $foreign->id,
            'reason' => 'Ghi nhầm khoản thu, đã có dòng đúng thay thế.',
        ])
        ->assertHasActionErrors(['payment_id']);

    expect($foreign->fresh()->voided_at)->toBeNull()
        ->and($mine->fresh()->voided_at)->toBeNull();
});

// =================================================================================================
// A2 — miễn đợt: nói rõ số bị xoá nợ, và bỏ miễn được
// =================================================================================================

it('names the instalment, its amount, what was collected and what will be written off in the waive modal', function () {
    [, $instalment] = fbActiveContract($this->matter);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->mountAction(TestAction::make('waiveInstalment')->table($instalment))
        ->assertMountedActionModalSee(__('billing_corrections.waive.description', [
            'name' => 'Đợt 2',
            'amount' => Money::format(30_000_000),
            'collected' => Money::format(10_000_000),
            'written_off' => Money::format(20_000_000),
        ]));
});

it('records what was collected and what was written off on the waiver audit row', function () {
    [, $instalment] = fbActiveContract($this->matter);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('waiveInstalment')->table($instalment), data: [
            'reason' => 'Miễn phần còn lại theo thoả thuận với khách.',
        ])
        ->assertHasNoActionErrors();

    $audit = Activity::query()->where('event', 'instalment_waived')->sole();

    expect($audit->properties['amount'])->toBe(30_000_000)
        ->and($audit->properties['collected'])->toBe(10_000_000)
        ->and($audit->properties['written_off'])->toBe(20_000_000);
});

it('puts a waived instalment back to pending through the unwaive button, with a reason and an audit row', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $instalment->forceFill([
        'status' => InstalmentStatus::Waived,
        'waived_reason' => 'Bấm nhầm nút miễn trên đợt hai.',
        'waived_by' => $this->lead->id,
        'waived_at' => now(),
    ])->save();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->assertActionVisible(TestAction::make('unwaiveInstalment')->table($instalment))
        ->callAction(TestAction::make('unwaiveInstalment')->table($instalment), data: [
            'reason' => 'Miễn nhầm đợt hai, khách vẫn phải trả đủ.',
        ])
        ->assertHasNoActionErrors();

    $fresh = $instalment->fresh();

    expect($fresh->status)->toBe(InstalmentStatus::Pending)
        ->and($fresh->waived_reason)->toBeNull()
        ->and($fresh->waived_by)->toBeNull()
        ->and($fresh->waived_at)->toBeNull()
        ->and($fresh->amount)->toBe(30_000_000);

    $audit = Activity::query()->where('event', 'instalment_unwaived')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->subject_id)->toBe($instalment->id)
        ->and($audit->properties['reason'])->toBe('Miễn nhầm đợt hai, khách vẫn phải trả đủ.')
        ->and($audit->properties['previous_waived_reason'])->toBe('Bấm nhầm nút miễn trên đợt hai.')
        ->and($audit->properties['amount'])->toBe(30_000_000);
});

it('shows the unwaive button only on a waived instalment', function () {
    [, $instalment] = fbActiveContract($this->matter);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->assertActionHidden(TestAction::make('unwaiveInstalment')->table($instalment));
});

it('refuses to unwaive with a reason shorter than 20 characters', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $instalment->forceFill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_by' => $this->lead->id, 'waived_at' => now()])->save();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('unwaiveInstalment')->table($instalment), data: ['reason' => 'ngắn quá'])
        ->assertHasActionErrors(['reason']);

    expect($instalment->fresh()->status)->toBe(InstalmentStatus::Waived);
});

it('refuses to unwaive an instalment of a contract that is no longer active, in Vietnamese', function () {
    [$contract, $instalment] = fbActiveContract($this->matter);
    $instalment->forceFill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_by' => $this->lead->id, 'waived_at' => now()])->save();
    $contract->forceFill(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()])->save();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('unwaiveInstalment')->table($instalment), data: ['reason' => 'Miễn nhầm đợt hai, khách vẫn phải trả đủ.']);

    Notification::assertNotified(__('actions.failed_title'));
    expect($instalment->fresh()->status)->toBe(InstalmentStatus::Waived);
});

// =================================================================================================
// A1 — hợp đồng đã huỷ không khoá vĩnh viễn vụ việc
// =================================================================================================

function fbCancel(Contract $contract): Contract
{
    $contract->forceFill(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('a', 20)])->save();

    return $contract;
}

it('offers "draft a new contract" once the contract is cancelled, drafts it, and shows only the new schedule with the old code as history', function () {
    [$old, $oldInstalment] = fbActiveContract($this->matter);
    fbCancel($old);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->assertActionVisible(TestAction::make('draftContract')->table())
        ->assertActionHasLabel(TestAction::make('draftContract')->table(), __('billing_corrections.redraft.label'))
        ->assertSee(__('billing_corrections.redraft.cancelled_hint'))
        ->callAction(TestAction::make('draftContract')->table(), data: [
            'total_amount' => '40.000.000',
            'instalments' => [
                ['name' => 'Ký lại trọn gói', 'amount' => '40.000.000', 'trigger_type' => 'due_date', 'due_date' => today()->addDays(30)->toDateString()],
            ],
        ])
        ->assertHasNoActionErrors();

    $new = Contract::query()->where('matter_id', $this->matter->id)->where('status', ContractStatus::Draft->value)->sole();

    $this->matter->refresh();

    fbBillingTab($this->matter)
        ->assertCanSeeTableRecords($new->instalments)
        ->assertCanNotSeeTableRecords([$oldInstalment])
        ->assertSee(__('billing_corrections.redraft.previous_line', [
            'code' => $old->code,
            'status' => ContractStatus::Cancelled->label(),
            'date' => today()->format('d/m/Y'),
            'collected' => Money::format(0),
        ]))
        ->assertActionHidden(TestAction::make('draftContract')->table());

    expect($old->fresh()->status)->toBe(ContractStatus::Cancelled);
});

it('keeps the draft button hidden while the matter has an active contract', function () {
    fbActiveContract($this->matter);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)->assertActionHidden(TestAction::make('draftContract')->table());
});

it('warns about the consequences in the cancel modal, with the amount that will no longer be tracked', function () {
    [, $instalment] = fbActiveContract($this->matter);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->mountAction(TestAction::make('cancelContract')->table())
        ->assertMountedActionModalSee(__('billing_corrections.cancel.description', [
            'outstanding' => Money::format(20_000_000),
        ]));
});

it('records the reason and the amount left untracked on the contract cancellation audit row', function () {
    [$contract, $instalment] = fbActiveContract($this->matter);
    Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('cancelContract')->table(), data: [
            'reason' => 'Hai bên thanh lý, sẽ ký lại với điều khoản mới.',
        ])
        ->assertHasNoActionErrors();

    $audit = Activity::query()->where('event', 'contract_cancelled')->sole();

    expect($contract->fresh()->status)->toBe(ContractStatus::Cancelled)
        ->and($audit->properties['reason'])->toBe('Hai bên thanh lý, sẽ ký lại với điều khoản mới.')
        ->and($audit->properties['outstanding_untracked'])->toBe(20_000_000);
});

// =================================================================================================
// Mục B — dời hạn đợt (khách xin khất)
// =================================================================================================

it('moves the due date of a pending instalment through the reschedule button, with a reason and an audit row', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $previous = $instalment->due_date->toDateString();
    $newDate = today()->addDays(20)->toDateString();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->assertActionVisible(TestAction::make('rescheduleInstalment')->table($instalment))
        ->callAction(TestAction::make('rescheduleInstalment')->table($instalment), data: [
            'due_date' => $newDate,
            'reason' => 'Khách xin khất 20 ngày, luật sư phụ trách đồng ý.',
        ])
        ->assertHasNoActionErrors();

    $fresh = $instalment->fresh();

    expect($fresh->due_date->toDateString())->toBe($newDate)
        ->and($fresh->status)->toBe(InstalmentStatus::Pending)
        ->and($fresh->amount)->toBe(30_000_000);

    $audit = Activity::query()->where('event', 'instalment_rescheduled')->sole();

    expect($audit->causer_id)->toBe($this->lead->id)
        ->and($audit->properties['from'])->toBe($previous)
        ->and($audit->properties['to'])->toBe($newDate)
        ->and($audit->properties['reason'])->toBe('Khách xin khất 20 ngày, luật sư phụ trách đồng ý.');
});

it('refuses to reschedule to the same date or without a 20-character reason', function () {
    [, $instalment] = fbActiveContract($this->matter);
    $previous = $instalment->due_date->toDateString();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('rescheduleInstalment')->table($instalment), data: [
            'due_date' => $previous,
            'reason' => 'Khách xin khất 20 ngày, luật sư phụ trách đồng ý.',
        ])
        ->assertHasActionErrors(['due_date']);

    fbBillingTab($this->matter)
        ->callAction(TestAction::make('rescheduleInstalment')->table($instalment), data: [
            'due_date' => today()->addDays(9)->toDateString(),
            'reason' => 'khất',
        ])
        ->assertHasActionErrors(['reason']);

    expect($instalment->fresh()->due_date->toDateString())->toBe($previous);
});

it('offers the reschedule button only on a pending instalment that already has a due date', function () {
    [$contract, $instalment] = fbActiveContract($this->matter);
    $instalment->forceFill(['status' => InstalmentStatus::Paid])->save();

    $this->actingAs($this->lead, 'web');

    fbBillingTab($this->matter)
        ->assertActionHidden(TestAction::make('rescheduleInstalment')->table($instalment));
});
