<?php

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\MatterRole;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\BillingRelationManager;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab "Hợp đồng và thanh toán" trên trang vụ việc (M9 Task 7).
 *
 * Cổng của cả tab là {@see BillingRelationManager::canViewForRecord()}, hỏi `viewAny` CÓ NGỮ
 * CẢNH vụ việc — khác bản mặc định của `RelationManager` (không ngữ cảnh). Nút ghi khoản thu hỏi
 * `PaymentPolicy::create` với ngữ cảnh VỤ VIỆC (không phải đợt); nút miễn/huỷ hỏi
 * `InstalmentPolicy::waive`/`PaymentPolicy::void` trên đúng bản ghi.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->teammate = User::factory()->withRole(Role::Lawyer)->create();
    $this->outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->matter->addTeamMember($this->teammate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);

    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $this->restricted->addTeamMember($this->teammate, MatterRole::Associate);
});

function billingTab(Matter $matter)
{
    return test()->livewire(BillingRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/**
 * Nút của vòng đời hợp đồng chỉ lọc CẤU TRÚC (có hợp đồng hay không), không lọc theo TRẠNG THÁI —
 * xem chú thích tại `BillingRelationManager::activateContractAction()`. Vì vậy mọi kịch bản
 * "trạng thái sai" dưới đây gọi thẳng `callAction()` bình thường: nút vẫn hiện, và chính Action là
 * nơi từ chối.
 */
function callActionOn($tab, string $actionName, ?Model $record, array $data)
{
    $target = $record === null ? TestAction::make($actionName)->table() : TestAction::make($actionName)->table($record);

    return $tab->callAction($target, data: $data);
}

/**
 * Hợp đồng ĐANG HIỆU LỰC với đúng MỘT đợt bằng giá trị hợp đồng — cùng thành ngữ
 * `BillingAccessTest::billingAccessChain()`: bất biến tổng M9 giữ sạch mà không cần dựng nhiều
 * đợt.
 */
function activeContractOneInstalment(Matter $matter, int $amount = 100_000_000, array $instalmentOverrides = []): array
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => $amount,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->subDays(5)->toDateString(),
        ...$instalmentOverrides,
    ]);

    return [$contract, $instalment];
}

/**
 * Hợp đồng ĐANG HIỆU LỰC với NHIỀU đợt: dựng ở trạng thái `draft` (bất biến tổng M9 không canh
 * bản nháp), rồi chuyển thẳng `active` bằng một lần ghi model — không qua `ActivateContract`, vì
 * đây là dựng DỮ LIỆU CHO TEST, không phải hành vi đang được kiểm.
 */
function activeContractManyInstalments(Matter $matter, int $total, array $instalmentSpecs): Contract
{
    $contract = Contract::factory()->for($matter)->create(['status' => ContractStatus::Draft, 'total_amount' => $total, 'signed_at' => null]);

    foreach (array_values($instalmentSpecs) as $index => $spec) {
        Instalment::factory()->for($contract)->create(['sequence' => $index + 1, ...$spec]);
    }

    $contract->forceFill(['status' => ContractStatus::Active, 'signed_at' => today()->subDay()->toDateString(), 'activated_by' => $matter->lead_lawyer_id])->save();

    return $contract->fresh();
}

// =================================================================================================
// Ai thấy tab
// =================================================================================================

it('shows the tab to the lead lawyer of the matter, and hides it on a matter he is not on', function () {
    $this->actingAs($this->lead, 'web');

    expect(BillingRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();

    $foreign = Matter::factory()->create();

    expect(BillingRelationManager::canViewForRecord($foreign, ViewMatter::class))->toBeFalse();
});

it('hides the tab from an assistant on the team', function () {
    $this->actingAs($this->lead, 'web');
    expect(BillingRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();

    // canViewForRecord không đọc actor đăng nhập trong tham số, nhưng Gate đọc actor hiện hành —
    // đặt actor trước khi hỏi, đúng cách các test canViewForRecord khác trong dự án đọc nó.
    $this->actingAs($this->assistant, 'web');

    expect(BillingRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();
});

it('hides the tab of a restricted matter from a team member who is not its lead', function () {
    $this->actingAs($this->teammate, 'web');

    expect(BillingRelationManager::canViewForRecord($this->restricted, ViewMatter::class))->toBeFalse();

    $this->actingAs($this->lead, 'web');

    expect(BillingRelationManager::canViewForRecord($this->restricted, ViewMatter::class))->toBeTrue();
});

it('hides the tab of a soft deleted matter even from an admin', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter);
    $instalment->fill(['status' => InstalmentStatus::Paid])->save();
    $this->matter->delete();

    $this->actingAs($this->admin, 'web');

    expect(BillingRelationManager::canViewForRecord($this->matter->fresh(), ViewMatter::class))->toBeFalse();
});

it('answers 404 for someone who has no access to the matter at all', function () {
    activeContractOneInstalment($this->matter);

    $this->actingAs($this->outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin'))
        ->assertNotFound();
});

// =================================================================================================
// Nút ghi khoản thu — quản lý xem được, không ghi được; luật sư vụ thường không ghi được; luật sư
// phụ trách vụ restricted ghi được.
// =================================================================================================

it('shows the tab to the manager but hides the record-payment button from them', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter);

    $this->actingAs($this->manager, 'web');

    expect(BillingRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();

    billingTab($this->matter)->assertActionHidden(TestAction::make('recordPayment')->table($instalment));
});

it('hides the record-payment button from the lawyers of an ordinary matter', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter);

    $this->actingAs($this->lead, 'web');
    billingTab($this->matter)->assertActionHidden(TestAction::make('recordPayment')->table($instalment));

    $this->actingAs($this->teammate, 'web');
    billingTab($this->matter)->assertActionHidden(TestAction::make('recordPayment')->table($instalment));
});

it('lets the lead lawyer of a restricted matter record a payment through the button', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->restricted);

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)
        ->assertActionVisible(TestAction::make('recordPayment')->table($instalment))
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '100.000.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
        ])
        ->assertHasNoActionErrors();

    $payment = Payment::query()->where('instalment_id', $instalment->id)->sole();

    expect($payment->amount)->toBe(100_000_000)
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Paid);
});

it('stores "1.250.000" typed in the amount field as the integer 1250000', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 5_000_000);

    $this->actingAs($this->accountant, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '1.250.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::Cash->value,
        ])
        ->assertHasNoActionErrors();

    expect(Payment::query()->where('instalment_id', $instalment->id)->sole()->amount)->toBe(1_250_000);
});

// =================================================================================================
// Dải cảnh báo hồ sơ đã kết thúc còn công nợ
// =================================================================================================

it('shows the closed-with-balance banner only when the matter is closed and a debt remains', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->assertDontSee(__('billing.tab.closed_with_balance_warning'));

    $this->matter->forceFill(['closed_at' => today()->toDateString()])->save();

    billingTab($this->matter->fresh())->assertSee(__('billing.tab.closed_with_balance_warning'));
});

// =================================================================================================
// Mỗi DomainException của Task 4 và Task 5 hiện thành lỗi trên form — một it() riêng mỗi trường hợp.
// =================================================================================================

it('turns ContractStatusConflict::notDraft into a notification when activating a contract that already left draft', function () {
    $contract = Contract::factory()->for($this->matter)->active()->create();
    Instalment::factory()->for($contract)->create(['amount' => $contract->total_amount]);

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->matter), 'activateContract', null, [
        'signed_at' => today()->toDateString(),
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns ContractTotalMismatch::onActivation into a notification when the draft schedule does not match its total', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 1]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->callAction(TestAction::make('activateContract')->table(), data: [
        'signed_at' => today()->toDateString(),
    ]);

    Notification::assertNotified(__('actions.failed_title'));

    expect($contract->fresh()->status)->toBe(ContractStatus::Draft);
});

it('turns ContractStatusConflict::notActive into a notification when completing a contract that is not active', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft]);

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->matter), 'completeContract', null, []);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns ContractStatusConflict::hasUnsettled into a notification when completing with an unsettled instalment', function () {
    $contract = activeContractManyInstalments($this->matter, 100_000_000, [
        ['amount' => 50_000_000, 'status' => InstalmentStatus::Paid],
        ['amount' => 50_000_000, 'status' => InstalmentStatus::Pending],
    ]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->callAction(TestAction::make('completeContract')->table());

    Notification::assertNotified(__('actions.failed_title'));
    expect($contract->fresh()->status)->toBe(ContractStatus::Active);
});

it('turns ContractStatusConflict::notActive into a notification when cancelling a contract that is not active', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft]);

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->matter), 'cancelContract', null, [
        'reason' => 'Khách hàng chấm dứt hợp đồng trước thời hạn theo thoả thuận riêng.',
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns ContractNotAmendable into a notification when amending a contract that is not active', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 100_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 100_000_000]);

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->matter), 'amendContract', null, [
        'new_total_amount' => '120.000.000',
        'signed_at' => today()->toDateString(),
        'reason' => 'Phát sinh công việc ngoài phạm vi ban đầu do vụ việc lên phúc thẩm.',
        'instalment_changes' => [],
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns ContractTotalMismatch::onAmendment into a notification when the new total does not match the new schedule', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 100_000_000);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->callAction(TestAction::make('amendContract')->table(), data: [
        'new_total_amount' => '150.000.000',
        'signed_at' => today()->toDateString(),
        'reason' => 'Phát sinh công việc ngoài phạm vi ban đầu do vụ việc lên phúc thẩm.',
        'instalment_changes' => [
            ['action' => 'update', 'instalment_id' => $instalment->id, 'amount' => '120.000.000'],
        ],
    ]);

    Notification::assertNotified(__('actions.failed_title'));
    expect($contract->fresh()->total_amount)->toBe(100_000_000);
});

it('turns InstalmentNotPayable::toRecordPayment into a form error when the instalment is not pending', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->restricted);
    $instalment->fill(['status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('a', 20), 'waived_by' => $this->lead->id, 'waived_at' => now()])->save();

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->restricted), 'recordPayment', $instalment, [
        'amount' => '10.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns InstalmentNotPayable::contractNotActive into a notification when recording on a cancelled contract', function () {
    $contract = Contract::factory()->for($this->restricted)->create(['status' => ContractStatus::Cancelled, 'ended_at' => today()->toDateString(), 'ended_reason' => str_repeat('b', 20)]);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => $contract->total_amount, 'status' => InstalmentStatus::Pending]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)->callAction(TestAction::make('recordPayment')->table($instalment), data: [
        'amount' => '10.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

/**
 * `PaymentExceedsInstalment` là `DomainException`, không `ValidationException` — nó đi ra qua
 * `ReportsActionFailures` bằng một `Notification`, không gắn vào riêng ô `amount` (xem docblock
 * trait đó: chỉ `ValidationException` mới gắn lỗi vào từng ô).
 */
it('turns PaymentExceedsInstalment into a notification when the amount overshoots what remains', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->restricted, 10_000_000);

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)->callAction(TestAction::make('recordPayment')->table($instalment), data: [
        'amount' => '20.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    Notification::assertNotified(__('actions.failed_title'));
    expect(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(0);
});

it('turns InstalmentNotPayable::toWaive into a notification when waiving an instalment that is not pending', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter);
    $instalment->fill(['status' => InstalmentStatus::Paid])->save();

    $this->actingAs($this->lead, 'web');

    callActionOn(billingTab($this->matter), 'waiveInstalment', $instalment, [
        'reason' => str_repeat('c', 20),
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

it('turns InstalmentNotPayable::contractNotActive into a notification when waiving on a completed contract', function () {
    $contract = activeContractManyInstalments($this->matter, 100_000_000, [
        ['amount' => 50_000_000, 'status' => InstalmentStatus::Waived, 'waived_reason' => str_repeat('d', 20), 'waived_by' => $this->lead->id, 'waived_at' => now()],
        ['amount' => 50_000_000, 'status' => InstalmentStatus::Pending],
    ]);
    $pending = $contract->instalments()->where('status', InstalmentStatus::Pending->value)->sole();
    $contract->forceFill(['status' => ContractStatus::Completed, 'ended_at' => today()->toDateString()])->save();

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->callAction(TestAction::make('waiveInstalment')->table($pending), data: [
        'reason' => str_repeat('e', 20),
    ]);

    Notification::assertNotified(__('actions.failed_title'));
});

/**
 * Đúng một tình huống đua: modal mở lúc khoản thu còn chưa huỷ (`payment_id` chốt lúc đó), một
 * người khác huỷ nó TRONG lúc modal đang mở, rồi người đầu bấm lưu. `callAction()` gộp mount, điền
 * và gọi làm MỘT bước, không có chỗ chen một lần ghi DB vào giữa — nên ở đây gọi tay từng bước
 * (`mountAction` rồi `setActionData` rồi `callMountedAction`, đúng ba bước `callAction()` làm bên
 * trong nó — đối chiếu `vendor/filament/actions/src/Testing/TestsActions.php`) chỉ để CHEN được
 * lần huỷ ngoài luồng vào đúng giữa hai bước đó. Nút vẫn `visible()` suốt (khoản còn chưa huỷ lúc
 * mount), nên đây không phải một lần né `->visible()` — nó là một lần điều khiển THỨ TỰ.
 */
it('turns PaymentAlreadyVoided into a notification when the payment is voided by someone else while the modal is open', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000);
    $payment = Payment::factory()->for($instalment)->create(['amount' => 10_000_000]);

    $this->actingAs($this->admin, 'web');

    $tab = billingTab($this->matter)->mountAction(TestAction::make('voidPayment')->table($instalment));

    $payment->fill(['voided_at' => now(), 'voided_by' => $this->manager->id, 'void_reason' => str_repeat('f', 20)])->save();

    $tab->setActionData(['reason' => str_repeat('g', 20)])->callMountedAction();

    Notification::assertNotified(__('actions.failed_title'));
    expect($payment->fresh()->void_reason)->toBe(str_repeat('f', 20));
});

it('hides the void button when the instalment has no payment at all', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000);

    $this->actingAs($this->admin, 'web');

    billingTab($this->matter)->assertActionHidden(TestAction::make('voidPayment')->table($instalment));
});

/**
 * Đợt còn hiện nút (còn một khoản, dù đã huỷ — xem chú thích `->visible()` của
 * `voidPaymentAction()`), nhưng bấm vào thì không còn gì để chọn: `payment_id` mặc định `null` vì
 * `latestActivePayment()` không tìm thấy khoản nào chưa huỷ.
 */
it('shows the void button but refuses when the only payment is already voided', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000);
    Payment::factory()->for($instalment)->voided()->create(['amount' => 10_000_000]);

    $this->actingAs($this->admin, 'web');

    billingTab($this->matter)
        ->assertActionVisible(TestAction::make('voidPayment')->table($instalment))
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'reason' => str_repeat('z', 20),
        ])
        ->assertHasActionErrors(['reason']);
});

it('voids the most recent unvoided payment of an instalment through the button', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000, ['status' => InstalmentStatus::Pending]);
    $payment = Payment::factory()->for($instalment)->create(['amount' => 10_000_000, 'attributed_lawyer_id' => $this->lead->id]);
    $instalment->fill(['status' => InstalmentStatus::Paid])->save();

    $this->actingAs($this->admin, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'reason' => str_repeat('h', 20),
        ])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->voided_at)->not->toBeNull()
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

// =================================================================================================
// Soạn và sửa hợp đồng qua chính form của tab (đường chính, không phải một race trạng thái).
// =================================================================================================

it('drafts a contract with its schedule through the draft button', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('draftContract')->table(), data: [
            'total_amount' => '50.000.000',
            'vat_rate_percent' => '10',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '50.000.000', 'trigger_type' => 'due_date', 'due_date' => today()->addDays(30)->toDateString()],
            ],
        ])
        ->assertHasNoActionErrors();

    $contract = Contract::query()->where('matter_id', $this->matter->id)->sole();

    expect($contract->total_amount)->toBe(50_000_000)
        ->and($contract->vat_rate_percent)->toBe(10)
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->instalments()->sole()->amount)->toBe(50_000_000);
});

it('updates a draft contract through the update button, replacing its schedule', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000, 'vat_rate_percent' => null]);
    Instalment::factory()->for($contract)->create(['name' => 'Cũ', 'amount' => 50_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('updateDraftContract')->table(), data: [
            'total_amount' => '80.000.000',
            'vat_rate_percent' => '8',
            'instalments' => [
                ['name' => 'Mới', 'amount' => '80.000.000', 'trigger_type' => 'due_date', 'due_date' => today()->addDays(60)->toDateString()],
            ],
        ])
        ->assertHasNoActionErrors();

    $contract->refresh();

    expect($contract->total_amount)->toBe(80_000_000)
        ->and($contract->vat_rate_percent)->toBe(8)
        ->and($contract->instalments()->pluck('name')->all())->toBe(['Mới']);
});
