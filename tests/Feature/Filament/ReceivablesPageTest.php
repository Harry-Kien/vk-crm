<?php

use App\Enums\InstalmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Pages\Receivables;
use App\Http\Middleware\AnswerDeniedPanelRequestsWithNotFound;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * Trang "Công nợ" cho kế toán (M9 Task 8). Kế toán không mở được trang vụ việc
 * (`matter.view`) — đây là màn hình DUY NHẤT của họ về tiền.
 *
 * Cổng của cả TRANG là `revenue.viewAny` ({@see Receivables::canAccess()}): admin, quản lý, kế
 * toán vào được; luật sư và trợ lý nhận 404 ({@see AnswerDeniedPanelRequestsWithNotFound}).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->assistant = User::factory()->withRole(Role::Assistant)->create();

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * Hợp đồng ACTIVE với đúng một đợt bằng giá trị hợp đồng (bất biến tổng M9 giữ sạch mà không cần
 * dựng nhiều đợt) — cùng thành ngữ `BillingRelationManagerTest::activeContractOneInstalment()`.
 */
function receivableOn(Matter $matter, int $amount = 10_000_000, array $instalmentOverrides = []): Instalment
{
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount]);

    return Instalment::factory()->for($contract)->create([
        'amount' => $amount,
        'due_date' => today()->subDays(3)->toDateString(),
        ...$instalmentOverrides,
    ]);
}

// =================================================================================================
// Ai mở được trang
// =================================================================================================

it('lets the accountant open the receivables page', function () {
    $this->actingAs($this->accountant, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets the manager open the receivables page', function () {
    $this->actingAs($this->manager, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets the admin open the receivables page', function () {
    $this->actingAs($this->admin, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertOk();
});

it('answers 404 to a lawyer, who has billing.view but not revenue.viewAny', function () {
    $this->actingAs($this->lawyer, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertNotFound();
});

it('answers 404 to an assistant', function () {
    $this->actingAs($this->assistant, 'web')
        ->get(Receivables::getUrl(panel: 'admin'))
        ->assertNotFound();
});

// =================================================================================================
// Ranh giới của kế toán: không tiêu đề vụ việc ở bất kỳ đâu (DTO AccountantBillingRow).
// =================================================================================================

it('never leaks the matter title anywhere on the page, even though the accountant sees the row', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO XXQ123';
    $matter = Matter::factory()->create(['title' => $secretTitle]);
    receivableOn($matter);

    $response = $this->actingAs($this->accountant, 'web')->get(Receivables::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertDontSee($secretTitle);
});

// =================================================================================================
// Tập dòng — Matter::listableBy(), vụ restricted không xuất hiện với kế toán/quản lý.
// =================================================================================================

it('shows the accountant an ordinary matters instalment but hides a restricted one, which only the admin sees', function () {
    $ordinary = receivableOn($this->matter);
    $restricted = receivableOn($this->restricted);

    $this->actingAs($this->accountant, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary])
        ->assertCanNotSeeTableRecords([$restricted]);

    $this->actingAs($this->manager, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary])
        ->assertCanNotSeeTableRecords([$restricted]);

    $this->actingAs($this->admin, 'web');
    $this->livewire(Receivables::class)
        ->assertCanSeeTableRecords([$ordinary, $restricted]);
});

// =================================================================================================
// Ghi khoản thu — kế toán ghi được, quản lý chỉ xem, "1.250.000" -> 1250000.
// =================================================================================================

it('lets the accountant record a payment through the button, parsing "1.250.000" as 1250000 dong', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->assertActionVisible(TestAction::make('recordPayment')->table($instalment))
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '1.250.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
        ])
        ->assertHasNoActionErrors();

    $payment = Payment::query()->where('instalment_id', $instalment->id)->sole();
    expect($payment->amount)->toBe(1_250_000)
        ->and($payment->receipt_document_id)->toBeNull()
        ->and($instalment->fresh()->status)->toBe(InstalmentStatus::Pending);
});

/**
 * "kế toán ghi khoản thu qua Livewire và đợt chuyển trạng thái" (test bắt buộc, brief Task 8):
 * ghi ĐỦ số tiền còn lại của đợt phải đưa `status` từ `pending` sang `paid` — cùng bước 8 của
 * `RecordPayment::handle()`, qua đúng đường Livewire của trang này, không gọi thẳng Action.
 */
it('transitions the instalment to paid once the accountant records enough through the page', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('recordPayment')->table($instalment), data: [
            'amount' => '10.000.000',
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::BankTransfer->value,
        ])
        ->assertHasNoActionErrors();

    expect($instalment->fresh()->status)->toBe(InstalmentStatus::Paid);
});

it('hides both money buttons from the manager, who can only look', function () {
    $instalment = receivableOn($this->matter);

    $this->actingAs($this->manager, 'web');

    $this->livewire(Receivables::class)
        ->assertActionHidden(TestAction::make('recordPayment')->table($instalment))
        ->assertActionHidden(TestAction::make('voidPayment')->table($instalment));
});

it('turns PaymentExceedsInstalment into a notification instead of a 500, and does not record anything', function () {
    $instalment = receivableOn($this->matter, 10_000_000);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)->callAction(TestAction::make('recordPayment')->table($instalment), data: [
        'amount' => '20.000.000',
        'paid_on' => today()->toDateString(),
        'method' => PaymentMethod::Cash->value,
    ]);

    Notification::assertNotified(__('actions.failed_title'));
    expect(Payment::query()->where('instalment_id', $instalment->id)->count())->toBe(0);
});

// =================================================================================================
// Huỷ khoản thu — ĐÚNG một khoản được chọn, không giới hạn khoản gần nhất (phán quyết controller 2).
// =================================================================================================

it('lets the accountant void an older payment while a newer one on the same instalment stays untouched', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    $older = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDays(5)->toDateString()]);
    $newer = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDay()->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'payment_id' => $older->id,
            'reason' => str_repeat('a', 20),
        ])
        ->assertHasNoActionErrors();

    expect($older->fresh()->voided_at)->not->toBeNull()
        ->and($newer->fresh()->voided_at)->toBeNull();
});

it('lets the accountant void the newer payment by id while an older one on the same instalment stays untouched', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    $older = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDays(5)->toDateString()]);
    $newer = Payment::factory()->for($instalment)->create(['amount' => 4_000_000, 'paid_on' => today()->subDay()->toDateString()]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->callAction(TestAction::make('voidPayment')->table($instalment), data: [
            'payment_id' => $newer->id,
            'reason' => str_repeat('a', 20),
        ])
        ->assertHasNoActionErrors();

    expect($newer->fresh()->voided_at)->not->toBeNull()
        ->and($older->fresh()->voided_at)->toBeNull();
});

it('hides the void button once every payment on the instalment has already been voided', function () {
    $instalment = receivableOn($this->matter, 10_000_000);
    Payment::factory()->for($instalment)->voided()->create(['amount' => 4_000_000]);

    $this->actingAs($this->accountant, 'web');

    $this->livewire(Receivables::class)
        ->assertActionHidden(TestAction::make('voidPayment')->table($instalment));
});

// Vụ `restricted` không được ghi bởi kế toán: đã chứng minh ở mức row-scope (bài test "shows the
// accountant an ordinary matter's instalment but hides a restricted one" phía trên) — dòng đó
// không có trong tập dòng của kế toán nên không có gì để bấm, và khung test action của Filament từ
// chối resolve một record ngoài tập đó (cùng cơ chế vừa chứng minh ở test quản lý bên trên). Định
// nghĩa "ai ghi được payment.record trên vụ restricted" (chỉ lead lawyer/admin) đã có bộ test riêng,
// đầy đủ, ở `tests/Feature/Authorization/BillingAccessTest.php` — không lặp lại ở đây.
