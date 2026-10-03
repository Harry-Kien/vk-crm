<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
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
use App\Models\MatterChecklistItem;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
// Gợi ý đầu mục "Hợp đồng dịch vụ pháp lý và giấy uỷ quyền" — việc sau gộp M7 (làn fu2)
// =================================================================================================

/**
 * Từ M7 Task 3 danh mục của vụ ĐÃ KẾT THÚC là chỉ đọc: `UploadStaffDocument` lên một đầu mục của nó
 * ném `MatterChecklistReadOnly`. Dòng nhắc "tải bản đã ký lên đúng đầu mục đó" vì vậy chỉ hiện khi vụ
 * còn mở — trên vụ đã kết thúc nó chỉ dẫn tới một lời từ chối. Cặp dương/âm trên cùng dữ liệu.
 *
 * Mutation probe: bỏ điều kiện `$matter->isClosed()` khỏi `BillingRelationManager::checklistNudge()`
 * — ĐỎ (vế vụ đã kết thúc); bỏ cả hàm (luôn `null`) — ĐỎ (vế vụ còn mở).
 */
it('nudges to upload the signed contract onto its checklist item on an open matter, and not once the matter is closed', function () {
    activeContractOneInstalment($this->matter);
    MatterChecklistItem::factory()->for($this->matter)->status(ChecklistItemStatus::Missing)->create([
        'name' => BillingRelationManager::REQUIRED_CHECKLIST_ITEM_NAME,
        'is_required' => true,
    ]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->assertSee(__('billing.tab.checklist_nudge'));

    $this->matter->forceFill(['closed_at' => today()->toDateString()])->save();

    billingTab($this->matter->fresh())->assertDontSee(__('billing.tab.checklist_nudge'));
});

// =================================================================================================
// Lượt rà soát cuối M9, I4 — dòng của hợp đồng KHÔNG active không được nói ngược với con số tổng.
// Tổng ("Còn phải thu"/"Quá hạn") chỉ đọc hợp đồng active; nên mỗi dòng của một hợp đồng đã huỷ /
// đã hoàn tất có "Còn lại" 0 và một badge TRUNG TÍNH mang trạng thái HỢP ĐỒNG, không phải "Quá hạn".
// =================================================================================================

it('shows a neutral contract-status badge and nothing outstanding on the rows of a contract that is no longer active', function (string $contractState, ContractStatus $status) {
    $contract = Contract::factory()->for($this->matter)->{$contractState}()->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'status' => InstalmentStatus::Pending,
        'trigger_type' => InstalmentTrigger::DueDate,
        'due_date' => today()->subDays(5)->toDateString(),
    ]);
    Payment::factory()->for($instalment)->create(['amount' => 4_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->assertTableColumnFormattedStateSet('state', __('billing.tab.contract_state_badge', ['status' => $status->label()]), $instalment)
        ->assertTableColumnFormattedStateNotSet('state', InstalmentState::Overdue->label(), $instalment)
        ->assertTableColumnStateSet('outstanding', Money::format(0), $instalment)
        // Tiền đã thu vẫn là tiền đã thu.
        ->assertTableColumnStateSet('collected', Money::format(4_000_000), $instalment);

    expect(BillingRelationManager::displayStateColor($instalment->fresh()))->toBe('gray');
})->with([
    'cancelled' => ['cancelled', ContractStatus::Cancelled],
    'completed' => ['completed', ContractStatus::Completed],
]);

/** Cặp dương: hợp đồng active vẫn hiện trạng thái của ĐỢT, tô đỏ khi quá hạn, và "Còn lại" thật. */
it('keeps showing the instalment state and the real remainder on the rows of an active contract', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000);
    Payment::factory()->for($instalment)->create(['amount' => 4_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->assertTableColumnFormattedStateSet('state', InstalmentState::Overdue->label(), $instalment)
        ->assertTableColumnStateSet('outstanding', Money::format(6_000_000), $instalment);

    expect(BillingRelationManager::displayStateColor($instalment->fresh()))->toBe('danger');
});

/** I1 trên tab: tổng "Quá hạn" tính PHẦN CÒN LẠI của đợt thu một phần đã quá hạn. */
it('counts the remainder of a partly-paid past-due instalment in the overdue total of the tab', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 10_000_000);
    Payment::factory()->for($instalment)->create(['amount' => 4_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->assertSeeHtml(
        e(__('billing.tab.totals.overdue')).': <span style="color:var(--danger-600)">'.e(Money::format(6_000_000)).'</span>'
    );
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

/**
 * Lượt sửa thứ hai sau rà soát cuối M9, N1: MariaDB báo `1020` (hàng vừa đổi) giữa lúc ghi khoản
 * thu → người dùng đọc câu "thử lại" tiếng Việt trong một thông báo, không phải trang 500. Lỗi giả
 * đúng hình dạng PDO thật, cùng cách `MoneyTransactionConflictTest`.
 */
it('turns a record-changed database error into the Vietnamese retry notification instead of a 500', function () {
    [, $instalment] = activeContractOneInstalment($this->restricted, 10_000_000);

    DB::beforeExecuting(function (string $sql): void {
        if (preg_match('/^insert\W+into\W+payments/i', $sql) === 1) {
            $pdo = new PDOException("SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'payments'");
            $pdo->errorInfo = ['HY000', 1020, "Record has changed since last read in table 'payments'"];

            throw new QueryException('mariadb', $sql, [], $pdo);
        }
    });

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)->callAction(TestAction::make('recordPayment')->table($instalment), data: [
        'amount' => '4.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    // Khớp CẢ thông báo (tiêu đề, thân, màu, không tự tắt) — đúng cái `ReportsActionFailures` dựng.
    Notification::assertNotified(
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body('Có người vừa thay đổi khoản này, anh/chị thử lại.')
            ->danger()
            ->persistent(),
    );
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

// =================================================================================================
// Lượt rà soát cuối M9, I5 — chia theo phần trăm và ba con số VAT hiện NGAY trong form, trước khi
// lưu. Nhập phần trăm thì số tiền của đợt TÍNH từ đó (SplitByPercent, đợt cuối nhận phần dư — ô số
// tiền bị khoá); nhập số tiền thì phần trăm để trống.
// =================================================================================================

it('previews the amount of each instalment split by percent, the last one taking the leftover dong, before saving', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('draftContract')->table())
        ->setActionData([
            'total_amount' => '10.000.001',
            'instalments' => [
                ['name' => 'Tạm ứng', 'percent_basis' => '50', 'trigger_type' => 'on_signing'],
                ['name' => 'Đợt cuối', 'percent_basis' => '50', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertMountedActionModalSee([Money::format(5_000_000), Money::format(5_000_001)]);

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

it('saves the amounts computed from the percents, with the leftover dong on the last instalment, and keeps the percents', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('draftContract')->table(), data: [
            'total_amount' => '10.000.001',
            'instalments' => [
                // Số tiền gõ tay ở đây bị BỎ QUA: phần trăm đã điền thì số tiền tính từ phần trăm.
                ['name' => 'Tạm ứng', 'percent_basis' => '50', 'amount' => '1', 'trigger_type' => 'on_signing'],
                ['name' => 'Đợt cuối', 'percent_basis' => '50', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertHasNoActionErrors();

    $instalments = Contract::query()->where('matter_id', $this->matter->id)->sole()->instalments()->get();

    expect($instalments->pluck('amount')->all())->toBe([5_000_000, 5_000_001])
        ->and($instalments->pluck('percent_basis')->all())->toBe(['50.00', '50.00']);
});

it('leaves the percent empty when the amount is typed directly', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('draftContract')->table(), data: [
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '10.000.000', 'percent_basis' => '', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertHasNoActionErrors();

    $instalment = Contract::query()->where('matter_id', $this->matter->id)->sole()->instalments()->sole();

    expect($instalment->amount)->toBe(10_000_000)
        ->and($instalment->percent_basis)->toBeNull();
});

it('locks the amount field of an instalment whose percent is filled in, and leaves it open otherwise', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('draftContract')->table())
        ->setActionData([
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Theo phần trăm', 'percent_basis' => '40', 'trigger_type' => 'on_signing'],
                ['name' => 'Theo số tiền', 'amount' => '6.000.000', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertFormFieldDisabled('instalments.0.amount')
        ->assertFormFieldEnabled('instalments.1.amount');

    $undoRepeaterFake();
});

/**
 * Lượt sửa thứ hai sau rà soát cuối M9, minor: ô số tiền bị khoá của một dòng theo phần trăm không
 * bao giờ được hiện một con số khác số sẽ lưu. Gõ số tiền rồi mới điền phần trăm thì số đã gõ bị
 * xoá — ô khoá chỉ còn dòng gợi ý "tự tính từ phần trăm", con số thật nằm ở khung xem trước.
 */
it('clears the amount typed on a row as soon as a percent is filled in on that row', function () {
    $undoRepeaterFake = Repeater::fake();
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('draftContract')->table())
        ->setActionData([
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Tạm ứng', 'amount' => '1.000.000', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertActionDataSet(['instalments.0.amount' => '1.000.000'])
        ->setActionData(['instalments' => [['percent_basis' => '40']]])
        ->assertActionDataSet(['instalments.0.amount' => null])
        ->assertFormFieldDisabled('instalments.0.amount')
        ->assertMountedActionModalSee(Money::format(4_000_000));

    $undoRepeaterFake();
});

/**
 * Cùng minor, form "Sửa hợp đồng": dòng đã lưu theo phần trăm mở ra với ô số tiền TRỐNG, không
 * phải số đã lưu — đổi giá trị hợp đồng thì số đã lưu thành số cũ, trong khi khung xem trước và
 * lần lưu dùng số tính lại từ phần trăm của giá trị mới.
 */
it('opens the update-draft form with the locked amount box of a percent row empty, never the stored figure a new total makes stale', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 10_000_000]);
    foreach (['Nửa đầu', 'Nửa sau'] as $index => $name) {
        Instalment::factory()->for($contract)->create([
            'name' => $name, 'sequence' => $index + 1, 'amount' => 5_000_000, 'percent_basis' => '50',
            'trigger_type' => InstalmentTrigger::OnSigning, 'due_date' => null,
        ]);
    }

    $undoRepeaterFake = Repeater::fake();
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('updateDraftContract')->table())
        ->assertActionDataSet(['instalments.0.amount' => null, 'instalments.1.amount' => null])
        ->assertFormFieldDisabled('instalments.0.amount')
        ->setActionData(['total_amount' => '20.000.000'])
        ->assertActionDataSet(['instalments.0.amount' => null, 'instalments.1.amount' => null])
        ->assertMountedActionModalSee(Money::format(10_000_000))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($contract->instalments()->orderBy('sequence')->pluck('amount')->all())->toBe([10_000_000, 10_000_000]);

    $undoRepeaterFake();
});

it('points a malformed percent back at its own field instead of saving', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('draftContract')->table(), data: [
            'total_amount' => '10.000.000',
            'instalments' => [
                ['name' => 'Sai', 'percent_basis' => '12,5', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertHasActionErrors(['instalments.0.percent_basis']);

    expect(Contract::query()->where('matter_id', $this->matter->id)->exists())->toBeFalse();
});

it('previews the three VAT figures of the total before saving', function () {
    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('draftContract')->table())
        ->setActionData([
            'total_amount' => '55.000.000',
            'vat_rate_percent' => '10',
            'instalments' => [
                ['name' => 'Trọn gói', 'amount' => '55.000.000', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertMountedActionModalSee([
            __('billing.tab.preview.vat_line', [
                'total' => Money::format(55_000_000),
                'rate' => 10,
                'tax' => Money::format(5_000_000),
                'net' => Money::format(50_000_000),
            ]),
        ]);
});

/**
 * Phát hiện khi làm I5: form "Sửa hợp đồng" điền sẵn số tiền bằng `Money::format()` ("50.000.000 ₫")
 * — `Money::parse()` cố tình không đọc "₫", nên mở form rồi bấm lưu NGAY mà không sửa gì cũng ra
 * lỗi định dạng. Điền sẵn phải là dạng `Money::parse()` đọc lại được.
 */
it('saves an untouched update-draft form back without a money format error', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000]);
    Instalment::factory()->for($contract)->create(['name' => 'Trọn gói', 'amount' => 50_000_000, 'trigger_type' => InstalmentTrigger::OnSigning, 'due_date' => null]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('updateDraftContract')->table())
        ->assertActionDataSet(['total_amount' => '50.000.000'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($contract->fresh()->total_amount)->toBe(50_000_000)
        ->and($contract->instalments()->sole()->amount)->toBe(50_000_000);
});

it('previews the percent split and the VAT figures in the update-draft form too', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000]);
    Instalment::factory()->for($contract)->create(['name' => 'Cũ', 'amount' => 50_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('updateDraftContract')->table())
        ->setActionData([
            'total_amount' => '33.000.000',
            'vat_rate_percent' => '10',
            'instalments' => [
                ['name' => 'Một phần ba', 'percent_basis' => '33.33', 'trigger_type' => 'on_signing'],
                ['name' => 'Một phần ba', 'percent_basis' => '33.33', 'trigger_type' => 'on_signing'],
                ['name' => 'Phần còn lại', 'percent_basis' => '33.34', 'trigger_type' => 'on_signing'],
            ],
        ])
        // 33.000.000 × 33,33% = 10.998.900 (hai đợt đầu); đợt cuối nhận 33.000.000 − 21.997.800.
        ->assertMountedActionModalSee([Money::format(10_998_900), Money::format(11_002_200), Money::format(3_000_000)]);
});

it('previews the amount of an amendment change typed as a percent of the new total, and the VAT figures of the new total', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 100_000_000);
    $contract->forceFill(['vat_rate_percent' => 8])->save();

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->mountAction(TestAction::make('amendContract')->table())
        ->setActionData([
            'new_total_amount' => '108.000.000',
            'instalment_changes' => [
                ['action' => 'update', 'instalment_id' => $instalment->id, 'percent_basis' => '25'],
                ['action' => 'add', 'name' => 'Phúc thẩm', 'percent_basis' => '75', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertMountedActionModalSee([
            Money::format(27_000_000),
            Money::format(81_000_000),
            __('billing.tab.preview.vat_line', [
                'total' => Money::format(108_000_000),
                'rate' => 8,
                'tax' => Money::format(8_000_000),
                'net' => Money::format(100_000_000),
            ]),
        ]);
});

it('amends with the amounts computed from the percents of the new total', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->matter, 100_000_000);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->callAction(TestAction::make('amendContract')->table(), data: [
            'new_total_amount' => '120.000.000',
            'signed_at' => today()->toDateString(),
            'reason' => 'Phát sinh công việc ngoài phạm vi ban đầu do vụ việc lên phúc thẩm.',
            'instalment_changes' => [
                ['action' => 'update', 'instalment_id' => $instalment->id, 'percent_basis' => '50'],
                ['action' => 'add', 'name' => 'Phúc thẩm', 'percent_basis' => '50', 'trigger_type' => 'on_signing'],
            ],
        ])
        ->assertHasNoActionErrors();

    expect($contract->fresh()->total_amount)->toBe(120_000_000)
        ->and($contract->instalments()->pluck('amount')->all())->toBe([60_000_000, 60_000_000])
        ->and($contract->instalments()->pluck('percent_basis')->all())->toBe(['50.00', '50.00']);
});

// =================================================================================================
// Lượt rà soát cuối M9 — M3 (không ô chọn biên lai), M5 (câu hướng dẫn riêng cho số tiền thu),
// M9 (xoá bản nháp qua DeleteDraftContract).
// =================================================================================================

it('has no receipt picker in the record-payment form, since receipts are not attached in this lane', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->restricted);

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)
        ->mountAction(TestAction::make('recordPayment')->table($instalment))
        ->assertFormFieldDoesNotExist('receipt_document_id');
});

it('explains the payment amount field as the money actually received this time, not as the contract total', function () {
    [$contract, $instalment] = activeContractOneInstalment($this->restricted);

    $this->actingAs($this->lead, 'web');

    billingTab($this->restricted)
        ->mountAction(TestAction::make('recordPayment')->table($instalment))
        ->assertMountedActionModalSee(__('billing.tab.fields.payment_amount_help'))
        ->assertMountedActionModalDontSee(__('billing.tab.fields.total_amount_help'));
});

it('deletes a draft contract with no payment through the delete-draft button', function () {
    $contract = Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000]);
    Instalment::factory()->for($contract)->create(['amount' => 50_000_000]);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)
        ->assertActionVisible(TestAction::make('deleteDraftContract')->table())
        ->callAction(TestAction::make('deleteDraftContract')->table())
        ->assertHasNoActionErrors();

    expect(Contract::query()->whereKey($contract->id)->exists())->toBeFalse();
});

it('does not offer delete-draft on a contract that has been signed', function () {
    activeContractOneInstalment($this->matter);

    $this->actingAs($this->lead, 'web');

    billingTab($this->matter)->assertActionHidden(TestAction::make('deleteDraftContract')->table());
});

it('does not offer delete-draft to the accountant, who cannot manage contracts', function () {
    Contract::factory()->for($this->matter)->create(['status' => ContractStatus::Draft, 'total_amount' => 50_000_000]);

    $this->actingAs($this->accountant, 'web');

    billingTab($this->matter)->assertActionHidden(TestAction::make('deleteDraftContract')->table());
});
